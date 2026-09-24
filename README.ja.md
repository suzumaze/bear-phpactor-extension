# suzumaze/bear-phpactor-extension

[English](README.md) | 日本語

[BEAR.Sunday](https://bearsunday.github.io/manuals/1.0/ja/)固有の意味を[Phpactor](https://phpactor.readthedocs.io/)へ追加し、標準の[LSP](https://microsoft.github.io/language-server-protocol/)操作からResource URI、SQL、JSON Schema、ALPS、Router、Twig、Qiqの規約を扱えるようにするComposerパッケージです。

```text
BEAR.Sunday固有の意味
        ↓
bear-phpactor-extension
        ↓
Phpactor / LSP
        ↓
VS Code / Neovim / Emacs / その他のLSPクライアント
```

このパッケージはPhpactorへlocator、provider、completorを登録します。エディタ固有APIの実装、テンプレートのレンダリング、アプリケーションPHPの実行、MCP機能は行いません。

## 必要なもの

- [PHP](https://www.php.net/) 8.2以上
- [Composer](https://getcomposer.org/)
- [`composer.json`](composer.json)の依存範囲と互換性があるPhpactor
- `autoload.psr-4`が設定されたBEAR.Sundayプロジェクト

## 機能

| BEAR.Sundayの意味 | LSP操作と解決先 |
|---|---|
| [Resource URI](https://bearsunday.github.io/manuals/1.0/ja/resource.html) | `app://self/user`のDefinition、References、Hover、URI Completion、Document Link |
| [SQL](https://bearsunday.github.io/manuals/1.0/ja/database.html) | `#[DbQuery('point_distance')]`と`@Query("point_distance")`のDefinition、References、Hover |
| [JSON Schema](https://bearsunday.github.io/manuals/1.0/ja/validation.html) | Definition、Type Definition、References、Hover、body property Completion |
| [ALPS](https://bearsunday.github.io/manuals/1.0/ja/apidoc.html) | `apidoc.xml`で選択されたdescriptorのDefinition、References、Hover |
| [TwigとQiq](https://bearsunday.github.io/manuals/1.0/ja/html.html) | 静的テンプレート参照のDefinition、References、Hover、Document Linkと、`#[Embed]` relationからのDefinition |
| [Aura Router](https://bearsunday.github.io/manuals/1.0/ja/router.html) | ルート名からPage ResourceへのDefinition、References、Hover |
| 静的な不整合 | 現在のeditor bufferに明示された壊れたBEAR参照を標準LSP診断として表示 |

プロジェクトルートと名前空間の接頭辞は、対象プロジェクトの`composer.json`から取得します。通常のPHP定義ジャンプは引き続きPhpactorが処理します。

## エディタ診断

Phpactorは、現在のeditor bufferに明示されたResource URI、Route、SQL、JSON Schema、ALPS、
Twig、Qiq参照が静的に`not_found`、`ambiguous`、`invalid_input`、`parse_error`と証明できる場合、
source `bear`のwarning診断を配信します。解析するのは現在の1文書だけで、参照先は保存済みworkspace
fileから解決します。編集のたびにproject全体を再走査せず、一度も保存されていない新規fileは対象外です。

保存済みproject全体を扱う`bear/project/diagnostics`は、これに加えてResource解析失敗、Link/Embed先
method、contract名の差も検査します。editor診断は意図的にそれらのproject-wide検査を行いません。
provider名は`bear`です。Phpactor標準の`language_server.diagnostics_*`設定に従い、providerを明示選択
する場合は`language_server.diagnostic_providers`で有効・無効を制御できます。

## ヘッドレスSemantic Query

文書内Positionを使える場合は標準LSP methodを優先します。BEAR identifierは分かっているものの
開いた文書やPositionがないclient向けに、Language Serverはproject、Resource、Route、SQL、
Template、ALPS、Schemaを問い合わせる21個のread-only `bear/*` requestも提供します。
Resource属性のfactsとworkspace全体の一覧は、application PHPを実行せず取得できます。
`bear/project/diagnostics`は、保存済みsourceに明示された参照から静的に証明できる不整合を
project全体で集約し、個別itemの失敗を外側のquery statusから分離します。
`bear/project/contractCoverage`は、request/response JSON SchemaとALPSのcontract surfaceが
利用可能・未導入・動的・未解決のどれかを返し、任意のcontract未導入をproject errorとは
扱いません。`scheme`とproject全体の`summary.schemes`は`app`と`page`を分けますが、schemeだけで
公開範囲やJSON表現であることは推測しません。
`bear/project/info`は安定したprotocol名`bear-semantic`、利用可能なrequest、capabilityを返します。
追加的に進化するcontractの詳細は
[`docs/lsp-semantic-requests.md`](docs/lsp-semantic-requests.md)にあります。

IDEは不要です。同梱clientは実際のPhpactor stdio processを起動します。

```bash
php tools/semantic-lsp-query.php /path/to/bear-project \
  bear/resource/describe \
  '{"uri":"app://self/user","contextPath":"src/Resource/App/User.php"}'
```

AI clientからcacheやResource metadataをworkspace単位で監査する例:

```bash
php tools/semantic-lsp-query.php /path/to/bear-project \
  bear/resource/attributeIndex \
  '{"scheme":"app","limit":50}'
```

Resource method、JSON Schema、ALPS descriptor間でrequest名の存在だけを比較する例
（型や意味の一致は主張しません）:

```bash
php tools/semantic-lsp-query.php /path/to/bear-project \
  bear/contract/compare \
  '{"uri":"app://self/user","method":"onPost","schemaKind":"request"}'
```

response比較では、静的に証明できる`$this->body`のkeyもResource面として扱います。
対象は直線的なliteral-key array代入だけです。動的または条件付きのbody構築は推測せず、
理由付きの`unsupported`として残します。

applicationを起動せず、project全体の静的診断を取得する例:

```bash
php tools/semantic-lsp-query.php /path/to/bear-project \
  bear/project/diagnostics \
  '{"limit":100,"offset":0}'
```

JSON SchemaやALPSを導入できるResource methodを探す例:

```bash
php tools/semantic-lsp-query.php /path/to/bear-project \
  bear/project/contractCoverage \
  '{"limit":100,"offset":0,"gapsOnly":true,"scheme":"page"}'
```

project全体の2つのreportは、安定したoffset paginationと概算serialized-byte budgetを使います。
pageは指定した`limit`より短くなる場合があります。`truncated`がtrueの間、実際に返ったitem数だけ
`offset`を進めてください。diagnosticsの`limit`は1〜200（既定100）、contract coverageは
1〜100（既定100）です。非常に大きい単一itemについてwire sizeを厳密に保証するものではありません。

問い合わせは保存済みworkspace fileだけを読みます。BEAR applicationの実行、file変更、network access、
MCP server機能は行いません。

## TwigとQiq

テンプレートジャンプは、BEAR.Sunday公式の[Qiq](https://bearsunday.github.io/manuals/1.0/ja/html-qiq.html)および[Twig](https://bearsunday.github.io/manuals/1.0/ja/html-twig-v2.html)の標準配置で確認できる関係だけを解釈します。

| 参照 | 対応するカーソル位置 | 解決先 |
|---|---|---|
| Twigパス | `extends`、`include`、`include()`の第1引数、または`block()`の第2引数にある静的文字列 | `src/Resource`、次に`var/templates`から見つかる既存テンプレート |
| Qiqパス | Qiqヘルパー構文またはネイティブPHPの`setLayout()`、`render()`、`extends()`にある静的文字列 | `var/qiq/template`内の既存`.php`テンプレート。`./`と`../`は現在のテンプレートを基準に解決 |
| Twig Embed relation | `var/templates/{App,Page}/.../*.html.twig`内の`{{ rel }}`または`{{ rel|raw }}`の先頭変数 | 親Resourceの`#[Embed]`が宣言するResourceのTwigテンプレート |
| Qiq Embed relation | `var/qiq/template/{App,Page}/.../*.php`内の`{{= $rel }}`または`{{h $rel }}`にある`$rel`。旧形式の`$this->rel`にも対応 | 親Resourceの`#[Embed]`が宣言するResourceのQiqテンプレート |

Embedジャンプは、名前付き静的文字列引数`rel:`と`src:`だけを読み取ります。絶対形式の`app://self/...`と`page://self/...`、および親Resourceのschemeを引き継いで`self`へ解決する相対形式`/...`に対応します。

動的な式、Twigのimportやプロパティ式、未確認のQiq/PHP呼び出し、ImportAppのResource、独自テンプレートルート、曖昧な規約は解決しません。

## インストール

Phpactorとこのパッケージは、同じComposerオートローダーから読み込める必要があります。

この節のコードは、エディタのコマンドパレットではなくターミナルで実行するコマンドです。

### VS Code

[Phpactor Setup for BEAR.Sunday](https://github.com/suzumaze/phpactor-setup-for-bear-sunday)を使用してください。動作確認済みのPhpactorとコアをプロジェクト外へインストールし、公式VS Codeクライアントの設定、使用中バージョンの確認、コアだけの更新を行えます。

### 手動グローバルインストール

[Phpactorのインストール方針](https://github.com/phpactor/phpactor#installation)では、言語サーバーをプロジェクト依存へ追加しない方法が推奨されています。次の手順では専用ディレクトリへインストールします。

```bash
mkdir -p ~/.local/share/phpactor-bear
cd ~/.local/share/phpactor-bear
composer init --no-interaction --name=local/phpactor-bear
composer config minimum-stability dev
composer config prefer-stable true
composer require \
  phpactor/phpactor:2026.07.22.0 \
  phpactor/language-server-protocol:3.17.4 \
  suzumaze/bear-phpactor-extension
```

既存の有効なPhpactor設定の他のキーを保持しながら、グローバル拡張クラス一覧を生成します。

```bash
mkdir -p "${XDG_CONFIG_HOME:-$HOME/.config}/phpactor"
cd "${XDG_CONFIG_HOME:-$HOME/.config}/phpactor"
PHPACTOR_BIN="$HOME/.local/share/phpactor-bear/vendor/bin/phpactor" \
  "$HOME/.local/share/phpactor-bear/vendor/bin/bear-phpactor-init"
```

エディタのPhpactorパスには次を指定します。

```text
~/.local/share/phpactor-bear/vendor/bin/phpactor
```

互換範囲内でこのコアだけを更新する場合は、次を実行します。

```bash
cd ~/.local/share/phpactor-bear
composer update suzumaze/bear-phpactor-extension --with-dependencies
cd "${XDG_CONFIG_HOME:-$HOME/.config}/phpactor"
PHPACTOR_BIN="$HOME/.local/share/phpactor-bear/vendor/bin/phpactor" \
  "$HOME/.local/share/phpactor-bear/vendor/bin/bear-phpactor-init"
```

### プロジェクト内インストール

Phpactorをプロジェクト内で管理している場合は、同じComposer環境へまとめてインストールし、プロジェクトルートから初期化します。

```bash
composer require --dev \
  phpactor/phpactor:2026.07.22.0 \
  phpactor/language-server-protocol:3.17.4 \
  suzumaze/bear-phpactor-extension
vendor/bin/bear-phpactor-init
vendor/bin/phpactor config:trust --trust
```

LSPクライアントには、同じ環境の`vendor/bin/phpactor`を指定してください。`container.extension_classes`はPhpactor組み込み一覧への追加ではなく置換として扱われるため、Phpactorのバージョンを変更した後は`bear-phpactor-init`を再実行します。

## エディタ側の条件

LSPクライアントがこのPhpactorを`language-server`で起動し、対象文書を送信する必要があります。

Qiqは`.php`なので通常どおりPhpactorへ送られます。[Phpactor公式VS Codeクライアント](https://github.com/phpactor/vscode-phpactor)はTwig文書を標準では選択しません。BEAR標準の`.html.twig`配置を試す場合は、ワークスペースの`.vscode/settings.json`へ次を追加できます。

```json
{
    "files.associations": {
        "*.html.twig": "php"
    }
}
```

これはTwigをPHP文書として送る暫定策で、syntax highlight、診断、formatter、他のTwig拡張へ影響する場合があります。document selectorを設定できるクライアントでは、PhpactorをTwig文書へ直接接続してください。

## 解決ルール

- カーソルが対応する参照上にあり、解決先が実在する場合だけ定義を返します。
- 解決先はworkspace内に限定し、パストラバーサルや任意の外部パスを拒否します。
  ただしエディタの定義ジャンプは、Composerの`vendor/composer/installed.json`に記録された
  ImportAppパッケージのrootに限り、path repositoryのsymlink先も許可します。read-onlyの
  `bear/*`問い合わせは、より厳しいworkspace内限定の境界を維持します。
- 不正な構文、存在しないファイル、未対応の式は例外にせず結果を返しません。
- 静的解析だけを行い、テンプレートのレンダリングやアプリケーションPHPの実行はしません。
- Resourceの候補が複数ある定義ジャンプでは、完全修飾名順の候補を表示します。曖昧な参照検索箇所は未解決として扱います。
- 同じEmbed relationが複数回現れる場合、すべてが同じ正規化済みResource URIを指すときだけ解決します。

## 定義ジャンプの使い分け

- Resource URI、SQL、属性のJSON Schema、ALPS、Router、テンプレートは「定義へ移動」を使用します。
- Resourceクラス宣言から規約に対応するJSON Schemaへは「型定義へ移動」を使用します。通常の「定義へ移動」はPhpactorの動作を維持します。
- Routerは第1引数のルート名だけを解釈します。第2引数はHTTPパスなので対象外です。`$map->attach()`も対象外です。

## 既知の制約

- テンプレートパスは、BEARのTwig/Qiq標準ローダー配置だけを扱います。
- VS Code公式クライアントでTwigを使うには、前述の暫定設定が必要です。
- SQL定義は`.sql`ファイルの先頭へ移動します。
- 参照検索は保存済みファイルだけを読み、`autoload` / `autoload-dev`のPSR-4ルートだけを走査します。
- Resource補完は`extends ... ResourceObject`のテキスト走査を使うため、余分な候補が含まれる場合があります。
- Windowsのドライブレターパスはテンプレート解決では防御されますが、PSR-4ディレクトリ解決では対応が不完全です。

## 関連プロジェクト

- [Phpactor Setup for BEAR.Sunday](https://github.com/suzumaze/phpactor-setup-for-bear-sunday): VS Code向けのインストール・更新ラッパー
- [BEAR.Sunday Extension Pack](https://marketplace.visualstudio.com/items?itemName=YukiAdachi.vscode-bear-sunday-extension-pack): 先行するVS Code固有実装
- [idea-php-bearsunday-plugin](https://github.com/bearsunday/idea-php-bearsunday-plugin): JetBrains固有機能を持つPhpStormプラグイン

各プロジェクトはアーキテクチャが異なり、互いを置き換えるものではありません。

## 開発

```bash
composer check
```

テストにはunit testと、initializeからshutdownまでの実Phpactor stdio sessionが含まれます。
さらに、対象applicationを明示して実行するproject単位の検証toolを同梱しています。

- `tools/coverage.php`: 独立に計算した規約とDefinitionの着地先を比較。
  `--assert-clean --expect-sites=N`で再現可能なCI gateとして利用できます。
- `tools/misfire.php`: 拡張が反応してはいけない位置での誤検出を検査。
  `--assert-clean --expect-sites=N --expect-probes=N`は誤爆、曖昧な選択、
  無応答、固定corpusの母集団変化を失敗として扱います。

定期回帰jobは、固定したBEAR.Kataのsource snapshotを、依存を導入せずapplicationも
実行せずに読み取ります。この測定は、ここに記述した人間可読な規約を補助する回帰oracleであり、
規約そのものの代替ではありません。
- `tools/references.php`: Resource参照集合の比較とDefinitionの往復検査
- `tools/latency.php`: Definitionのcold/warm latencyを測定
- `tools/verify-invariants.php`: end-to-endのLSP invariantを機械的に検査
- `tools/verify-kata-conventions.php`: BEAR.Kataで規約によるSchema到達件数を測定

これらは通常のunit test suiteからは実行されません。
