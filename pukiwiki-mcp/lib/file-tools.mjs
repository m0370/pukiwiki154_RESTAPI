import { realpath, open } from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';

const MAX_BYTES = 5 * 1024 * 1024;
const text = (value) => ({ text: JSON.stringify(value, null, 2) });
function str(args, key) {
  if (typeof args[key] !== 'string' || !args[key]) throw new Error(`${key} を指定してください。`);
  return args[key];
}
function age(args) {
  const n = args.age ?? 0;
  if (!Number.isSafeInteger(n) || n < 0) throw new Error('age は0以上の整数を指定してください。');
  return n;
}
async function localRoot() {
  const dir = process.env.PUKIWIKI_FILES_DIR;
  if (!dir) throw new Error('Claude Desktopの拡張設定で「添付用フォルダ」を選択してください。');
  return realpath(dir);
}
function filename(name) {
  if (/[\x00-\x1f\x7f/\\:]/u.test(name) || name === '.' || name === '..') throw new Error('無効なファイル名です。');
  return name;
}
async function read(client, args) {
  const value = await client.request('GET', '/attachment', { query: { page: str(args,'page'), name: str(args,'name'), age: age(args) } });
  const bytes = Buffer.from(value.content_base64, 'base64');
  if (bytes.length > MAX_BYTES || createHash('sha256').update(bytes).digest('hex') !== value.sha256) throw new Error('添付データのサイズまたはハッシュが一致しません。');
  return { value, bytes };
}
export const fileHandlers = {
  wiki_get_capabilities: async (client) => text(await client.request('GET','/capabilities')),
  wiki_standard_backups: async (client,args) => text(await client.request('GET','/backups',{query:{page:str(args,'page')}})),
  wiki_read_standard_backup: async (client,args) => text(await client.request('GET','/backup',{query:{page:str(args,'page'),age:age(args)}})),
  wiki_list_attachments: async (client,args) => text(await client.request('GET','/attachments',{query:{page:str(args,'page')}})),
  wiki_read_attachment: async (client,args) => {
    const { value, bytes } = await read(client,args);
    const metadata = { ...value }; delete metadata.content_base64;
    const uri = `pukiwiki-attachment:///${encodeURIComponent(value.page)}/${encodeURIComponent(value.name)}?age=${value.age}`;
    const content = [{type:'text',text:JSON.stringify(metadata,null,2)}];
    if (['image/png','image/jpeg','image/gif','image/webp'].includes(value.mime_type)) {
      content.push({type:'image',mimeType:value.mime_type,data:value.content_base64});
    } else if (value.mime_type?.startsWith('text/')) {
      content.push({type:'resource',resource:{uri,mimeType:value.mime_type,text:bytes.toString('utf8')}});
    } else {
      content.push({type:'resource',resource:{uri,mimeType:value.mime_type || 'application/octet-stream',blob:value.content_base64}});
    }
    return { content };
  },
  wiki_download_attachment: async (client,args) => {
    const root = await localRoot();
    const { value, bytes } = await read(client,args);
    const target = path.join(root,filename(value.name));
    // 'wx' により既存ファイル・シンボリックリンクを上書きしない。
    const file = await open(target,'wx',0o600);
    try { await file.writeFile(bytes); } finally { await file.close(); }
    return text({saved_to:target,sha256:value.sha256});
  },
  wiki_upload_attachment: async (client,args) => {
    const root = await localRoot();
    const relative = str(args,'file');
    const target = await realpath(path.resolve(root,relative));
    if (target === root || !target.startsWith(root + path.sep)) throw new Error('添付用フォルダの外にあるファイルは送信できません。');
    const file = await open(target,'r');
    let bytes;
    try {
      const stat = await file.stat();
      if (!stat.isFile() || stat.size > MAX_BYTES || stat.size === 0) throw new Error('1バイトから5 MiBのファイルを指定してください。');
      const buffer = Buffer.alloc(MAX_BYTES+1);
      let length=0;
      while(length<buffer.length) {
        const result=await file.read(buffer,length,buffer.length-length,null);
        if (!result.bytesRead) break;
        length+=result.bytesRead;
      }
      if(length>MAX_BYTES) throw new Error('ファイルが5 MiBを超えています。');
      bytes=buffer.subarray(0,length);
    } finally { await file.close(); }
    return text(await client.request('POST','/attachments',{body:{page:str(args,'page'),name:filename(args.name || path.basename(target)),content_base64:bytes.toString('base64')}}));
  },
  wiki_delete_attachment: async (client,args) => text(await client.request('POST','/attachment/delete', {body:{page:str(args,'page'),name:str(args,'name'),sha256:str(args,'sha256')}})),
};
const string = { type:'string' };
const page = { page: {type:'string',description:'PukiWikiページ名'} };
const named = { ...page, name: {type:'string',description:'添付ファイル名'}, age:{type:'integer',minimum:0,default:0,description:'0は現在の添付。正の数は削除した添付の履歴番号。'} };
function tool(name, description, properties, required, write=false) {
  return {name,description,inputSchema:{type:'object',properties,required},annotations:{readOnlyHint:!write,destructiveHint:write}};
}
export function fileDefinitions() {
  return [
    tool('wiki_get_capabilities','Wikiとの接続・API権限・対応機能を確認します。',{},[]),
    tool('wiki_standard_backups','PukiWiki標準バックアップの一覧。API独自スナップショットとは別です。',page,['page']),
    tool('wiki_read_standard_backup','標準バックアップの本文を読みます。復元は現在のSHA1を取得してwiki_write_pageで行います。',{...page,age:{type:'integer',minimum:1}},['page','age']),
    tool('wiki_list_attachments','添付ファイルと削除済み添付の履歴・SHA256を一覧表示します。',page,['page']),
    tool('wiki_read_attachment','添付を読みます。画像は画像、その他は埋め込みリソースとして返します。最大5 MiB。',named,['page','name']),
    tool('wiki_download_attachment','添付を設定済みの添付用フォルダへ保存します。同名ファイルは上書きしません。',named,['page','name'],true),
    tool('wiki_upload_attachment','設定済みの添付用フォルダにあるファイルをアップロードします。fileはフォルダからの相対パス。既存の添付は上書きしません。',{...page,file:string,name:string},['page','file'],true),
    tool('wiki_delete_attachment','添付を削除して標準の添付履歴に残します。ユーザーから削除の指示がある場合だけ実行。事前に一覧で得た現在のSHA256が必須です。',{...page,name:string,sha256:string},['page','name','sha256'],true),
  ];
}
