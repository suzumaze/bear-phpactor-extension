# BEAR semantic LSP requests

Position-based navigation continues to use the standard LSP methods
`textDocument/definition`, `textDocument/references`, `textDocument/hover`,
`textDocument/completion`, and `textDocument/documentLink`.

The requests below are read-only custom requests for queries that start with a BEAR
identifier instead of a text-document position. A client should not create a fake
document merely to call a standard positional method. These methods are not a
replacement for standard LSP navigation.

## Contract evolution and discovery

The public `bear/*` custom requests form the `bear-semantic` protocol. Clients enter
through `bear/project/info` and discover the currently available request names and
semantic capabilities in `data.requests` and `data.capabilities`. They should test the
specific request or capability they need instead of selecting a version of the whole
API.

The previously published `data.semanticApiVersion` remains `1` as a deprecated
compatibility member. New clients must not use it for negotiation or capability
decisions; it is retained so clients following the earlier contract continue to work.

The contract evolves additively. The server may add a request, an optional request
parameter with a default, an optional response member, or an advertised capability.
Clients must ignore unknown object members. Published request names, members, value
types, meanings, and statuses remain stable. A genuinely incompatible meaning is
introduced under a new request or capability name and may coexist with the old one;
it does not create a new version of every unrelated request.

The contract snapshot is
[`tests/Contract/semantic-query-contract.json`](../tests/Contract/semantic-query-contract.json).
It is a regression oracle, not a version-negotiation mechanism. Its test verifies all
public method names, handler argument names/types/defaults, success envelope and
top-level data members, failure envelope, error members, and the complete status set
against live handler responses.

For standard `textDocument/hover`, a recognized Resource URI literal returns its
resolved class, workspace-relative path, public `on*` methods, and outgoing
Link/Embed count. Non-BEAR positions continue through Phpactor's built-in PHP Hover.
A valid but unresolved or unsafe Resource URI returns an empty Hover instead of a
generic PHP string description. This is implemented as a narrow middleware because
the supported Phpactor version exposes one Hover handler rather than a provider chain.

In `aura.route.php`, the first static string argument of Aura.Router `route`, `get`,
`post`, `put`, `patch`, `delete`, `head`, and `options` calls (or their named `name:`
argument) returns standard Hover
with the route name and resolved Page Resource URI, class, and workspace-relative
path. HTTP path arguments, `attach`, dynamic expressions, and quote boundaries
continue to Phpactor. A recognized route whose Resource is missing, ambiguous,
invalid, or unsafe returns an empty Hover without guessing from the HTTP path.

Standard `textDocument/references` at the same Route name resolves the Page Resource
through the same Route query used by Definition. It returns Route declarations and
Resource URI literals that resolve to that exact Page Resource. HTTP path arguments and
missing or ambiguous Routes return no BEAR references. Route-file scanning is restricted
to the workspace-local `aura.route.php` and bounded to 1 MiB.

Static Ray.MediaQuery `DbQuery` IDs and legacy Ray.QueryModule `@Query("id")`
docblock IDs return standard Hover with the query ID and resolved
workspace-relative SQL path. Attribute recognition follows the actual ID argument:
the first positional argument or `id:`; `type:`, `factory:`, later arguments,
dynamic expressions, foreign attributes, and quote boundaries continue to Phpactor.
SQL contents are never included. A recognized missing, invalid, or unsafe query ID
returns an empty Hover without trying another convention.

Standard `textDocument/references` at the same static SQL ID returns `DbQuery` and
legacy `@Query` sites only when the ID resolves to an existing SQL file. Equal strings,
foreign attributes, non-ID arguments, dynamic expressions, and IDs without a SQL file
are not references. Scanning is restricted to canonical PSR-4 roots inside the workspace,
with each PHP input limited to 1 MiB and results ordered deterministically. With
`includeDeclaration: true`, the existing Definition locator also supplies the SQL file
as the declaration location.

