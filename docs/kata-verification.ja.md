# BEAR.KataでのDI/AOP検証

## 判断

BEAR.Kataは、実在するコンテキスト、分割されたModule、属性によるAOP、テスト用の
束縛差し替えを確認する検証先として使える。ただし、Kataだけで静的解析全体の
正しさを保証することはできない。Kataの既存テストと、小さい独立したRayの検証を
組み合わせる。

| 確認したいこと | Kataの使い方 | 残る境界 |
| --- | --- | --- |
| コンテキスト候補 | `BEAR\Kata\Injector`への呼び出し元を収集する | アプリ固有Bootstrapの処理や動的な値は推測しない |
| AOPの適用先 | CSRF/SameOriginの属性、Resource、Moduleの宣言を照合する | source matchだけでは実行時の織り込み成功を証明しない |
| DIの差し替え | FakeModule/TestModuleと宣言位置を読む | テストが追加するoverride Moduleはコンテキスト文字列だけでは表現できない |
| AOPの詳細な順序、DIの衝突 | 実際のRayを使う小さな独立fixtureで比較する | Kataに存在しないケースは既存Kataテストの成功で代用しない |
| 属性カタログの規模 | vendor込みで走査し、打ち切り情報を確認する | ページ送りでは走査上限により省かれた定義は回収できない |

## 固定した検証対象

- BEAR.Kata: `54f0aef60aa1eb3ba08c4027506edfacfe0b1f0b`
- composer.lock SHA-256: `7c3d7947cd453b3757053ace84541bcd13a03be71bffad64e0fe762bf34d0c51`
- BEAR.Package 1.20.3、Ray.Di 2.20.0、Ray.Aop 2.19.1、BEAR.Sunday 1.8.0

元のKata checkoutは変更しない。追跡済みソースの隔離コピーを作り、export-ignoreで
抜けるtestsも補ってから、lockを更新せず `composer install --no-scripts` する。
元プロジェクトの環境設定や秘密値はコピーしない。実行時テストは隔離コピー内で、
継承環境を除いた子プロセスと架空のテスト設定を使う。DBや外部サービスへの接続は不要。

## 今回見つかった不具合

コンテキスト候補の解析は、以前は `BEAR\Package\Injector::getInstance()` の第1引数を
contextとして扱っていた。実際の第1引数はappName、第2引数がcontextである。
また、呼び出せるBootstrapは `BEAR\Package\Compiler\Bootstrap` だった。
誤ったAPI形を使ったfixtureも同時に修正した。

KataのInjectorはBEAR.Packageへの薄いstatic wrapperなので、本文が単一のreturnで、
context引数をそのまま既知のInjector APIへ渡す場合に限り呼び出し元を追う。
一般的なPHPのデータフロー解析には広げない。`getOverrideInstance()`は候補収集の
対象だが、その追加Moduleを通常のcontext合成へ混ぜない。

## 独立した照合

`tests/Integration/KataRayOracleTest.php` は、指定した隔離Kataの実際のRay依存を使う。
テスト自身は別プロセスで実行し、通常のテストへautoload状態を持ち越さない。

- AOP: 静的解析のチェーンと、実際の `Ray\Aop\Bind` のチェーンを比較する。
  priority、メソッド上の属性順、同じ属性に対するpointcutの置換、重複interceptorを対象とする。
- DI: 同じfixtureを静的解析と実際の `Ray\Di\Injector` に渡し、install時の既存束縛の維持と、
  override時の置換結果を比較する。
- Kata: テスト用コンテキスト候補の発見、4つのAdmin onPostのCSRFチェーン、
  未解決条件が残るときの `provisional` 表示を検証する。

実行例（extensionリポジトリのrootから）:

```sh
BEAR_KATA_VERIFY_ROOT=/private/tmp/bear-kata-check \
  vendor/bin/phpunit tests/Integration/KataRayOracleTest.php
```

この照合は1 test / 22 assertionsで通過した。環境変数を指定しない通常のテスト実行ではskipする。

## Kataでの観測結果

| 検証 | 結果 |
| --- | --- |
| 既存のCSRF/SameOrigin配線テスト | 2 tests / 3 assertions、成功 |
| Interceptorの単体テストとDefer | 27 tests / 41 assertions、成功 |
| コンテキスト候補 | 5候補を取得。自動選択はしない |
| `test-hal-api-app`のCsrfToken適用先 | 4つのResourceメソッド。`SameOriginInterceptor → CsrfTokenInterceptor` |
| 同AOP問い合わせの不確実性 | 全結果が`provisional`。unknown / unresolvedPointcutは各222 |
| CsrfTokenを指定した属性カタログ | 1定義を取得。ただし5,000ファイルの上限に達し`scanTruncated: true` |

検出した候補は `cli-hal-api-app`、`fake-hal-api-app`、`hal-api-app`、
`html-test-hal-api-app`、`test-hal-api-app`。

222は独立した222個の不具合を表す数ではなく、問い合わせの対象に対して報告された
未解決条件の件数である。カタログの完全一致フィルタも走査上限を解消しない。
この3種の静的問い合わせをまとめた実測は4.13秒、ピークメモリ94 MiBだった。
環境とキャッシュの状態に依存する参考値であり、性能の合格基準ではない。

## 結果の読み方

KataのCSRF配線テストは `html-test-hal-api-app` にさらにテスト専用Moduleを渡す。
一方、Deferのテストにはアプリのコンテキストとは別のInjector構成がある。
これらのテスト成功を、context-only問い合わせの全束縛が実行時と等しいという
根拠にはしない。

静的問い合わせで残る `provisional`、`unknownTotal`、`unresolvedPointcutTotal` と、
カタログの `scanTruncated` を成功結果から消してはならない。今回の対象バージョンでの
限定した照合であり、他のRayバージョンや動的Moduleへの互換性保証ではない。

## 最終チェックと残作業

2026-09-28時点で、extensionは663 tests / 5,476 assertions / 1 skip、MCPは
61 tests / 530 assertions / 3 skipsで通過した。両リポジトリのPHPCSとPHPStanも通過。
extensionのskipは任意のKata照合テストで、環境変数を付けた別実行では成功している。
MCPの実行には `BEAR_MCP_TEST_EXTENSION_ROOT` で今回のextension checkoutを指定した。

次の改善候補は、属性カタログの走査上限への対処、AOP未解決条件の理由ごとの整理、
Module関係と宣言一覧をつなぐ照会。無効な属性の診断は、その属性のAOP上の役割と
対象Moduleの構成が確定する範囲から追加する。未解決や走査打ち切りを根拠に
「効いていない」と断定しない。リリースとインストール済みMCPへの反映は別作業。
