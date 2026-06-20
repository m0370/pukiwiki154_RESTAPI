# PukiWiki REST API — API キー管理

> **ドキュメント種別**: 運用手順  
> **ステータス**: 現時点（Phase 0〜8）の手順。将来的に管理 UI を追加予定。

---

## 現時点の方法（CLI スクリプト）

現バージョンにはキー管理 UI がありません。PHP の CLI スクリプトで直接 Ledger（SQLite）を操作します。

### キー発行スクリプト

以下のスクリプトを一時ファイル（例: `/tmp/gen_key.php`）に作成して実行し、実行後は削除してください。

```php
<?php
// /tmp/gen_key.php

chdir('/var/www/pukiwiki/rest-api');
require_once 'bootstrap.php';

// ─── ここを編集する ───────────────────────────────────
$label     = 'claude-ai';                // キーの識別名（監査ログに記録される）
$scopes    = 'page:read draft:create';   // 付与するスコープ（スペース区切り）
$expires   = null;                       // 有効期限: time() + 86400*30 など。nullで無期限
$ip_allow  = null;                       // IP制限: '192.168.1.0/24' など。nullで無制限
// ──────────────────────────────────────────────────────

$raw_key = bin2hex(random_bytes(32));    // 64文字のランダムトークン

$key_id = $REST_LEDGER->registerApiKey(
    $raw_key,   // 生トークン（SHA-256 ハッシュで保存される）
    $label,
    $scopes,
    time(),
    $expires,
    $ip_allow
);

echo "=== API キー発行完了 ===\n";
echo "ID     : {$key_id}\n";
echo "Label  : {$label}\n";
echo "Scopes : {$scopes}\n";
echo "Expires: " . ($expires ? date('Y-m-d H:i:s', $expires) : '無期限') . "\n";
echo "IP Allow: " . ($ip_allow ?? '制限なし') . "\n";
echo "\n";
echo "Bearer token (一度しか表示されません):\n";
echo $raw_key . "\n";
echo "\n";
echo "このトークンをコピーして安全に保管してください。\n";
echo "DB にはハッシュのみ保存されるため、紛失した場合は新たに発行が必要です。\n";
```

```bash
php /tmp/gen_key.php
rm /tmp/gen_key.php
```

出力例:
```
=== API キー発行完了 ===
ID     : 3
Label  : claude-ai
Scopes : page:read draft:create
Expires: 無期限
IP Allow: 制限なし

Bearer token (一度しか表示されません):
a3f9c2e1d04b7f82c5e9d1f3b8a20e74c6d5e3f1b2a8c9d0e7f4a1b3c2d8e9f0

このトークンをコピーして安全に保管してください。
DB にはハッシュのみ保存されるため、紛失した場合は新たに発行が必要です。
```

---

## スコープの選び方

| 用途 | 推奨スコープ |
|------|------------|
| AI エージェント（Claude 等）| `page:read draft:create` |
| 人間の編集者（下書き承認者）| `page:read draft:create draft:approve` |
| 自動化スクリプト（直接書き込み）| `page:read page:write` |
| 管理者 | `page:read draft:create draft:approve page:write admin` |
| 読み取り専用（bot・監視）| `page:read` |

**AI に `page:write` を渡さない**ことが設計の根幹です。AI は下書きを作成し、人間が承認したときに初めて本番ページへ反映されます。

---

## キー一覧・確認

```php
<?php
// /tmp/list_keys.php

chdir('/var/www/pukiwiki/rest-api');
require_once 'bootstrap.php';

$rows = $REST_LEDGER->db_query(
    "SELECT id, label, scopes, ip_allow, status, created_at, expires_at
     FROM api_keys ORDER BY id"
);

printf("%-4s %-20s %-35s %-8s %s\n", 'ID', 'Label', 'Scopes', 'Status', 'Expires');
echo str_repeat('-', 90) . "\n";
foreach ($rows as $r) {
    $exp = $r['expires_at'] ? date('Y-m-d', $r['expires_at']) : '無期限';
    printf("%-4s %-20s %-35s %-8s %s\n",
        $r['id'], $r['label'], $r['scopes'], $r['status'], $exp);
}
```

> **注意**: `Ledger::db_query()` はパブリックメソッドではありません。  
> 実際には SQLite ファイルを直接 `sqlite3` コマンドで確認するのが現実的です:

```bash
sqlite3 /var/www/pukiwiki/rest-api/data/db/ledger.sqlite \
  "SELECT id, label, scopes, status, datetime(created_at,'unixepoch') FROM api_keys;"
```

---

## キーの無効化（失効）

```php
<?php
// /tmp/revoke_key.php

chdir('/var/www/pukiwiki/rest-api');
require_once 'bootstrap.php';

$key_id = 3;  // 無効化したいキーの ID

$result = $REST_LEDGER->revokeApiKey($key_id, time());
echo $result
    ? "キー ID={$key_id} を無効化しました\n"
    : "キー ID={$key_id} が見つかりません\n";
```

失効したキーは `status='revoked'` になります。物理削除はしません（監査目的）。

---

## セキュリティ注意事項

| 項目 | 内容 |
|------|------|
| **保存形式** | 生トークンは保存しない。SHA-256 ハッシュのみ DB に保存 |
| **再表示不可** | 発行時の出力が唯一の機会。紛失したら再発行 |
| **IP 制限** | サーバー固定 IP があれば `ip_allow` に CIDR を設定 |
| **有効期限** | 長期間使わないキーには `expires_at` を設定 |
| **スコープ最小化** | 必要なスコープだけを付与する |
| **AI のスコープ** | `page:write` は渡さない。`draft:create` まで |

---

## 将来の計画: キー管理 UI

現状は CLI のみで、専用管理画面はありません。以下は将来的な実装検討事項です。

### 認証方法の選択肢

キー管理 UI には管理者認証が必要です。候補:

| 方式 | 特徴 | 向いているケース |
|------|------|----------------|
| **HTTP Basic 認証（Apache）** | 設定が最もシンプル。`.htpasswd` で管理 | 個人・小規模サイト |
| **既存の PukiWiki 認証と連携** | PukiWiki のセッションを流用 | PukiWiki を使い慣れている場合 |
| **独自セッション（PHP）** | ログイン UI を自前で実装 | より柔軟な権限制御が必要な場合 |
| **外部 OAuth / SSO** | Google 等の認証を利用 | 組織利用・ユーザー数が多い場合 |

### 必要な機能

管理 UI が実装された際に必要な画面・機能:

- [ ] キー一覧（label・スコープ・有効期限・ステータス）
- [ ] キー新規発行フォーム（label・スコープ選択・IP 制限・有効期限）
- [ ] 発行後のトークン表示（1 回限り）
- [ ] キー個別の失効（ボタン 1 クリック）
- [ ] 使用状況（監査ログとのリンク）

### 実装に際しての決定事項

以下は実装前に確認が必要です:

1. **管理者認証方式**: 上記の選択肢から決定する
2. **管理者の範囲**: 全スコープを付与できる人を誰にするか
3. **UI の設置場所**: `/rest-api/api/admin-ui/` など（Web 非公開は困難なので `.htpasswd` 必須）
4. **監査**: キー発行・失効操作自体を監査ログに記録するか

---

## 関連ドキュメント

- [setup.md](setup.md) — インストール・環境設定
- [api-reference.md](api-reference.md) — 全エンドポイントのリファレンス
