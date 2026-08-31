# suzumaze/bear-phpactor-extension

[English](README.md) | 日本語

BEAR.Sunday（PHPのフレームワーク）の命名・ディレクトリ規約を、PHPの言語サーバー[phpactor](https://github.com/phpactor/phpactor)に教えるComposerパッケージです。定義ジャンプと補完が、`app://self/user`という文字列がどのファイルを指すか、SQLファイルがどこに置かれるか、JSON Schemaがどう命名されるかを理解するようになります。

LSP（Language Server Protocol、エディタと言語サーバーをつなぐ規格）のプロトコル部分は一切実装していません。いくつかのロケータ（定義ジャンプの実処理）とコンプリータ（補完の実処理）を、phpactorの拡張コンテナに登録するだけです。

## 機能（v0.1）

| 機能 | 何が起きるか |
|---|---|
| リソースURI定義ジャンプ | `'app://self/user'`にカーソルを置く → `src/Resource/App/User.php`へ飛ぶ（psr-4を考慮） |
| リソースURI補完 | `'app://self/<カーソル>'` → プロジェクト内に実在するリソースクラスのURIを補完する |
| SQL定義ジャンプ | `#[DbQuery('point_distance')]`（Ray.MediaQuery）または`@Query("point_distance")`（Ray.QueryModule）にカーソルを置く → `var/db/sql/point_distance.sql`へ飛ぶ |
| JSON Schema定義ジャンプ（属性） | `#[JsonSchema('user.json')]`にカーソルを置く → `var/json_schema/user.json`へ飛ぶ。名前付き引数`params:`が付いていれば`var/json_validate/`配下を解決する |
| JSON Schema型定義ジャンプ（規約） | リソースクラスの宣言名にカーソルを置き「型定義へ移動」を実行 → `var/json_schema/<ケバブケース>.json`へ飛ぶ（例: `BodyTypeDemo` → `body-type-demo.json`、`Page\Admin\UserProfile` → `admin/user-profile.json`） |
| ルータージャンプ | `aura.route.php`の中の**ルート名**（`$map->route()` / `$map->get()` / `$map->post()`などの第1引数）にカーソルを置く → 対応するPageリソースクラスへ飛ぶ。文脈接頭辞を辿る（`'/article-redirector'`は`Page/Content/ArticleRedirector.php`を見つける）。大文字も保つ（`'/articleRedirector'` → `ArticleRedirector`。`Articleredirector`にはならない）。第2引数（URLパターン、例`'/blogs/{blogger}'`）は**意図的にジャンプの対象外**——HTTPのパスであってリソースのパスではなく、そこから飛ぶと間違ったクラスに着地する。`$map->attach()`も対象外——第1引数が名前の接頭辞であるため |
| リソース参照検索 | リソースURI文字列（`'app://self/article'`）またはリソースクラスの宣言名にカーソルを置く → そのリソースを参照している箇所を全部列挙する（`#[Link]`・`#[Embed]`・`$this->resource->get()`など） |

どのジャンプも純粋なパス・名前空間の対応づけで、PHPの型推論は一切使っていません。プロジェクトの根や名前空間の接頭辞は、対象プロジェクトの`composer.json`の`autoload.psr-4`から取ります。

## インストール

phpactor自体をプロジェクトにインストールする必要があります（この拡張はphpactorから読み込まれる側で、逆ではありません）。

```bash
composer require --dev phpactor/phpactor suzumaze/bear-phpactor-extension
vendor/bin/bear-phpactor-init
phpactor config:trust --trust
```

補足:

- phpactorは`dev-master`のパッケージに依存しているため、プロジェクト側にも`"minimum-stability": "dev"`（または`repositories`での上書き）が必要です。
- `phpactor config:trust --trust`はそのディレクトリを信頼済みとして記録します。信頼されていないディレクトリの`.phpactor.json`はphpactorに無視されます。
- このパッケージの`composer.json`にある`extra.phpactor.extension_class`キーは、エコシステムの慣習に合わせて残しているだけで、phpactorはもう読みません。実際に効く読み込み経路は`.phpactor.json`の`container.extension_classes`だけで、しかも言語サーバーにしか適用されません（CLIには効きません）。
- `phpactor/language-server-protocol`を`3.17.4`に固定してください（`composer require --dev phpactor/language-server-protocol:3.17.4`）。language-server 7.0.1とprotocol 3.17.5の組み合わせだと、`textDocument/didChange`がサーバーに届きません。つまり保存前の編集が無視され、保存するまですべての機能が`didOpen`時点のテキストに対して答え続けます——エラーは一切出ません。`bear-phpactor-init`はこの組み合わせを検知して標準エラー出力に警告します（コマンド自体は成功扱いのまま進みます）。この不具合は上流で修正済みで、`contentChanges`をオブジェクトとして読むよう直したプルリクエスト（[phpactor/language-server#68](https://github.com/phpactor/language-server/pull/68)）がありますが、まだリリースは切られていません。修正版のlanguage-serverが配布されたら、このバージョン固定は外してください。

## `.phpactor.json`がすべての拡張クラスを列挙している理由

phpactorの`container.extension_classes`というパラメータは、組込みの既定値に**追加するのではなく置き換える**動きをします。「自分の拡張を足す」という選択肢が無いため、この拡張を使うプロジェクトは、組込みの拡張クラスを全部と、この拡張の分を合わせて列挙する必要があります——執筆時点で69個です。

組込みのリストは`Phpactor::boot()`の中に直書きされた配列で、公開されたAPIはありません。そのため`vendor/bin/bear-phpactor-init`は、実行時にこれを取得します。具体的には、まっさらな一時ディレクトリの中で`phpactor config:dump --config-only`を実行し（プロジェクト側の設定が既定値を覆い隠さないようにするため）、解決済みのリストを`.phpactor.json`に書き出します。その際`Suzumaze\BearPhpactor\BearSundayExtension`を先頭に置きます。

**phpactorをアップグレードしたら、`vendor/bin/bear-phpactor-init`を再実行してください。** 再実行すると、新しい環境からリストが作り直されます。このコマンドは冪等（何度実行しても同じ結果になる）です。拡張リストの重複を除き、自分の拡張を先頭に保ち、既存の`.phpactor.json`の他のキーはすべて保存します。

これを省略すると、2通りの壊れ方をします。2つ目のほうが厄介です。

- アップグレードで**新しく追加された**拡張が、あなたのリストに無いままになり、その機能が使えません。何の報告もされません。
- リストの中のクラスが**もう存在しない**——上流かこのパッケージ側で名前が変わった・削除された場合です。この場合、言語サーバーは**まったく起動しません**。`PhpactorDispatcherFactory`が列挙された名前ごとに`new $class()`を呼ぶため、`initialize`に答える前に致命的エラー`Class "..." not found`でプロセスが落ちます。この拡張の機能だけでなく、PHPの言語機能すべてを失いますが、エディタ側には「サーバーがクラッシュした」としか見えず、設定の問題だとは分かりません。

実際に、有効な`.phpactor.json`に存在しないクラス名を1つ混ぜて確かめました。サーバーは起動中に落ち、生成されたファイルを使った同じプロジェクトは正常に応答しました。

### `config:trust`に潜む罠

`config:trust`は**プロジェクトディレクトリの中から**実行するか、絶対パスを渡してください。

```sh
cd /path/to/your-project && vendor/bin/phpactor config:trust --trust
```

`--working-dir`に**相対パス**を渡すと、その相対文字列がそのままphpactorの信頼記録（`~/.local/share/phpactor/trust.json`）に書き込まれ、以後二度と一致しなくなります。壊れ方は静かです——`.phpactor.json`が読まれなくなるので、この拡張が読み込まれず、すべての機能が何もしなくなります。ジャンプも補完もまったく反応しない場合は、このファイルに相対パスの記録が無いか確認してください。

## 定義ジャンプの挙動

この拡張は**先頭に**登録されているため、そのロケータはphpactor組込みのものより先に動きます。この鎖は「最初に答えた人が勝ち」という仕組みで、この順序こそが規約ジャンプを成立させています。

- **リソースクラスの宣言名**（例: `src/Resource/App/User.php`の`final class User`）にカーソルがある場合 → F12（定義へ移動）は組込みと同じ動きをします。「もうここにいます」という答えなので、その場に留まります。
- **クラス名の規約ジャンプは、代わりに「型定義へ移動」にあります。** 右クリック →「型定義へ移動」（既定のキーバインドなし）を、リソースクラスの宣言名の上で実行すると`var/json_schema/user.json`へ飛びます。リソースのbodyの形を決めるのはJSON Schemaなので、「このリソースの型はどこか」という問いに答えるのは自然な役割です。
- **なぜF12ではないのか?** 以前は、この規約ジャンプがクラス宣言名の上のF12（定義へ移動）を上書きしていました。Shiftの押し忘れ（参照検索のつもりの⇧F12が、F12になってしまう）が起きると、JSONファイルに着地してしまい、「参照検索が壊れている」ように見えました——キー1つの違いが不具合に見えたのです。既定のキーバインドを持たない機能へジャンプを移すことで、この衝突を無くしました。
- **それ以外はすべて影響を受けません。** `new User()`のような利用箇所はクラス宣言ではないので規約ジャンプは発火せず、組込みのロケータが通常どおり処理します。普通のPHPの定義ジャンプ（変数・メソッド・引数・リソースでないクラス）にも影響はありません。

## エディタの設定

この拡張はphpactorの言語サーバーの中に読み込まれるため、LSPクライアントを持つエディタなら何でも使えます。クライアントには**対象プロジェクトの**`vendor/bin/phpactor`——このパッケージをオートロードできる側——を向けてください。どこか別の場所にあるphpactorではありません。

### VS Code

公式クライアントを入れ、どのバイナリを動かすかを指定します。

```sh
code --install-extension phpactor.vscode-phpactor
```

プロジェクトの`.vscode/settings.json`:

```json
{
    "phpactor.path": "vendor/bin/phpactor"
}
```

クライアントは自前のphpactorを同梱していますが、その同梱版はこのパッケージをオートロードできません。`phpactor.path`を設定することが、この拡張が動くか、黙って何もしないかの分かれ目です。

**フォルダを信頼してください。** VS Codeは見慣れないフォルダを制限モードで開き、そこでは言語サーバーがまったく起動しません——実際にプロジェクトを開いて、phpactorのプロセスが存在しないことを確認しています。エラーは何も出ません。機能が単に無いだけです。信頼の確認ダイアログを承認するか、コマンドパレットから「ワークスペースの信頼を管理」を使ってください。

この2つのうち、「入れたのに何も起きない」の原因はだいたいこの2つのどちらかです。まずは制限モードを疑ってください——確認にクリック1回で済みます。

同じ設定ファイルの`phpactor.config`は、この拡張の読み込みには**効きません**。サーバーはクライアントの初期化オプションを取り込む前に`container.extension_classes`を読むため、`.phpactor.json`だけが唯一の経路のままです。

### Neovim

```lua
vim.lsp.start({
  name = 'phpactor',
  cmd = { 'vendor/bin/phpactor', 'language-server' },
  root_dir = vim.fs.dirname(vim.fs.find({ 'composer.json' }, { upward = true })[1]),
})
```

### 曖昧なジャンプはどう見えるか

1つのURIが複数のクラスを指す場合（上記の文脈接頭辞のケース）、サーバーは場所の一覧を返しません。代わりにエディタへ選択肢の表示を依頼し、答えを待ちます。

```
Goto type
  ▸ MyVendor\MyProject\Resource\Page\Admin\Error400
  ▸ MyVendor\MyProject\Resource\Page\Content\Error400
```

どれかを選ぶとそのクラスへ飛びます。候補は完全修飾名で、ディレクトリの並び順に列挙されます。

参照検索（`textDocument/references`）も同じ解決方法・同じ規則を使います。URIが2つ以上のクラスを指す箇所は「解決できない」扱いになり、参照としては数えません。この2つの機能を1つの判定基準に揃えることで、説明も実装も二重にせずに済んでいます。

参照検索が見つけるのは、カーソル位置のファイルと**同じファイルに解決される**箇所すべてです——同じURI文字列を持つ箇所すべて、ではありません。2つのミニアプリが両方とも`'app://self/article'`を使うことがありますが、それぞれの文字列はその文字列自身があるファイルの位置から解決されるため、問い合わせたファイルを指す場合にだけ参照として報告されます。

## 実アプリのどれだけをカバーしているか測る

フィクスチャが示すのは「機能が1回発火する」ことだけで、実アプリのどれだけの割合に届くかは分かりません。`tools/coverage.php`はそれに答えます——対象プロジェクトの全PHPファイルを解析し、この拡張が答えるはずの箇所（リソースURI・クエリ名・ルートパス・リソースクラスの宣言）を全部集め、本物の言語サーバーに1件ずつ定義を問い合わせ、命中率と、外した箇所全部をファイルと行番号付きで報告します。

```sh
cd /path/to/your-app && php /path/to/bear-phpactor-extension/bin/bear-phpactor-init
cd /path/to/your-app && vendor/bin/phpactor config:trust --trust
php /path/to/bear-phpactor-extension/tools/coverage.php /path/to/your-app
```

276個のリソースを持つ本番アプリケーションで測定: **550箇所中544箇所、99%**。残りの外れは実行時に組み立てられるURI（`'page://self/content/' . $slug`）で、どのクラスも指さないのが正しい答えです。

2つ、注意点があります。この道具が測るのは**届いているかどうかであって、正しいかどうかではありません**——答えが返ったかどうかを数えるだけで、それが正しいファイルかどうかは見ていません。そして**誤爆**（飛ぶべきでない場所で飛んでしまうこと）も測っていません。

## 既知の限界

- **ジャンプ先はファイルの先頭（0,0）に着地します。** クラス名の位置を計算しているのはルーターのロケータだけで、残り3つのロケータはファイルの1行目を返します。見た目だけの問題ですが、エディタ上では見えます。
- **リソースクラスを走査する正規表現が、誤検出することがあります。** `Project::resourcePhpFiles()`は、テキストに`extends ... ResourceObject`を含むファイルにマッチします。docblockの説明文や、`MyResourceObject`を継承するクラスもマッチしてしまい、URI補完の候補が水増しされます。
- **参照検索はディスクからしかファイルを読まず、しかもpsr-4のディレクトリの中だけです。** 保存していない`#[Link]`は結果に出てきませんし、`autoload`・`autoload-dev`のpsr-4の根の外にあるファイル（`bin/*.php`や`public/index.php`など）の箇所も出てきません。BEAR.Kataで測定したところ、単純なテキスト検索が見つける箇所のうち16件が`bin/`にありました。定義ジャンプはこの影響を受けません——エディタが送ってくるバッファから動作するためです。
- **Windowsの絶対パス判定が、psr-4の解決では不完全です。** `/`で始まるパスは絶対パス扱いにしていますが、ドライブレター付きのパス（`C:/src`）は`PathGuard`側では扱っていても、psr-4のディレクトリ解決側では扱っていません。
- **`includeDeclaration: true`付きの「すべての参照を検索」は、クラス自身を宣言として列挙します。** リソースクラスの宣言名の上では、定義の鎖がもう`var/json_schema/<name>.json`には解決されません（規約ジャンプは「型定義へ移動」に移したため）。そのため組込みのロケータが答え、VS Codeの「すべての参照を検索」は、実際の参照箇所より前に、宣言の位置としてクラス自身を表示します。

## 開発

```bash
vendor/bin/phpunit
vendor/bin/phpcs
vendor/bin/phpstan analyse
```
