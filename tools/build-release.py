#!/usr/bin/env python3
"""開発者用。本番ZIPとMCPBを許可リストから生成し、秘密設定を含めない。"""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import hashlib, json, re
root=Path(__file__).resolve().parents[1]
version=json.loads((root/'pukiwiki-mcp/package.json').read_text())['version']
out=root/'dist';out.mkdir(exist_ok=True)
manifest=json.loads((root/'pukiwiki-mcp/manifest.json').read_text())
assert manifest['version']==version
assert re.search(r"SERVER_VERSION = '([^']+)'", (root/'pukiwiki-mcp/server.mjs').read_text())[1] == version
assert re.search(r"'version' => '([^']+)'", (root/'rest-api-v2/lib/ExtraRoutes.php').read_text())[1] == version
bridge_files=[root/'pukiwiki-mcp'/name for name in ['manifest.json','package.json','server.mjs','README.md','LICENSE']]
bridge_files+=sorted((root/'pukiwiki-mcp/lib').glob('*.mjs'))
bundle=out/'pukiwiki-mcp.mcpb'
with ZipFile(bundle,'w',ZIP_DEFLATED) as z:
 for f in bridge_files:z.write(f,str(f.relative_to(root/'pukiwiki-mcp')))
archive=out/f'PukiWiki-REST-API-{version}.zip'
with ZipFile(archive,'w',ZIP_DEFLATED) as z:
 api=root/'rest-api-v2'
 for name in ['bootstrap.php','.htaccess','.user.ini','config.local.php.example']:
  z.write(api/name,'rest-api-v2/'+name)
 for folder in ['lib','api','setup','bin','mcp']:
  for f in sorted((api/folder).rglob('*')):
   if f.is_file() and (f.suffix=='.php' or f.name=='.htaccess'):
    z.write(f,'rest-api-v2/'+str(f.relative_to(api)))
 # Claude CodeでMCPBを手動展開せず使えるNode版と、リンク先の説明書を同梱する。
 for f in bridge_files:z.write(f,str(f.relative_to(root)))
 for name in ['README.md','docs/advanced-guide.md','docs/upgrade-2.2.md','docs/release-2.2.1.md','rest-api-v2/docs/setup.md','rest-api-v2/docs/api-reference.md']:
  z.write(root/name,name)
 z.write(root/'docs/はじめに.html','はじめに.html')
 z.write(root/'LICENSE','LICENSE')
 z.write(bundle,'pukiwiki-mcp.mcpb')
with ZipFile(archive) as z:
 assert all(not any(x in name for x in ['run.sh','config.local.php/','/test/','/data/','.DS_Store']) for name in z.namelist())
 assert 'rest-api-v2/config.local.php' not in z.namelist()
(out/'SHA256SUMS.txt').write_text(''.join(hashlib.sha256(f.read_bytes()).hexdigest()+'  '+f.name+'\n' for f in [archive,bundle]))
print(archive);print(bundle)