The same standard Hover method recognizes only the first string argument of
`#[Alps('descriptorId')]` (including the supported fully-qualified spellings). It
returns the workspace-relative profile, bounded descriptor fields, and up to 20
deterministically ordered incoming and outgoing explicit local relationships:
`contains`, local-fragment `href`, and local-fragment `rt`. It never fetches external
references or infers a Resource from descriptor names. An unresolved or unsafe ALPS
descriptor returns an empty Hover; other attribute arguments and ordinary PHP
positions continue to Phpactor.

Standard `textDocument/references` on that static `Alps` argument returns PHP
attribute usages resolving to the same profile path and descriptor byte offset. This
prevents an equal ID in another project/profile from being conflated. Missing or
duplicate descriptors return no BEAR references; `includeDeclaration: true` adds the
descriptor definition in the profile. Profile `contains`, `href`, and `rt` edges are
kept as structured relationships in `bear/alps/describeDescriptor` and Hover instead
of being flattened into ordinary attribute references.

Static Twig and Qiq template references supported by Definition also return standard
Hover with the engine, original name, and resolved workspace-relative path. Twig
recognition is limited to `.html.twig` documents or the `twig` language ID. Qiq Hover
recognition is deliberately narrower than the legacy Definition heuristic: the document
must use the `qiq` language ID or live below `var/qiq/template`. Dynamic expressions,
Twig block names, comments, and quote boundaries continue to Phpactor. A recognized
static reference that is missing, invalid, or unsafe returns an empty Hover without
guessing another target. For PHP-associated template documents, ALPS, explicit Schema,
SQL, Route, and Resource URI semantics are checked first so existing BEAR Hover
behavior remains unchanged.

Standard `textDocument/references` on those static template names resolves every
candidate in its own document context and returns only sites targeting the same
canonical template. Twig scanning is limited to `src/Resource` and `var/templates`;
Qiq scanning is limited to `var/qiq/template`. Each source is capped at 1 MiB, Qiq
relative names are resolved from each source file, and ordinary PHP `render()` calls,
dynamic expressions, comments, unresolved targets, and unsafe paths are excluded.

Explicit BEAR `#[JsonSchema(...)]` file references return standard Hover with the
request/response kind, resolved workspace-relative path, top-level types, and up to
20 deterministically ordered top-level properties with their declared types and
required flag. Recognition follows the BEAR attribute contract: the first positional
argument and `schema:` are response schemas, while `params:` is a request schema;
`key:`, `target:`, later positional arguments, dynamic expressions, foreign attributes,
and quote boundaries continue to Phpactor. Raw JSON and `$ref` targets are not returned
or expanded. A recognized missing, malformed, invalid, or outside-workspace schema
returns an empty Hover without guessing another target.

Standard `textDocument/references` on one of those explicit Schema arguments resolves
each candidate through the same Schema query and returns only attributes targeting the
same canonical Schema file. Request and response Schemas remain distinct; convention
Schemas, foreign attributes, unsupported arguments, dynamic expressions, and missing
files are not mixed in. SQL and Schema reference queries share a bounded PSR-4 PHP
source scanner with workspace containment, canonical deduplication, a 1 MiB per-file
limit, and deterministic paths. `includeDeclaration: true` also returns the Schema file.

Compatibility note: in the supported Phpactor release, extension middleware runs
before the built-in trace, shutdown, and cancellation middleware, and there is no
Hover provider chain. The BEAR middleware therefore performs only syntactic
recognition there and remaps a recognized request to a registered internal handler;
semantic querying and response creation still pass through Phpactor's lifecycle
middleware. Recognition itself is consequently outside cancellation and trace timing.
For PHP documents the deterministic recognition order is ALPS, explicit Schema, SQL,
Route, Resource URI, then Template; context-specific attributes and route declarations
therefore cannot be misclassified as generic string semantics. This shim should move
to an upstream Hover provider chain when Phpactor exposes one.

