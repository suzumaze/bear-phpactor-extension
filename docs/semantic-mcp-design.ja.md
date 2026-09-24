# BEAR.Sunday Semantic MCP 設計書

Status: Draft (LSP phase implemented; MCP phase deferred)

対象: `suzumaze/bear-phpactor-extension` と、将来作成する独立した MCP server package

想定読者: 実装を担当するエージェント、レビュー担当者

> **現在地（2026-09-14）:** 本書のSemantic Core基盤は実装済みで、標準LSPと
> read-only `bear/*` custom requestとして公開されている。現在の公開契約と実装状況は
> [`lsp-semantic-requests.md`](lsp-semantic-requests.md)と
> [`semantic-lsp-progress.ja.md`](semantic-lsp-progress.ja.md)を正とする。将来のMCPは
> Phpactor stdioへ接続する薄いadapterとし、本書中の同一processでcoreを直接呼ぶ案や
> 未完了を前提とした実装順序は、当初案を残した非規範的な記録として扱う。

## 1. 目的

BEAR.Sunday 固有の関係を、特定 IDE に依存しない読み取り専用の semantic facts として提供する。

エディタ向けの標準 LSP 機能は、引き続き Phpactor が提供する。MCP は LSP を置き換えず、同じ BEAR.Sunday semantic core を別の transport から利用する。

```text
VS Code / Neovim / Emacs
        ↓ standard LSP
Phpactor LSP adapter
        ↓
BEAR.Sunday semantic core
        ↑
standalone MCP adapter
        ↑ stdio MCP
Codex / Claude / other MCP clients
```

成功条件は、エディタの定義ジャンプと MCP の回答が同じ規約・同じ曖昧性判定を使うことである。
読み取り境界だけはadapterの役割に応じて異なる。MCPはworkspace内に限定し、エディタはComposerが
`installed.json`に記録したImportAppパッケージrootを追加の信頼済み定義ジャンプ先として扱える。

## 2. 背景と prior art

### 2.1 調査基準

参考にする主な設計は、`idea-php-bearsunday-plugin` の以下の提案と実装である。

