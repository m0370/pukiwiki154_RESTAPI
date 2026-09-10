<?php
// 本体不要の機能・認可テスト。全データはシステム一時領域のみ。
if (PHP_SAPI !== 'cli') exit('CLI only');
require __DIR__ . '/testlib.php';
$tmp = sys_get_temp_dir().'/pkwk-features-'.bin2hex(random_bytes(5));
mkdir($tmp,0750);
putenv('PKWK_ROOT='.$tmp); putenv('PKWK_REST_DATA='.$tmp.'/private');
require __DIR__.'/../bootstrap.php';
require __DIR__.'/../lib/WikiFiles.php';
require __DIR__.'/../lib/Setup.php';

$p = $REST_PAGES;
$p->write('肺癌/治療',"薬剤はオシメルチニブ\n",PageStore::EMPTY_SHA1);
$p->write('別ページ',"肺癌について\n",PageStore::EMPTY_SHA1);
$p->write('Other',"ALK inhibitor\n",PageStore::EMPTY_SHA1);
ok(count($p->search('肺癌 オシメルチニブ',20,'AND'))===1,'ANDはタイトルと本文を横断して全語一致');
ok(count($p->search('肺癌　ALK',20,'OR'))===3,'ORは全角空白を区切りとしていずれかの語に一致');
ok(count($p->search('肺癌 オシメルチニブ'))===0,'既定は従来のフレーズ検索');
ok(count($p->search('alk',20,'AND'))===1,'英字の大小を区別しない');
expect_api_error(fn()=>$p->search('肺癌',20,'X'),400,'未知の検索方式を拒否');

