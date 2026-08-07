<?php
declare(strict_types=1);

/**
 * ローカル設定ファイルの読み込み。
 *
 * 標準の設定手段は環境変数（PKWK_REST_DATA 等）だが、共有ホスティングでは
 * Apache の SetEnv も php-fpm のプール設定も触れず、環境変数が PHP に届かない
 * ことがある。その場合の逃げ道として rest-api-v2/config.local.php を置ける。
 *
 *   <?php
 *   return [
 *       'data_dir' => '/home/example/example.com/rest-data',
 *       'keys_file' => null,   // 省略時は data_dir . '/keys.php'
 *   ];
 *
 * 優先順位は 環境変数 > config.local.php > 既定値。
 * Web と CLI（bin/make-key.php）の双方がこのクラスを使うため、
 * キーの置き場所が両者でずれない。
 *
 * config.local.php は環境ごとの値なのでバージョン管理に含めないこと
 * （.gitignore 済み）。DocRoot 内に置かれるため、data/ 自体は必ず
 * DocRoot 外を指すこと。
 *
 * License: GPL v2 or (at your option) any later version（PukiWiki 1.5.4 本体に準拠）
 */
final class LocalConfig
{
    private static ?array $config = null;

    /** config.local.php の内容（無ければ空配列）を返す */
    public static function all(): array
    {
        if (self::$config === null) {
            $file = dirname(__DIR__) . '/config.local.php';
            $loaded = is_file($file) ? require $file : null;
            self::$config = is_array($loaded) ? $loaded : [];
        }
        return self::$config;
    }

    /** 環境変数 > config.local.php > 既定値 の順で解決する */
    public static function get(string $env_name, string $config_key, string $default): string
    {
        $env = getenv($env_name);
        if ($env !== false && $env !== '') {
            return $env;
        }
        $value = self::all()[$config_key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return $default;
    }

    /** データディレクトリ（キー・監査ログ・スナップショット） */
    public static function dataDir(): string
    {
        return self::get('PKWK_REST_DATA', 'data_dir', dirname(__DIR__) . '/data');
    }

    /** keys.php のパス */
    public static function keysFile(): string
    {
        return self::get('PKWK_API_KEYS', 'keys_file', self::dataDir() . '/keys.php');
    }

    /** テスト用: キャッシュを捨てて再読込させる */
    public static function reset(): void
    {
        self::$config = null;
    }
}