All paths supplied by a caller are relative to the workspace root. Successful paths
in responses are also workspace-relative. Every response has this envelope:

```json
{
  "status": "ok",
  "data": {},
  "candidates": [],
  "provenance": [
    {
      "source": "file",
      "path": "src/Resource/App/User.php",
      "freshness": "saved"
    }
  ]
}
```

`status` is one of `ok`, `not_found`, `ambiguous`, `invalid_input`, `unsupported`,
`parse_error`, `engine_unavailable`, `outside_workspace`, or `timeout`. `data` is
non-null only for `ok`. A failed query may add an optional `partial` member when it
proves a narrower result without proving the requested target. In particular,
`bear/template/forResource` returns `not_found` with no successful `data` and a
`partial` object containing the resolved Resource and ordered `searched` convention
paths when the Resource exists but no template does. Phpactor's stdio serializer omits
null object members, so `data` and a null template `path` are absent on the wire even
though direct in-process handler calls represent them as null. Failed results also
contain an `error` object with a stable snake_case `code` and a safe human-readable
`message`; no exception trace is exposed.

`provenance` records the workspace-relative evidence used by the semantic query.
Disk-backed facts use `freshness: saved`; `buffer` is reserved for a future query that
actually reads an LSP document buffer. `source: derived` carries no path and marks a
result composed from other evidence. Token-specific evidence can additionally contain
`byteRange: {start, end}`. Provenance is deduplicated and sorted deterministically, and
its DTO rejects absolute paths, parent traversal, and Windows absolute paths.

`bear/project/info` is the connection, API-version, and capability check for headless clients. It
reports only workspace-relative project and PSR-4 paths. Missing, invalid, symlinked,
or otherwise outside-workspace PSR-4 roots are counted in `excludedPsr4Roots` and are
not exposed. `versions` contains only runtime packages whose versions can be detected;
`compatibilityIssues` is empty unless a known, safely comparable incompatibility is
present.

## Standard LSP diagnostics

The diagnostic provider named `bear` uses the current LSP document buffer as its
source and saved workspace files as reference targets. It publishes standard
`textDocument/publishDiagnostics` warnings with `source: "bear"`, the stable project
diagnostic code, and structured `status`, `subject`, and `details` data. Only the
current existing file is analyzed, so an edit does not trigger a full project scan.

The provider covers explicit Resource URI, Route, SQL, JsonSchema, ALPS, and Twig/Qiq
references. As in the project query, SQL, Schema, and ALPS checks are omitted when the
corresponding supported convention root does not exist. Newly created files must be
saved once before the workspace boundary can be established. Resource parse failures,
Link/Embed target-method checks, and contract comparisons remain exclusive to the
saved-project `bear/project/diagnostics` request because they require project-wide
context or do not have a precise current-buffer range.

Phpactor's normal `language_server.diagnostics_on_open`, `_on_update`, `_on_save`, and
`diagnostic_sleep_time` settings control scheduling. If
`language_server.diagnostic_providers` is set explicitly, include `bear` to enable this
provider or omit it to disable the provider.