function _is_page_accessible($p,$rules) { return $p !== '別ページ'; }
$GLOBALS['read_auth']=1; $GLOBALS['read_auth_pages']=[];
ok(count($p->search('肺癌',20,'OR'))===1,'閲覧できないページは検索から除外');
define('UPLOAD_DIR',$tmp.'/attach/'); mkdir(UPLOAD_DIR);
function exist_plugin($name) { return $name==='attach'; }
function get_backup($page) { return [1=>['time'=>123,'data'=>["旧本文\n"]]]; }
function is_page_writable($page) { return empty($GLOBALS['fixture_edit_auth']) || ($GLOBALS['auth_user']??'')==='editor'; }
function is_freeze($page) { return ($GLOBALS['fixture_frozen']??false) && $page==='肺癌/治療'; }
function is_editable($page) { return !is_freeze($page); }
$GLOBALS['fixture_edit_auth']=true;
$reader = new WikiFiles($p,$REST_AUDIT,['label'=>'reader','scope'=>'read']);
$writer = new WikiFiles($p->withIdentity(new Identity('editor','editor')),$REST_AUDIT,['label'=>'editor','scope'=>'write','attachments_write'=>true]);
ok(count($reader->backups('肺癌/治療')['backups'])===1,'標準バックアップ一覧');
ok($reader->backups('肺癌/治療',1)['content']==="旧本文\n",'標準バックアップの本文');
expect_api_error(fn()=>$reader->backups('肺癌/治療',2),404,'存在しない版を拒否');
expect_api_error(fn()=>$reader->backups('別ページ',1),403,'閲覧不可ページの標準バックアップを拒否');
expect_api_error(fn()=>$reader->backups(':config',1),403,'システムページの履歴を拒否');
expect_api_error(fn()=>$reader->backups('削除済み',1),404,'削除済みページの履歴を公開しない');
expect_api_error(fn()=>$reader->upload('肺癌/治療','a.txt',base64_encode('test')),403,'readキーは添付変更不可');
$normal = new WikiFiles($p,$REST_AUDIT,['label'=>'normal','scope'=>'write']);
expect_api_error(fn()=>$normal->upload('肺癌/治療','a.txt',base64_encode('test')),403,'既存writeキーも添付変更は既定拒否');
$noUser = new WikiFiles($p,$REST_AUDIT,['label'=>'nouser','scope'=>'write','attachments_write'=>true]);
expect_api_error(fn()=>$noUser->upload('肺癌/治療','a.txt',base64_encode('test')),403,'添付変更にも編集認証を適用');
$a=$writer->upload('肺癌/治療','資料.txt',base64_encode('test'));
ok($a['sha256']===hash('sha256','test'),'添付アップロード');
ok(count($reader->list('肺癌/治療')['attachments'])===1,'添付一覧');
ok(base64_decode($reader->read('肺癌/治療','資料.txt')['content_base64'])==='test','添付取得');
expect_api_error(fn()=>$reader->list('別ページ'),403,'閲覧不可ページの添付一覧を拒否');
expect_api_error(fn()=>$reader->read('別ページ','資料.txt'),403,'閲覧不可ページの添付取得を拒否');
expect_api_error(fn()=>$writer->upload('肺癌/治療','資料.txt',base64_encode('changed')),409,'添付の暗黙の上書きを拒否');
expect_api_error(fn()=>$writer->upload('肺癌/治療','../x.txt',base64_encode('test')),400,'パストラバーサルを拒否');
expect_api_error(fn()=>$writer->upload('肺癌/治療','x.php',base64_encode('test')),400,'実行形式の新規添付を拒否');
expect_api_error(fn()=>$writer->upload('肺癌/治療','x.txt','***'),400,'不正Base64を拒否');
expect_api_error(fn()=>$writer->upload('肺癌/治療','x.txt',base64_encode(str_repeat('x',WikiFiles::MAX_BYTES+1))),413,'サイズ上限');
$GLOBALS['fixture_frozen']=true;
expect_api_error(fn()=>$writer->delete('肺癌/治療','資料.txt',$a['sha256']),403,'凍結ページの添付削除を拒否');
$GLOBALS['fixture_frozen']=false;
expect_api_error(fn()=>$writer->delete('肺癌/治療','資料.txt',str_repeat('0',64)),409,'古いSHA256の削除を拒否');
$d=$writer->delete('肺癌/治療','資料.txt',$a['sha256']);
ok($d['age']===1,'削除は標準添付履歴に移動');
expect_api_error(fn()=>$reader->read('肺癌/治療','資料.txt'),404,'削除した添付は現在版から消える');
ok(base64_decode($reader->read('肺癌/治療','資料.txt',1)['content_base64'])==='test','削除した添付を履歴から取得');
$writer->upload('肺癌/治療','資料.txt',base64_encode('second'));
ok(count($reader->list('肺癌/治療')['attachments'])===2,'再アップロード後も削除履歴を維持');
$file=UPLOAD_DIR.strtoupper(bin2hex('肺癌/治療')).'_'.strtoupper(bin2hex('資料.txt'));
file_put_contents($file.'.log',"0\n1\n\n1\n");
expect_api_error(fn()=>$writer->delete('肺癌/治療','資料.txt',hash('sha256','second')),403,'添付自身の凍結を尊重');
symlink($tmp.'/private/keys.php',UPLOAD_DIR.strtoupper(bin2hex('肺癌/治療')).'_'.strtoupper(bin2hex('link.txt')));
expect_api_error(fn()=>$reader->read('肺癌/治療','link.txt'),403,'シンボリックリンクを拒否');

mkdir($tmp.'/public');
$outside=Setup::privateDirectory($tmp.'/setup-data',$tmp.'/public');
ok(is_dir($outside),'公開領域外の保存先を作成');
try { Setup::privateDirectory($tmp.'/public/data',$tmp.'/public'); ok(false,'公開領域内を拒否'); }
catch(RuntimeException $e) { ok(true,'公開領域内を拒否'); }
symlink($tmp.'/public',$tmp.'/alias');
try { Setup::privateDirectory($tmp.'/alias/data',$tmp.'/public'); ok(false,'symlink経由の公開領域を拒否'); }
catch(RuntimeException $e) { ok(true,'symlink経由の公開領域を拒否'); }
$store=new KeyStore($outside.'/keys.php');
$raw=$store->create('desktop','write','editor',true);
ok(!str_contains(file_get_contents($outside.'/keys.php'),$raw),'生キーは保存しない');
$auth=new Auth($outside.'/keys.php');
ok($auth->authenticate('Bearer '.$raw,'write')['attachments_write']===true,'明示した添付権限を認証結果に反映');
try { $store->create('desktop','read'); ok(false,'キー重複拒否'); } catch(InvalidArgumentException $e) { ok(true,'キー重複拒否'); }
$store->revoke('desktop');
expect_api_error(fn()=>(new Auth($outside.'/keys.php'))->authenticate('Bearer '.$raw,'read'),401,'ブラウザーで失効したキーを拒否');
summary();
