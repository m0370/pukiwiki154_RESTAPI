<?php
/**
 * API リクエストの identity（誰として PukiWiki を触るか）。
 *
 * 生成後は変更できない。認証が済んだ時点で 1 つ作り、PageStore::withIdentity()
 * でその identity を持つインスタンスを得る。read も write も同じ 1 つの identity を
 * 見るため、「読み取りはプロパティ・書き込みは引数」という二重管理にならない。
 *
 * License: GPL v2 or (at your option) any later version（PukiWiki 1.5.4 本体に準拠）
 */
declare(strict_types=1);

final class Identity
{
    /**
     * @param string $actor     監査ログと #author に残す名前（API キーのラベル等）
     * @param string $wiki_user 名乗る PukiWiki ユーザー名。'' なら未ログイン扱い（fail-closed）
     */
    public function __construct(
        public readonly string $actor = '',
        public readonly string $wiki_user = '',
    ) {}

    /** 未ログイン扱いの identity。$read_auth / $edit_auth のあるページは触れない。 */
    public static function anonymous(): self
    {
        return new self('', '');
    }

    public function isAnonymous(): bool
    {
        return $this->wiki_user === '';
    }
}
