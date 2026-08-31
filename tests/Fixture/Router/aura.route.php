<?php

declare(strict_types=1);

// Aura.Router goto demo: Cmd/Ctrl+B on '/index' or '/dashboard' jumps to the Page resource.
$map->route('/index', '/index', '/index');
$map->route('/dashboard', '/dashboard', '/dashboard');
$map->route('/missing', '/missing', '/missing');
$map->route('/../../Client', '/../../Client', '/../../Client');
$map->route('/articleRedirector', '/articleRedirector', '/articleRedirector');
$map->route('/userProfile', '/userProfile', '/userProfile');
$map->route('/ambiguous', '/ambiguous', '/ambiguous');
// 第1引数=ルート名だけが Page リソースに対応する。第2引数は HTTP の URL パターンで
// リソースとは無関係。'/thing/{id}' から飛ぶと Page/Thing.php に着地してしまう。
$map->route('/thing/detail', '/thing/{id}');
// 名前とパターンがまったく別系統の対。将来「URLパターンからも飛ばせたら便利では」と
// いう提案に対する防波堤として、第2引数 '/tag/' からは飛ばないことを固定する。
$map->route('/keywords', '/tag/');
// メソッド連鎖と改行を挟む書き方でも第1引数から飛ぶ。
$map->route('/a/b', '/a/{id}')->tokens([
    'slug' => '(\w|-)+',
]);
// get などの HTTP メソッド別ショートカットも route と同じ引数形なので、第1引数 (ルート名) から飛ぶ。
$map->get('/keywords', '/tag/');
// attach の第1引数は名前の接頭辞 (ルート名ではない) なので飛ばない。
// '/admin/ambiguous' は Page/Admin/Ambiguous.php (実在) に着地しうる形で、誤って
// 受け入れたら赤になる。「対応するファイルが無いから null」で通らないようにしている。
$map->attach('/admin/ambiguous', '/admin', function ($map) {
});

// 注: このフィクスチャのルート名 (第1引数) は実アプリと同じくすべて '/' で始まる
// (実アプリ4本、50〜59本ずつのルートで実測)。
// 依頼Cまでは 'index' のように '/' なしの形もあったが、実アプリのどれとも違う形だったため
// '/' 始まりに書き換えた。
