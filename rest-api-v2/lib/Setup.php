<?php
declare(strict_types=1);
require_once __DIR__ . '/KeyStore.php';
require_once __DIR__ . '/LocalConfig.php';

final class Setup
{
    public static function candidate(string $documentRoot, string $wikiRoot): string
    {
        $root = realpath($documentRoot);
        if ($root === false || $root === DIRECTORY_SEPARATOR) return '';
        return dirname($root) . '/pukiwiki-rest-' . substr(hash('sha256', $wikiRoot),0,10);
    }
    public static function privateDirectory(string $path, string $documentRoot): string
    {
        $root = realpath($documentRoot);
        if ($root === false || $path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new RuntimeException('公開ディレクトリ外の保存先を絶対パスで指定してください。');
        }
        $real = realpath($path);
        if ($real === false) {
            $parent = realpath(dirname($path));
            $leaf = basename($path);
            if ($parent === false || in_array($leaf,['.','..',''],true)) throw new RuntimeException('保存先の親フォルダが見つかりません。FTPで作成してください。');
            $real = $parent . '/' . $leaf;
        }
        if ($real === $root || str_starts_with($real . '/', rtrim($root,'/') . '/')) {
            throw new RuntimeException('この保存先はWeb公開領域内です。公開領域の外を指定してください。');
        }
        if (!is_dir($real) && !@mkdir($real,0750)) throw new RuntimeException('保存先を作成できません。FTPでフォルダを作成し、PHPの書込権限を確認してください。');
        $probe = $real . '/.probe-' . bin2hex(random_bytes(12));
        if (@file_put_contents($probe,'probe',LOCK_EX) !== 5) throw new RuntimeException('保存先に書き込めません。');
        unlink($probe);
        return (string)realpath($real);
    }
    public static function initialize(string $path, string $documentRoot): void
    {
        $configFile = dirname(__DIR__) . '/config.local.php';
        if (is_file($configFile) || getenv('PKWK_REST_DATA') || getenv('PKWK_API_KEYS')) {
            throw new RuntimeException('既存の設定があります。保存先の変更はこの画面では行いません。');
        }
        $data = self::privateDirectory($path,$documentRoot);
        foreach (['audit','locks','snapshots'] as $sub) {
            if (!is_dir($data . '/' . $sub) && !mkdir($data . '/' . $sub,0750)) throw new RuntimeException('保存用フォルダを作成できません。');
        }
        // 初回登録を並列に実行しても既存設定を上書きしない。
        $lock = fopen(dirname(__DIR__) . '/.setup.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('設定をロックできません。');
        try {
            if (is_file($configFile)) throw new RuntimeException('設定済みです。ページを再表示してください。');
            KeyStore::writePhp($configFile,['data_dir'=>$data,'keys_file'=>null]);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
        LocalConfig::reset();
    }
    public static function keys(string $documentRoot): KeyStore
    {
        self::privateDirectory(LocalConfig::dataDir(),$documentRoot);
        $file = LocalConfig::keysFile();
        self::privateDirectory(dirname($file),$documentRoot);
        if (is_link($file)) throw new RuntimeException('キー設定にシンボリックリンクは使用できません。');
        return new KeyStore($file);
    }
}