`bear/project/diagnostics` scans bounded saved sources and returns a deterministic,
limited item list plus the complete diagnostic count for the scanned set. Individual
missing, ambiguous, invalid, or malformed references are diagnostic items; they do not
make the outer result fail. Each item contains a stable snake_case `code`, the underlying
semantic `status`, a concise `subject`, a workspace-relative source `path`, an optional
byte range, and bounded structured `details`. It checks explicit Resource URI, Route,
SQL, JsonSchema, ALPS, and Twig/Qiq references; Resource parse failures; resolved
Link/Embed target methods; and exact-name-presence contract differences. It does not
report absent convention templates or Schemas merely because those optional artifacts
do not exist, and it does not treat `unsupported` or `outside_workspace` as project
breakage by default. Contract items explicitly mean exact name presence only, not type,
meaning, or behavioral compatibility; their `status: ok` means the comparison query
succeeded, while the diagnostic `code` records the observed mismatch. SQL references
are checked only when the supported `var/db/sql` convention root exists. A missing root
may mean the application configured another Ray.MediaQuery directory, which cannot be
safely inferred from arbitrary PHP configuration. Schema references are checked only
when their corresponding `var/json_validate` or `var/json_schema` directory exists;
ALPS descriptors are checked only when `apidoc.xml` exists. `skippedChecks` identifies
the omitted checks (`sql_references`, `request_schema_references`,
`response_schema_references`, or `alps_descriptors`), not a clean result.
`limit` defaults to 100 and accepts 1–200, preserving the v0.1.7 input range.
`offset` advances through the stable diagnostic ordering. Resource discovery is
complete; a page may contain fewer than `limit` items because an approximate 56 KiB
serialized-item-and-provenance budget also applies. Advance `offset` by the number of
items actually returned until `truncated` is false. The budget reserves room for the
envelope; it is not a strict wire-size guarantee, particularly for a single oversized item.
Contract-only name lists inside `details` are capped at five names per surface and
include complete per-surface totals plus `detailsTruncated`.

`bear/project/contractCoverage` is an adoption report, not an error report or quality
score. For each saved Resource method it inspects three surfaces: request Schema,
response Schema, and the method's ALPS descriptor. A surface state is `available`,
`absent`, `dynamic`, `unresolved`, or `not_applicable`; the underlying semantic
`status` and subject are preserved separately. A request Schema is `not_applicable`
only when the method has no parameters and no statically observable request Schema.
`absent` means no adopted artifact was found, **not** that the method requires one.
The `app`/`page` URI scheme does not prove whether a Resource is externally reachable
or rendered as JSON or HTML; API contexts may expose `app` Resources. Consequently,
the query does not infer Schema applicability from the scheme alone.
`covered` means every applicable surface is available, while `gaps` names the surfaces
that are absent, dynamic, or unresolved. `summary` counts every analyzed method in the
scanned Resource set, even when `items` is limited. Its `schemes.app` and `schemes.page`
subtotals separate source URI schemes without interpreting public exposure. Set `scheme`
to `app` or `page` to select a scheme before pagination, and `gapsOnly` to return only
methods with at least one adoption opportunity. `total` remains the complete analyzed
method count while `matchingTotal` is the count selected by those filters. `offset` pages through the stable
selected order. Resource discovery itself is complete. `scannedResources` and
`analyzedResources` expose the analyzed scope; `resourceScanTruncated` is retained as
a stable published member and is `false` for this complete scan. The query uses saved source only and returns at most
100 items per page. The same approximate serialized-item-and-provenance budget may
return fewer items; advance `offset` by the returned item count until `truncated` is
false. It is not a strict wire-size guarantee for a single oversized item.

## Methods

