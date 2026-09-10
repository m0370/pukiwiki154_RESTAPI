"""使い捨て PukiWiki コピーで初期設定・API・CSRF・再設定防止を検証する。"""
import argparse, pathlib, tempfile, shutil, subprocess, socket, time, urllib.request, urllib.parse, urllib.error, http.cookiejar, re, json, hashlib, base64, os, zipfile
p=argparse.ArgumentParser();p.add_argument('pukiwiki_source');p.add_argument('--port',type=int,default=0);args=p.parse_args()
source=pathlib.Path(args.pukiwiki_source).resolve();assert (source/'pukiwiki.ini.php').is_file()
root=pathlib.Path(tempfile.mkdtemp(prefix='pkwk-browser-'));public=root/'public';shutil.copytree(source,public,ignore=shutil.ignore_patterns('.git','rest-api-v2','rest-api-v2-testdata'))
api=pathlib.Path(__file__).resolve().parents[1];shutil.copytree(api,public/'rest-api-v2',ignore=shutil.ignore_patterns('data','config.local.php'))
config=public/'pukiwiki.ini.php';s=config.read_text();s=s.replace("$adminpass = '{x-php-md5}!';", "$adminpass = '{x-php-sha256}"+hashlib.sha256(b'fixture-admin-pass').hexdigest()+"';")
# 設定ファイル末尾には PHP 終了タグがない。
s+='\n$auth_users = array("editor" => "fixture-user");\n$edit_auth = 1;\n$edit_auth_pages = array("##" => "editor");\n'
config.write_text(s)
port=args.port
if not port:
 with socket.socket() as sock: sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
log=open(root/'server.log','w');proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(public)],stdout=log,stderr=log)
base=f'http://127.0.0.1:{port}';url=base+'/rest-api-v2/setup/index.php';cookies=http.cookiejar.CookieJar();browser=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
def get(url): return browser.open(url).read().decode()
def post(data): return browser.open(url,urllib.parse.urlencode(data).encode()).read().decode()
def csrf(html):
 m=re.search('name="csrf" value="([a-f0-9]+)"',html);assert m,html[:700];return m[1]
def endpoint(route,method='GET',body=None,token=None,**query):
 req=urllib.request.Request(base+'/rest-api-v2/api/v1/index.php?'+urllib.parse.urlencode({'route':route,**query}),method=method)
 if token:req.add_header('Authorization','Bearer '+token)
 if body is not None:req.data=json.dumps(body,ensure_ascii=False).encode();req.add_header('Content-Type','application/json')
 try:
  with browser.open(req) as resp:return resp.status,json.loads(resp.read())
 except urllib.error.HTTPError as e:return e.code,json.loads(e.read())