- [Issue #28: ALPS is meaning. ApiDoc is contract. IDE is evidence and action.](https://github.com/bearsunday/idea-php-bearsunday-plugin/issues/28)
- [PR #47: Add read-only semantic MCP toolset](https://github.com/bearsunday/idea-php-bearsunday-plugin/pull/47)
- [PR #50: Resource attribute index](https://github.com/bearsunday/idea-php-bearsunday-plugin/pull/50)
- [PR #51: DI binding lookup](https://github.com/bearsunday/idea-php-bearsunday-plugin/pull/51)
- [PR #52: DI module tree](https://github.com/bearsunday/idea-php-bearsunday-plugin/pull/52)
- [PR #53: Resource URI completion/goto parity](https://github.com/bearsunday/idea-php-bearsunday-plugin/pull/53)
- [PR #55: Union schema property names](https://github.com/bearsunday/idea-php-bearsunday-plugin/pull/55)

この設計書の再監査基準は、2026-09-07の`upstream/master`、commit `7b59555964834d3ae39269fbe08a882b08abfe43`である。最初に参照したcommit `7344d3d1a9a7e2e8043881d6ffb38ef50a492c12`はPR #47の最終headであり、PR内容の確認には正しかったが、merge後に追加された機能を含んでいなかった。

現在のJetBrains版は、次の16個のread-only MCP toolを持つ。

- Resource: `bear_resource_describe`、`bear_resource_body_shape`、`bear_resource_attribute_index`
- Application/DI/AOP: `bear_app_context_list`、`bear_di_binding_lookup`、`bear_di_module_tree_read`、`bear_di_object_graph`、`bear_aop_pointcut_lookup`
- Contract: `bear_schema_lookup`、`bear_apidoc_operation_lookup`、`bear_contract_compare`
- ALPS: `bear_alps_profile_read`、`bear_alps_descriptor_lookup`、`bear_alps_transition_lookup`、`bear_alps_links_resolve`、`bear_alps_links_suggest`

### 2.2 採用する設計

PR #47と、その後の`master`から採用する考え方:

- MCP tool class は薄い adapter にする。
- 実処理は transport 非依存の facts service に置く。
- 全ツールを read-only にする。
- 成功・未発見・曖昧・解析失敗などを構造化した status で返す。
- 回答には provenance を付け、何を根拠にしたかを示す。
- 複数の証拠を比較する場合、事実と推測を区別する。
- 入力パス、XML、巨大な出力、index 未準備を安全に扱う。
- 解決できないstatic expressionや未対応constructを黙って捨てず、`unresolved`と理由を返す。
- application contextをDI/AOP問い合わせの入口にし、実際のbootstrap引数から候補を得る。
- DI binding、module tree、object graph、AOP pointcutはapplicationをbootせず、source/indexから静的に答える。
- diagramはfactsと別ロジックで再計算せず、同じfactsから生成する。
- Resource attributeは短い文字列一致ではなく、`use`を解決したFQNとして扱う。
- Resource URIは非`self` authority、`.`/`..` segment、非concrete classを拒否する。

### 2.3 直接持ち込まないもの

JetBrains版から直接持ち込まないもの:

- JetBrains MCP Server extension point
- PSI、VFS、ReadAction、IDE index
- IDE 内の未保存文書へ直接アクセスする仕組み
- Kotlin/Java の実装コード

先行実装は仕様と失敗事例の参考に留め、本プロジェクトでは Phpactor と PHP の実装モデルに合わせて独立に実装する。

### 2.4 最新JetBrains版にも残る差分

次は`upstream/master`を再監査した上でも、本設計の対象として残る。

- Twig/QiqのResource↔templateおよび`#[Embed]` navigationはIDE機能として存在するが、MCP toolとしては公開されていない。
- SQL、Router navigationもMCP toolとしては公開されていない。
- `bear_app_context_list`は存在するが、Resource一覧、artifact全体のinventory、project health/capability一覧はない。
- MCP Resource resolverは`app://self`と`page://self`だけを受け付け、Composer dependencyとしてimportされたapp authorityを解決しない。
- editorのResource gotoは`ResourceIndex.getFileByUri()`、MCP factsは`ResourceClassResolver.resolveCached()`を使っており、両transportが一つのresolverを共有する構造にはまだなっていない。
- MCP serverはPhpStorm 2025.2以降に同梱されたserverへ接続する方式であり、IDEなしのheadless processや他editorから直接起動できるstdio serverではない。
- Qiq editor navigationは現在も`{{= $this->name }}`を前提としている。Qiq 3のbare variable構文を対応済みとは扱わない。

したがってJetBrains版を「機能が不足した旧実装」とは評価しない。現在はDI/AOPを含む強いprior artであり、本設計の差別化はPhpactor/LSPとのsemantic core共有、headless運用、import app対応、Twig/Qiq・SQL・Router factsに置く。

## 3. 非目標

初期リリースでは以下を実装しない。

- ファイルの作成、編集、削除
- BEAR.Resource の実行や HTTP request
- テンプレートの render
- 任意 PHP、Composer script、shell command の実行
- DI container や application bootstrap の実行
- ALPS、JSON Schema、OpenAPI の自動修正
- VS Code や IDEA の操作
- 曖昧な候補の自動選択
- Phpactor の一般的な PHP 機能を MCP にすべて再公開すること

AI agent は facts を受け取り、自身の通常の編集手段で review 可能な diff を作る。MCP server は diff の author にならない。

## 4. アーキテクチャ上の決定

### 4.1 Semantic core を `bear-phpactor-extension` に置く

BEAR.Sunday の規約と意味は、この package の責務である。MCP server 側に Resource URI、Twig/Qiq、SQL、Router、ALPS、JSON Schema の解決処理を再実装しない。

既存の Phpactor locator/completor は adapter とし、最終的には semantic service に処理を委譲する。

```text
lib/Semantic/
  Model/
  Result/
  Service/
  Workspace/

lib/*/*DefinitionLocator.php
  └ Semantic service を呼び、結果を Phpactor TypeLocations へ変換
```

### 4.2 MCP server は別 package にする

推奨 repository/package 名:

```text
suzumaze/bear-sunday-mcp-server
```

MCP transport、tool schema、Composer compatibility manifestと、位置ベースLSP bridgeを追加する場合のPhpactor process lifecycleはMCP packageの責務にする。`bear-phpactor-extension` 自体には MCP SDK を依存させない。

初期 transport は stdio のみとする。SSE/HTTP、認証、常駐 daemon は初期スコープ外とする。

高水準の`bear_*` toolは、MCP serverと同じPHP process内でsemantic serviceを直接呼ぶ。架空のPHP文書やcursor位置を生成してLSPへ問い合わせる実装にはしない。位置ベースのLSP bridgeは補助機能として分離し、M1の必須要件に含めない。

### 4.3 LSP と MCP の関係

LSP 側は、今後も以下の標準 method を提供する。

- `textDocument/definition`
- `textDocument/typeDefinition`
- `textDocument/references`
- `textDocument/completion`
- `textDocument/documentLink`

MCP の高水準ツールを、標準 LSP method であるかのように偽装しない。必要になった場合でも、`bear/*` custom LSP method を先に増やすのではなく、transport 非依存の semantic service を追加する。

位置ベースの MCP tool を試作する場合は、MCP adapter が本物の Phpactor process を起動し、標準 LSP request を送る。この経路は動作確認用または補助機能とし、BEAR 固有ロジックの複製には使わない。

## 5. 現在のコアに不足しているもの

### 5.1 Workspace を明示する context

現在の `Project::locate()` と `ProjectLocator::locate()` は、入力ファイルから上方向へ `composer.json` を探索する。エディタが開いたファイルを起点にする LSP では自然だが、MCP tool の文字列引数をそのまま渡す API としては境界が弱い。

追加する概念:

```php
WorkspaceContext
  canonicalRoot: string
  project: Project
  accessPolicy: WorkspaceAccessPolicy
```

必須 API:

```php
WorkspaceContext::fromRoot(string $root): SemanticResult
WorkspaceContext::contains(string $path): bool
WorkspaceContext::resolveExisting(string $relativePath): SemanticResult
Project::locateWithin(string $filePath, string $workspaceRoot): ?Project
Project::fromRoot(string $workspaceRoot): ?Project
```

MCP server 起動時に workspace root を一度 canonicalize し、全 tool call で同じ context を使う。

### 5.2 `null` ではなく構造化された解決結果

現在の `ResourceTargetResolver::resolve()` は、未発見と曖昧をともに `null` で返す。LSP の first-match chain には適しているが、agent は再試行すべきか、入力を直すべきか、候補をユーザーへ示すべきか判断できない。

追加する wire 非依存モデル:

```php
enum SemanticStatus: string
{
    case Ok = 'ok';
    case NotFound = 'not_found';
    case Ambiguous = 'ambiguous';
    case InvalidInput = 'invalid_input';
    case Unsupported = 'unsupported';
    case ParseError = 'parse_error';
    case EngineUnavailable = 'engine_unavailable';
    case OutsideWorkspace = 'outside_workspace';
    case Timeout = 'timeout';
}

final readonly class SemanticResult
{
    public SemanticStatus $status;
    public mixed $value;
    public array $candidates;
    public ?SemanticError $error;
    public array $provenance;
}
```

既存 LSP adapter は次のように変換する。

| Semantic status | LSP adapter |
|---|---|
| `ok` | `Location` / `TypeLocation`を返す |
| `not_found` | 空結果または`CouldNotLocateDefinition` |
| `ambiguous` | 空結果。勝手に選ばない |
| `invalid_input` | 空結果 |
| `unsupported` | chainの次へ渡す |
| `parse_error` | 空結果。serverを落とさない |
| `outside_workspace` | 空結果 |

既存の `resolve(): ?array` は一度に削除せず、`resolveDetailed()` を追加して旧 method を adapter として残す。LSP の回帰テストが通った後に内部呼び出しを段階的に移行する。

### 5.3 Provenance

MCP の成功回答には、根拠を配列で付ける。

```json
{
  "source": "file",
  "path": "src/Resource/App/User.php",
  "range": {"start": {"line": 10, "character": 4}},
  "freshness": "saved"
}
```

規則:

- path は原則 workspace 相対パスにする。
- 絶対パスは diagnostic log のみに留め、通常 payload には出さない。
- 初期 MCP server はディスクを読むため `freshness: saved` のみ返す。
- 将来、LSP workspace の buffer を根拠にした場合だけ `freshness: buffer` を返す。
- 複数ファイルから導出した回答は `source: derived` とし、各入力を provenance 配列へ含める。
- freshness を確認できない場合に `unsaved` と推測しない。

### 5.4 Project inventory と関係グラフ

現在は `Project::resourceClasses()` が Resource URI と FQN の一覧を返すが、BEAR artifact 全体の inventory はない。

JetBrains版には`bear_app_context_list`があるため、context inventory自体は新規性ではない。一方、Resource一覧とproject health/capabilityをまとめて返すinventoryは確認できず、headless MCPがworkspaceを説明する入口として引き続き必要である。

追加する read-only service:

```text
ProjectInventoryService
  resources()
  templates()
  schemas()
  queries()
  routes()
  alpsProfiles()

ResourceFactsService
  describe()
  outgoingRelations()
  incomingRelations()
```

関係は最低限、次の共通 DTO に正規化する。

```json
{
  "kind": "embed",
  "rel": "user",
  "sourceUri": "app://self/dashboard",
  "sourceMethod": "onGet",
  "targetUri": "app://self/user",
  "targetMethod": "onGet",
  "provenance": []
}
```

`Link` と `Embed` を文字列が似ているという理由で結びつけない。属性の FQN、static string argument、明示された `rel` / `src` / `href` のみを使う。

### 5.5 繰り返し問い合わせ向けの cache

現在の Resource reference search は request ごとに PSR-4 配下を走査する。MCP agent は短時間に複数 tool を連続実行するため、同じ解析を繰り返さない設計が必要である。

初期実装では複雑な常駐 index を作らず、次の request-scope / process-scope cache を使う。

- canonical path と `filemtime`、file size を cache key にする。
- PHP parse result、JSON decode result、ALPS normalized modelを再利用する。
- Composer metadata と Resource inventory を workspace 単位で保持する。
- キャッシュから返しても provenance の freshness は変えない。
- ファイル変更を検知したら、そのファイルに依存する entry だけ無効化する。
- cache が古い可能性がある場合に、存在しないものを `not_found` と断定しない。

性能最適化は測定後に行う。M0では正しい invalidation と上限を優先する。

## 6. 共通レスポンス envelope

すべての MCP tool は JSON object を返す。成功時と失敗時で型を大きく変えない。

成功例:

```json
{
  "status": "ok",
  "data": {},
  "provenance": [
    {
      "source": "file",
      "path": "src/Resource/App/User.php",
      "freshness": "saved"
    }
  ],
  "meta": {
    "truncated": false
  }
}
```

曖昧例:

```json
{
  "status": "ambiguous",
  "data": null,
  "candidates": [
    {"path": "src/Resource/Page/Admin/User.php"},
    {"path": "src/Resource/Page/Content/User.php"}
  ],
  "error": {
    "code": "resource_target_ambiguous",
    "message": "Two Page resources match page://self/user."
  },
  "provenance": [],
  "meta": {
    "truncated": false
  }
}
```

契約上の規則:

- `status` は常に存在する。
- `data` は成功時に存在する。
- `error.code` は機械判定可能な安定した snake_case とする。
- `error.message` は人間向けで、例外の stack trace を含めない。
- 配列は必ず決定的に sort する。
- 同一候補は canonical path で重複除去する。
- 空配列と未取得を区別する。未取得は `available: false` と理由を返す。
- 一部だけ取得できた複合回答は全体を失敗にせず、各 section に availability を持たせる。

## 7. MCP tool surface

### M0: Core groundwork

MCP serverを公開せず、既存機能を壊さずに semantic core を作る。

1. `WorkspaceContext` と canonical containment
2. `SemanticStatus`、`SemanticResult`、`Provenance`
3. Resource URI の詳細解決
4. 既存 `ResourceDefinitionLocator` と reference search を新 service へ委譲
5. 既存全テストと実 LSP request の回帰テスト

### M1: Read-only MCP minimum

#### `bear_project_info`

入力:

```json
{}
```

出力:

- workspace basename
- `composer.json`の検出状態
- PSR-4 roots
- Resource件数
- 利用可能なfacts capability
- core、Phpactor、protocol のversion
- 既知の互換性問題

このtoolを疎通確認兼health checkとし、専用の`ping`は作らない。

#### `bear_resource_list`

入力:

```json
{
  "scheme": "app",
  "prefix": "user",
  "limit": 50
}
```

出力:

- URI
- class FQN
- workspace相対ファイルパス

同じURIが複数のapp rootで異なる対象を持つ場合、黙って先頭を返さず候補を区別する。

#### `bear_resource_describe`

入力:

```json
{
  "uri": "app://self/user",
  "contextPath": "src/Resource/App/Dashboard.php"
}
```

`contextPath`はworkspace相対で、省略時に一意に決まる場合だけ解決する。`self`の意味が複数app rootで変わる場合は`ambiguous`を返す。

出力:

- normalized URI
- class FQN と path
- public `on*` method の名前と parameter
- `Link` / `Embed` の outgoing relations
- 一意に得られる場合の incoming relations
- 対応するSchema/Templateのpath

属性の生テキスト全体は初期版では返さない。必要なFQNとstatic argumentだけを構造化する。

#### `bear_schema_lookup`

入力:

```json
{
  "resourceUri": "app://self/user",
  "method": "get",
  "kind": "response",
  "includeRaw": false
}
```

`kind`は`response`または`request`。出力はpath、source、property names、required、parse errorを含む。`includeRaw`のdefaultは`false`とし、raw JSONにはsize上限を適用する。

### M2: Existing navigation facts

#### `bear_template_lookup`

二つのmodeを1toolへ混ぜず、以下に分ける。

- `bear_template_for_resource(resourceUri, engine, contextPath?)`
- `bear_template_reference_at(path, line, character)`

対応構文はREADMEに記載済みのTwig/Qiq構文だけとする。custom loaderを推測しない。

位置指定はLSPと同じ0-basedの`line`とUTF-16 code unit単位の`character`に統一し、tool descriptionへ明記する。byte offsetへの変換はcoreの共通converterだけで行い、各toolで独自実装しない。

#### `bear_sql_lookup`

query nameまたはPHP上の位置から`var/db/sql`の対象を返す。SQL本文はdefaultで返さない。

#### `bear_route_lookup`

route nameからPage Resourceを返す。HTTP pathをResource URIとして推測しない。

#### `bear_alps_descriptor_lookup`

初期段階では現在の`apidoc.xml`が明示するJSON ALPS profileだけを扱う。nested descriptor、XML profile、profile auto detectionは、共通normalizer導入後に対応する。

### M3: Semantic comparison

#### `bear_contract_compare`

以下のfield nameをpresence-onlyで比較する。

- response JSON Schema
- ALPS descriptor
- 静的に確認できるResource body shape

少なくとも2面が取得できた場合だけ`onlyInSchema`等を返す。型や意味の一致は主張しない。

Resource body shapeは、直線的なmethod内のliteral-key `$this->body`完全代入に限定して実装済み。
条件分岐やdynamic assignmentをunionまたは推測で平らにせず、理由付き`unsupported`に残す。
branchごとのshape表現はM5の独立課題とする。

### M4: DI and AOP facts

JetBrains版の現在の実装を仕様・失敗事例のprior artとして、次を独立milestoneで設計する。

- `bear_app_context_list`
- `bear_di_binding_lookup`
- `bear_di_module_tree_read`
- `bear_di_object_graph`
- `bear_aop_pointcut_lookup`
- `bear_resource_attribute_index`

applicationやDI containerを実行せず、PHP sourceとComposer metadataから静的に答える。dynamic binding、読めないqualifier、未対応matcher、module installの未解決部分は欠落させず、理由付きの`unresolved`として返す。

DIの優先順位、`override()`、framework module、provider、multibinding、assisted injectionは誤ると回答全体を誤認させる。M0/M1へ含めず、fixtureとRay.Diの実際の規則を確認する専用設計・専用PRを必要とする。

### M5: Deferred contract facts

- JSON/XML共通ALPS normalizer
- ALPS transitionと`Link`/`Embed`実装の照合
- OpenAPI / ApiDoc operation lookup
- ALPS link resolution / suggestion
- body shapeのunion branch表現
- project graphのfiltered export

## 8. Security requirements

### 8.1 Workspace境界

- MCP server起動時に受け取ったworkspace rootをcanonicalizeする。
- toolのpath引数はworkspace相対を原則とする。
- 絶対パス、NUL byte、親segment、解決後にworkspace外へ出るsymlinkを拒否する。
- `..`という部分文字列ではなくpath segmentとして判定する。`schema..v2.json`は正当な名前として扱う。
- 読み取り前と結果返却前の両方でcanonical containmentを確認する。
- workspace外入力は`outside_workspace`を返し、存在の有無や内容を漏らさない。

### 8.2 Composer dependency

read-only Semantic API / MCPでは、canonical workspace root内の`vendor/`だけを読み取り対象にする。
Composer path repositoryやsymlinkによりdependencyがworkspace外へ出る場合は`outside_workspace`とし、
存在の有無や内容を返さない。

エディタの定義ジャンプでは、Composer metadataから明示的に得たImportAppパッケージrootだけを
追加のallowlistとして扱う。任意の外部path、通常のResource symlink、caller入力から到達した
workspace外pathは許可しない。

### 8.3 Parser

- PHPコードを実行せず、static parserだけを使う。
- XMLでDOCTYPEと外部entityを禁止する。
- XMLのsecure processingを有効にする。
- JSON/XMLの入力sizeとdescriptor depthに上限を設ける。
- malformed inputは`parse_error`として返す。
- `simplexml_load_file()`のdefault挙動へ安全性を依存させない。

### 8.4 Output bounds

初期値:

- 一覧のdefault limit: 50
- 一覧のmaximum limit: 200
- raw artifact maximum: 256 KiB
- tool response目標: 1 MiB未満
- 超過時は切り捨て、`meta.truncated: true`と件数を返す

巨大なproject graphや全Schema本文をfilterなしで返すtoolは作らない。

### 8.5 Side effects

MCP server processは以下のAPIを持たない。

- write file
- execute command
- invoke resource
- render template
- install/update package
- network fetch

setupと更新はVS Code setup extensionまたはユーザーの明示的なComposer操作に任せる。

## 9. Determinism and ambiguity

- pathはworkspace相対pathのbyte orderでsortする。
- URIはnormalized URIでsortする。
- relationは`sourceUri`, `targetUri`, `rel`, `kind`, `sourcePath`, `offset`の順でsortする。
- 複数候補を返す順番をfilesystem traversal順に依存させない。
- 同じ`rel`が異なるURIを指す場合は`ambiguous`。
- 複数のschema/profileが該当する場合、規約に明示された優先順位が無ければ`ambiguous`。
- 一意性の根拠が無いfirst matchを採用しない。

## 10. Freshness model

JetBrains版はIDE documentを読めるため、saved/unsavedを区別できる。standalone MCP serverはIDEとbufferを共有しないため、初期版はディスク上の内容だけを根拠にする。

```text
saved   = ディスクから読んだ
buffer  = LSP Workspaceが保持するdidOpen/didChange内容から読んだ
derived = 複数のsaved/buffer事実から導出した
```

M1では`saved`と`derived`のみ実装する。`buffer`は、MCP serverがPhpactorの同一LSP sessionを所有し、document lifecycleを正しく管理できる段階まで実装しない。

別プロセスのVS Code Phpactorが持つ未保存内容を読めると主張しない。

## 11. Test strategy

### 11.1 Core unit tests

- 各semantic serviceの成功、未発見、曖昧、不正入力
- workspace外の絶対パス
- `../`と多段traversal
- workspace外を指すsymlink
- `schema..v2.json`のような正当な名前
- malformed PHP/JSON/XML
- deterministic ordering
- duplicate candidate normalization
- output limitとtruncation metadata

### 11.2 LSP regression tests

既存 locator をsemantic serviceへ委譲した後も、実際の以下のrequestを通す。

- `textDocument/definition`
- `textDocument/typeDefinition`
- `textDocument/references`
- `textDocument/completion`
- `textDocument/documentLink`

Resource URI、SQL、Router、JSON Schema、ALPS、Twig、Qiqの既存テストを変更理由なく書き換えない。

### 11.3 MCP contract tests

- tool nameとinput schemaのsnapshot
- 全responseに`status`がある
- 成功時に`data`と`provenance`がある
- failure時に安定した`error.code`がある
- pathがworkspace相対
- 配列順が複数実行で同じ
- read-only toolしか登録されない

### 11.4 End-to-end

fixture workspaceに対してstdio MCP serverを起動し、最低限以下を確認する。

1. initialize
2. tools/list
3. `bear_project_info`
4. `bear_resource_describe`
5. `bear_schema_lookup`
6. shutdown

加えて、本物の`phpactor language-server`を使う既存のcoverage/invariant検証を維持する。

## 12. Implementation sequence

実装担当は、以下の順番を変更しない。

1. M0のDTOとworkspace境界だけを追加する。
2. Resource解決を`resolveDetailed()`へ移し、旧LSP挙動を完全に維持する。
3. 全既存テスト、PHPCS、PHPStanを通す。
4. `ResourceFactsService`を追加する。
5. `SchemaFactsService`を追加する。
6. core releaseを作る。
7. 別repositoryでstdio MCP adapterを作る。
8. fixtureを使ったMCP end-to-end testを追加する。
9. 実projectでresponse sizeとlatencyを測定する。
10. M2以降はtoolまたは密接なfacts群単位のPRに分ける。

M0とM1を1つの巨大なPRにしない。既存LSPのbehavior-preserving refactorと、新しいMCP surfaceを別々にreview可能にする。

## 13. Files expected to change in M0

追加候補:

```text
lib/Semantic/Result/SemanticStatus.php
lib/Semantic/Result/SemanticResult.php
lib/Semantic/Result/SemanticError.php
lib/Semantic/Result/Provenance.php
lib/Semantic/Workspace/WorkspaceContext.php
lib/Semantic/Workspace/WorkspaceAccessPolicy.php
lib/Semantic/Resource/ResourceResolution.php
lib/Semantic/Resource/ResourceResolver.php
tests/Unit/Semantic/...
```

変更候補:

```text
lib/Resource/Model/Project.php
lib/Resource/Model/ResourceTargetResolver.php
lib/Resource/ReferenceFinder/ResourceDefinitionLocator.php
lib/Resource/ReferenceFinder/ResourceReferenceFinder.php
lib/Util/PathGuard.php
lib/BearSundayExtension.php
README.md
README.ja.md
```

M0ではTwig/Qiq parser、ALPS parser、Router、SQLの対応構文を増やさない。

## 14. Acceptance criteria for M0

- 既存LSPの外部挙動が変わらない。
- Resource解決の`ok`、`not_found`、`ambiguous`、`outside_workspace`を区別できる。
- workspace外source/targetをsemantic APIが拒否する。
- symlink escapeを拒否する。
- ambiguous candidateが決定的な順序で返る。
- locatorはsemantic resultをLSPの空結果またはLocationへ変換するだけになる。
- Composer dependency versionを不用意に更新しない。
- `composer check`が成功する。
- 実際のPhpactor LSP requestを通す既存テストが成功する。

## 15. Acceptance criteria for MCP M1

- MCP serverはIDEなしで起動できる。
- transportはstdioのみ。
- workspace rootは起動時に固定される。
- `bear_project_info`、`bear_resource_list`、`bear_resource_describe`、`bear_schema_lookup`を提供する。
- tools/listにwrite/execute/network toolが存在しない。
- すべてのresponseが共通envelopeに従う。
- workspace外の内容を返さない。
- PHPやBEAR applicationを実行しない。
- 同じfixtureへの同じ問い合わせはbyte-identicalなJSONを返す。
- malformed inputでprocessが終了しない。
- READMEにIDEA版prior artとアーキテクチャの違いを明記する。

## 16. Agent handoff instructions

実装担当エージェントは作業開始前に次を行う。

1. repository全体、README、composer.json、lib、tests、docsを読む。
2. `AGENTS.md`の有無を確認する。
3. `composer check`のbaselineを取得する。
4. `ResourceTargetResolver`、`Project`、`ProjectLocator`、`PathGuard`、既存LSP integration testを読む。
5. behavior-preservingなM0計画を提示する。

禁止事項:

- `idea-php-bearsunday-plugin`のコードを直接コピーしない。
- BEAR.Sunday本体やJetBrains版repositoryを変更しない。
- MCP SDKを`bear-phpactor-extension`へ追加しない。
- 既存LSP locatorを削除してから置き換えない。
- 曖昧候補をfirst matchで解決しない。
- security testを後回しにしない。
- version bump、tag、release、PR作成を明示依頼なしに行わない。

実装報告には以下を含める。

- 変更ファイル
- semantic resultのstatus対応表
- LSP behaviorを維持した方法
- workspace境界とsymlink判断
- テスト結果
- 未対応toolと次のmilestone

## 17. Open decisions

実装前にmaintainerが最終確認する事項:

1. 新repository名を`bear-sunday-mcp-server`とするか。
2. M1で`bear_resource_describe`のincoming relationまで含めるか、M2へ送るか。
3. workspace外のComposer path dependencyを常に拒否するか、明示allowlistを導入するか。
4. Schemaのraw内容をM1で許可するか。推奨defaultは`includeRaw: false`。
5. ALPS JSON/XML normalizerをM2とM5のどちらへ置くか。推奨はM5で、現在のJSON navigationを先にfacts化する。
6. DI/AOP factsを同じcore packageへ置くか、追加Composer packageへ分離するか。どちらでもM0/M1のrelease条件にはしない。

上記以外は本設計書のdefaultに従い、M0実装中にscopeを拡大しない。