| Method | Params | Successful data |
|---|---|---|
| `bear/project/info` | `{contextPath?}` | `{semanticApiVersion, semanticProtocol, requests, workspaceName, projectPath, composerPath, psr4Roots, excludedPsr4Roots, resourceCount, capabilities, versions, compatibilityIssues}` |
| `bear/project/diagnostics` | `{contextPath?, limit?, offset?}` | `{items, total, offset, truncated, scannedFiles, scannedResources, resourceScanTruncated, skippedChecks}` |
| `bear/project/contractCoverage` | `{contextPath?, limit?, offset?, gapsOnly?, scheme?}` | `{items, total, matchingTotal, offset, truncated, gapsOnly, scheme, scannedResources, analyzedResources, resourceScanTruncated, summary}` |
| `bear/resource/resolve` | `{uri, contextPath?}` | `{uri, fqn, path}` |
| `bear/resource/list` | `{scheme?, prefix?, limit?, offset?}` | `{resources, total, offset, truncated}` |
| `bear/resource/describe` | `{uri, contextPath?, incomingLimit?}` | `{resource, methods[{name, parameters, responseBody}], relationsOut, relationsIn, templates, schemas}` |
| `bear/resource/attributes` | `{uri, contextPath?}` | `{resource, attributes, argumentPolicy}` |
| `bear/resource/attributeIndex` | `{scheme?, prefix?, limit?, offset?}` | `{items, total, offset, truncated, argumentPolicy}` |
| `bear/resource/incomingRelations` | `{uri, contextPath?, limit?}` | `{resource, available, items, total, truncated}` |
| `bear/resource/references` | `{uri, contextPath?, limit?}` | `{resource, references, total, truncated}` |
| `bear/contract/compare` | `{uri, method?, schemaKind?, descriptorId?, contextPath?}` | `{resource, method, schemaKind, surfaces, comparison}` |
| `bear/route/resolve` | `{route, contextPath?}` | `{route, resource}` |
| `bear/sql/resolve` | `{queryId, contextPath?}` | `{queryId, path}` |
| `bear/template/resolve` | `{engine, name, contextPath?}` | `{engine, name, path}` |
| `bear/template/forResource` | `{uri, engine, contextPath?}` | `{resource, engine, path, searched}` |
| `bear/alps/resolveDescriptor` | `{descriptorId, contextPath?}` | `{descriptorId, profilePath, byteOffset}` |
| `bear/alps/describeDescriptor` | `{descriptorId, contextPath?}` | `{descriptorId, profilePath, byteOffset, type, name, rt, href, rel, doc, def, tag, title, relationsOut, relationsIn}` |
| `bear/schema/resolveNamed` | `{fileName, kind, contextPath?}` | `{kind, source, path, titleByteOffset, resource}` |
| `bear/schema/forResource` | `{uri, kind?, contextPath?}` | `{kind, source, path, titleByteOffset, resource}` |
| `bear/schema/describeNamed` | `{fileName, kind, contextPath?}` | `{kind, source, path, titleByteOffset, resource, available, types, properties}` |
| `bear/schema/describeForResource` | `{uri, kind?, contextPath?}` | `{kind, source, path, titleByteOffset, resource, available, types, properties}` |

`engine` is `twig` or `qiq`. A relative Qiq name requires `contextPath`. The ALPS
offset is a byte offset in the saved JSON profile; positional standard LSP methods
continue to use UTF-16 line/character positions.

For `bear/template/forResource`, `searched` contains workspace-relative convention
paths in the order actually checked. A missing Resource has no successful `data`; an
existing Resource without a template has `status: not_found`, no successful `data`,
and a `partial` object with the Resource, no resolved template path, the complete
searched path list, and saved-file provenance for the Resource. Null members are
omitted on the stdio wire. Missing paths are descriptive candidates and are not
reported as file provenance.

ALPS description follows nested descriptors recursively and reports only explicit
relationships in the workspace-local profile: `contains`, a descriptor's local-fragment
`href`, and a transition's local-fragment `rt`. Each relation contains `sourceId`,
`targetId`, `sourceByteOffset`, `targetByteOffset`, and a `targetStatus` of `ok`,
`not_found`, or `ambiguous`. A missing `type` on an addressable descriptor is normalized
to the ALPS default `semantic`. External `href` and `rt` values remain visible as scalar
descriptor fields but are never fetched or converted into local relation edges. The
server does not infer a BEAR Resource relation from ALPS naming similarity or `rel`.
ALPS JSON and `apidoc.xml` input are each limited to 1 MiB and a structure depth of 64.
Optional descriptor scalar fields are absent from the serialized response when unspecified.

Normalized ALPS profiles and parsed Schema facts use bounded process-lifetime caches. The
saved file is still read and content-hashed for every query, so edits with unchanged size
and modification time cannot return stale semantic data. Parse errors are reused only while
the file content is unchanged.

