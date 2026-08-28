<?php

declare(strict_types=1);

function uri(string $uri): string
{
    return $uri;
}

/**
 * LSPテストがジャンプ・補完の対象にするURI文字列群。
 *
 * @return list<string>
 */
function fixtureUris(): array
{
    return [
        uri('app://self/user'),
        uri('page://self/index'),
        uri('app://self/blog/posts'),
        uri('app://self/missing'),
        uri('app://self/'),
        uri('app://self/u'),
        uri('page://self/'),
        // コンテキスト接頭辞: 直接のクラスが無い x は Content と Admin の2件が返る
        uri('page://self/x'),
        // 直接のクラスが在る y は1件だけが返る
        uri('page://self/y'),
        // ImportApp で取り込まれた別アプリ (tags ホスト → Acme\Tags)
        uri('app://tags/api/search'),
        // 対応表に無いホストは候補なし
        uri('app://unknown/api/search'),
        // Resource ディレクトリの外へ出ようとするパスは定義ジャンプしない
        uri('app://self/../../Client'),
    ];
}
