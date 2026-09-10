import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, writeFile, readFile, symlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { callTool } from '../lib/tools.mjs';
import { RestClient } from '../lib/rest-client.mjs';

test('添付ファイルの送受信・フォルダ境界・上書き防止', async () => {
  const root = await mkdtemp(path.join(tmpdir(),'pkwk-files-'));
  const other = await mkdtemp(path.join(tmpdir(),'pkwk-other-'));
  process.env.PUKIWIKI_FILES_DIR = root;
  await writeFile(path.join(root,'send.txt'),'test');
  await writeFile(path.join(other,'secret.txt'),'private');
  await symlink(path.join(other,'secret.txt'),path.join(root,'link.txt'));
  const data = {page:'日記/記事',name:'saved.txt',age:0,size:4,mime_type:'text/plain',content_base64:Buffer.from('test').toString('base64'),sha256:createHash('sha256').update('test').digest('hex')};
  const calls=[];
  const client={request:async (...args)=>{calls.push(args);return data;}};
  let r=await callTool(client,'wiki_upload_attachment',{page:'日記/記事',file:'send.txt'});
  assert.equal(r.isError,undefined);
  assert.equal(calls.at(-1)[2].body.content_base64,data.content_base64);
  const n=calls.length;
  r=await callTool(client,'wiki_upload_attachment',{page:'日記/記事',file:path.join(other,'secret.txt')});
  assert.equal(r.isError,true);assert.equal(calls.length,n);
  r=await callTool(client,'wiki_upload_attachment',{page:'日記/記事',file:'link.txt'});
  assert.equal(r.isError,true);assert.equal(calls.length,n);
  r=await callTool(client,'wiki_read_attachment',{page:'日記/記事',name:'saved.txt'});
  assert.equal(r.content[1].resource.text,'test');
  r=await callTool(client,'wiki_download_attachment',{page:'日記/記事',name:'saved.txt'});
  assert.equal(r.isError,undefined);assert.equal(await readFile(path.join(root,'saved.txt'),'utf8'),'test');
  r=await callTool(client,'wiki_download_attachment',{page:'日記/記事',name:'saved.txt'});
  assert.equal(r.isError,true);
  r=await callTool({request:async()=>({...data,name:'../outside.txt'})},'wiki_download_attachment',{page:'日記/記事',name:'saved.txt'});
  assert.equal(r.isError,true);
  r=await callTool({request:async()=>({...data,sha256:'bad'})},'wiki_read_attachment',{page:'日記/記事',name:'saved.txt'});
  assert.equal(r.isError,true);
  delete process.env.PUKIWIKI_FILES_DIR;
});

test('index.php形式はクエリ経由でrewriteなし・階層名と検索方式を維持', async () => {
  const old=globalThis.fetch;
  const calls=[];
  globalThis.fetch=async (url,options)=>{calls.push([new URL(url),options]);return {ok:true,status:200,text:async()=>'{"ok":true}'};};
  try {
    const c=new RestClient({baseUrl:'https://example.invalid/rest-api-v2/api/v1/index.php',apiKey:'fixture'});
    await c.readPage('日記/記事');
    assert.equal(calls[0][0].searchParams.get('route'),'/pages/日記/記事');
    await c.search('肺癌 ALK',10,'OR');
    assert.equal(calls[1][0].searchParams.get('route'),'/search');
    assert.equal(calls[1][0].searchParams.get('mode'),'OR');
    assert.equal(calls[1][1].redirect,'error');
    assert.equal(calls[1][0].searchParams.has('authorization'),false);
  } finally {globalThis.fetch=old;}
});

test('notimestampを明示したときだけ保存先へtrueを渡す', async () => {
  const calls=[];
  const client={writePage:async (...args)=>{calls.push(args);return {page:'A',new_sha1:'x',size:1,changed:true,is_new:false};}};
  const args={page:'A',base_sha1:'0'.repeat(40),content:'改訂'};
  await callTool(client,'wiki_write_page',args);
  await callTool(client,'wiki_write_page',{...args,notimestamp:true});
  assert.equal(calls[0][3],false);assert.equal(calls[1][3],true);
  const r=await callTool(client,'wiki_write_page',{...args,notimestamp:'true'});
  assert.equal(r.isError,true);assert.equal(calls.length,2);
});