Resource candidate inventory uses a separate bounded process-local index only when the LSP
client supports dynamic `workspace/didChangeWatchedFiles` registration and Phpactor file
events are enabled. PHP create/change/delete events and `textDocument/didSave` invalidate
the complete index; the Composer PSR-4 map is also part of each cache key. The same index is
shared by Resource list, incoming relation, project info, and Resource URI completion paths.
Standalone semantic callers and clients without watched-file support continue to rescan on
every query, so they cannot receive stale inventory merely because invalidation is unavailable.

Schema `kind` is `request` or `response`. Standard Hover recognizes explicit BEAR
`JsonSchema` file arguments only; convention lookup remains on `textDocument/typeDefinition`.
Resource convention lookup supports only
`response`; request schemas require the explicit name recorded by `#[JsonSchema(params: ...)]`.
Schema description returns sorted top-level properties with `required` and statically
declared `types`. It does not return raw JSON or expand `$ref`. JSON input is limited
to 1 MiB and a decode depth of 64; malformed or over-limit input returns `parse_error`.

Resource listing accepts `app` or `page` as `scheme`. `limit` defaults to 50 and
has a maximum of 200 per page; `offset` defaults to zero and reaches later pages in the
stable ordering. Duplicate URIs from different PSR-4 roots remain separate,
and results are ordered by URI, path, then FQN. The supported Phpactor version does
not expose a provider chain for `workspace/symbol`, so this identifier-based request
is kept separate instead of replacing Phpactor's PHP symbol search.

No BEAR provider is registered for `textDocument/documentSymbol` either. In the
supported Phpactor release, both document and workspace symbol methods have a single
provider/handler and no extension composition chain. Replacing them would discard
Phpactor's native behavior. Document symbols include class members from the requested
saved file. Phpactor's workspace-symbol provider, however, maps only indexed class,
function, and constant records; it does not map member records such as methods, and
its index freshness is separate from saved-file freshness. BEAR identifiers retain
their structure through the read-only custom queries. Route names and URIs are not
emitted as fake PHP symbols. This decision should be revisited if Phpactor exposes
composable providers.

Resource description reports public `on*` methods, declared parameter types, and
statically resolvable method-level `BEAR\\Resource\\Annotation\\Link` and `Embed`
relations. `relationsIn` contains the bounded incoming relation set with `available`,
`items`, `total`, and `truncated`. It is unavailable for an ambiguous Resource URI;
the server does not claim that a URI-only relation belongs to one physical candidate.
Dynamic target expressions and invalid resource URIs are not guessed.

Resource attribute facts are deliberately allowlisted. The current set is `Alps`,
`Cacheable`, `CacheableResponse`, `DonutCache`, `HttpCache`, `Purge`, `Refresh`,
`Embed`, `JsonSchema`, and `Link`, matched by fully qualified class name. Class-level
attributes and attributes on public Resource `on*` methods are returned with target,
method name, FQN, saved-file byte range, and source-order arguments. Static strings,
numbers, booleans, and `null` are typed explicitly. Expressions, constants, calls,
and over-limit strings are returned as `{type: "dynamic", value: null}` rather than
evaluated or guessed. Application PHP is never loaded or executed.

Both attribute requests return `argumentPolicy: {source: "explicit_only",
constructorDefaultsExpanded: false}`. An omitted argument therefore means only that it
was absent from attribute syntax; it does not prove that the parameter has no default.
Expanding defaults safely would require version-aware static parsing of the installed
attribute class, not a hardcoded framework value or PHP execution.

`bear/resource/attributeIndex` applies the same `scheme`, `prefix`, `limit`, and `offset`
bounds as Resource listing. Every selected Resource has its own `status` and `attributes`.
The outer result therefore remains `ok` when one selected file becomes malformed;
that item reports `parse_error` while facts proven from the other saved files remain
available. This partial-result model is intended for workspace audits and AI clients
that need evidence rather than an all-or-nothing text search.