try:
 for _ in range(40):
  try:html=get(url);break
  except urllib.error.URLError:time.sleep(.1)
 else:raise RuntimeError('PHP server did not start')
 assert '管理者パスワード' in html
 html=post({'action':'initialize','csrf':csrf(html),'data_dir':str(root/'private')});assert not (public/'rest-api-v2/config.local.php').exists()
 html=post({'action':'login','csrf':csrf(html),'password':'fixture-admin-pass'});assert '非公開の保存先を設定' in html,html[:1500]
 html=post({'action':'initialize','csrf':'invalid','data_dir':str(root/'private')});assert not (public/'rest-api-v2/config.local.php').exists()
 html=post({'action':'initialize','csrf':csrf(html),'data_dir':str(public/'exposed')});assert 'Web公開領域内' in html
 html=post({'action':'initialize','csrf':csrf(html),'data_dir':str(root/'private')});assert '接続キーを発行' in html,html[:1500]
 html=post({'action':'initialize','csrf':csrf(html),'data_dir':str(root/'other')});assert not (root/'other').exists()
 html=post({'action':'create','csrf':csrf(html),'label':'desktop','scope':'write','wiki_user':'editor','attachments':'on'})
 match=re.search(r'pkw2_[a-f0-9]{48}',html);assert match,html[:2000];key=match[0]
 assert key not in get(url), 'key should be shown once'
 assert key not in (root/'private/keys.php').read_text()
 assert endpoint('/capabilities')[0]==401
 assert endpoint('/capabilities',token=key)[1]['attachments_write'] is True
 page='テスト/日本語 %20'
 status,result=endpoint('/pages/'+page,'PUT',{'content':'肺癌とALKについて\n','base_sha1':hashlib.sha1(b'').hexdigest()},key);assert status==201,(status,result)
 assert endpoint('/pages/'+page,token=key)[1]['page']==page
 page_file=public/'wiki'/(page.encode().hex().upper()+'.txt');os.utime(page_file,(1500000000,1500000000))
 current=endpoint('/pages/'+page,token=key)[1]
 status,changed=endpoint('/pages/'+page,'PUT',{'content':'肺癌とALKについて改訂\n','base_sha1':current['sha1'],'notimestamp':True},key)
 assert status==200 and changed['mtime']==1500000000,(status,changed)
 assert endpoint('/pages/'+page,'PUT',{'content':'不正型','base_sha1':changed['new_sha1'],'notimestamp':'true'},key)[0]==400

 assert len(endpoint('/search',token=key,q='肺癌 ALK',mode='AND')[1]['results'])==1
 assert endpoint('/search',token=key,q='肺癌 ALK')[1]['results']==[]
 status,r=endpoint('/attachments','POST',{'page':page,'name':'資料.txt','content_base64':base64.b64encode(b'hello').decode()},key);assert status==201,(status,r)
 digest=r['sha256'];status,r=endpoint('/attachment',token=key,page=page,name='資料.txt');assert status==200
 assert base64.b64decode(r['content_base64'])==b'hello'
 assert endpoint('/attachment/delete','POST',{'page':page,'name':'資料.txt','sha256':'0'*64},key)[0]==409
 status,r=endpoint('/attachment/delete','POST',{'page':page,'name':'資料.txt','sha256':digest},key);assert status==200,(status,r)
 assert endpoint('/attachment',token=key,page=page,name='資料.txt',age=1)[0]==200
 status,backups=endpoint('/backups',token=key,page=page);assert status==200 and backups['backups'],(status,backups)
 first_age=backups['backups'][0]['age']
 status,backup=endpoint('/backup',token=key,page=page,age=first_age);assert status==200 and backup['content'],(status,backup)
 # 配布物から展開したMCPを起動し、実HTTP経路まで確認する。
 bundle=pathlib.Path(__file__).resolve().parents[2]/'dist/pukiwiki-mcp.mcpb'
 if bundle.exists():
  client_dir=root/'desktop-extension';client_dir.mkdir()
  with zipfile.ZipFile(bundle) as z:z.extractall(client_dir)
  env=dict(os.environ,PUKIWIKI_API_URL=base+'/rest-api-v2/api/v1/index.php',PUKIWIKI_API_KEY=key,PUKIWIKI_FILES_DIR=str(root))
  bridge=subprocess.Popen(['node',str(client_dir/'server.mjs')],env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=log,text=True)
  def mcp(method,params):
   bridge.stdin.write(json.dumps({'jsonrpc':'2.0','id':1,'method':method,'params':params})+'\n');bridge.stdin.flush()
   return json.loads(bridge.stdout.readline())
  try:
   assert mcp('initialize',{'protocolVersion':'2024-11-05'})['result']['serverInfo']['version']=='2.2.0'
   assert len(mcp('tools/list',{})['result']['tools'])==14
   result=mcp('tools/call',{'name':'wiki_read_page','arguments':{'page':page}})['result']
   assert not result.get('isError') and page in result['content'][0]['text'],result
   result=mcp('tools/call',{'name':'wiki_search','arguments':{'query':'肺癌 ALK','mode':'AND'}})['result']
   assert not result.get('isError') and page in result['content'][0]['text'],result
   result=mcp('tools/call',{'name':'wiki_read_standard_backup','arguments':{'page':page,'age':first_age}})['result']
   assert not result.get('isError') and 'pukiwiki_backup' in result['content'][0]['text'],result
   result=mcp('tools/call',{'name':'wiki_read_attachment','arguments':{'page':page,'name':'資料.txt','age':1}})['result']
   assert not result.get('isError') and result['content'][1]['resource']['text']=='hello',result
  finally:bridge.terminate();bridge.wait(timeout=5)
 html=get(url);post({'action':'revoke','csrf':csrf(html),'label':'desktop'})
 assert endpoint('/capabilities',token=key)[0]==401
 print('PASS: browser setup, admin authentication, CSRF, private storage, reinitialization guard, one-time key display, Japanese query routes, AND search, attachment upload/archive/read, backup listing, key revocation')
 print('fixture:',root)
finally:
 proc.terminate();proc.wait(timeout=10);log.close()