`bear/contract/compare` compares exact name presence and deliberately does not claim
type compatibility, semantic equivalence, or behavioral compatibility. For
`schemaKind: "request"`, the Resource surface is the selected public `on*` method's
parameter names, the Schema surface is the static `#[JsonSchema(params: ...)]` file,
and the ALPS surface is the selected operation descriptor's contained descriptor IDs.
The ALPS descriptor defaults to the method/class `#[Alps(...)]` value and can be
overridden explicitly with `descriptorId`.

For `schemaKind: "response"`, the Schema surface is an explicit response Schema or
the Resource convention Schema. If the ALPS operation has a local `rt`, its target
representation's contained descriptor IDs form the ALPS surface. The Resource surface
is `ok` only when saved source proves an exact top-level body shape: a straight-line
method must assign a literal-key array to `$this->body`, may then add literal keys, and
may return `$this`. Conditional control flow, dynamic keys or assignments, unsupported
body uses, and direct `$this` helper calls stay `unsupported`; application PHP is never
loaded or executed.

Each `bear/resource/describe` method reports the same evidence as
`responseBody: {status, names, reason}`. A complete shape has status `ok`, sorted names,
and a null reason. Unsupported shapes have no names and a stable reason such as
`no_complete_body_assignment`, `complex_control_flow`, `dynamic_body_assignment`, or
`dynamic_body_key`. These are exact observations of explicit method-body construction,
not a claim about runtime interceptors or semantic/type compatibility.

Every surface independently reports `status`, `subject`, and sorted `names`. The
`comparison` member is `null` until at least two surfaces are `ok`; otherwise it
contains the compared sources, intersection, names exclusive to one surface, and a
lossless per-name presence list. These are saved-file observations only. A matching
name is not evidence that types, constraints, or meanings match.

`bear/resource/references` is the identifier-based counterpart for clients that have
a Resource URI but no open text document or LSP position. When a position is available,
clients should continue to use standard `textDocument/references`. Each returned static
reference contains `kind` (`resource_uri` or `route`), the original `identifier`, a
workspace-relative `path`, and a content-only byte range. Equal URI text is included only
after resolving from that source file's project context to the same canonical Resource;
mini applications are not conflated. Results default to 50 items, accept at most 200,
and expose the complete `total` plus `truncated`. The standard positional request remains
unlimited and preserves Phpactor's reference-finder chain.
For a Link with a dynamic explicit method, `targetMethod` is `null`; an omitted
method uses BEAR.Resource's `get` default. Relation byte offsets refer to the saved
PHP file. Incoming results default to 50 items and accept at most 200; the complete
current workspace Resource inventory is considered before `total` and `truncated`
are reported, whether it was rebuilt or safely reused from the watched index.
`templates` lists existing Qiq and Twig convention paths in deterministic engine
order. `schemas` lists the convention response Schema when present and is empty when
the Resource has no matching Schema. Request Schema inference is not guessed because
it requires an explicit `#[JsonSchema(params: ...)]` declaration.

Parsed Resource facts are cached for the lifetime of the Language Server process.
The key includes canonical path, Resource identity, modification time, size, and a
content hash. Repeated queries avoid reparsing unchanged PHP while same-size edits in
the same timestamp tick still invalidate the entry. The cache never changes the
saved-file semantics of these requests.

Example JSON-RPC request:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "bear/resource/resolve",
  "params": {
    "uri": "app://self/user",
    "contextPath": "src/Resource/App/Dashboard.php"
  }
}
```

From this repository, the same request can be checked against a real Phpactor
stdio process without an IDE:

```console
php tools/semantic-lsp-query.php /path/to/bear-project \
  bear/resource/resolve \
  '{"uri":"app://self/user","contextPath":"src/Resource/App/Dashboard.php"}'
```

The target project must already have Phpactor and this extension configured. Set
`PHPACTOR_BIN` if Phpactor is not available in the target project or this repository.
