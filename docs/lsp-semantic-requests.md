# BEAR semantic LSP requests

Position-based navigation continues to use the standard LSP methods
`textDocument/definition`, `textDocument/references`, `textDocument/hover`,
`textDocument/completion`, and `textDocument/documentLink`.

The requests below are read-only custom requests for queries that start with a BEAR
identifier instead of a text-document position. A client should not create a fake
document merely to call a standard positional method. These methods are not a
replacement for standard LSP navigation.

## Contract versioning

The public `bear/*` custom-request contract is Semantic API version `1`.
Clients discover it through `bear/project/info.data.semanticApiVersion`; it is
independent of both the LSP protocol version and this Composer package's version.
There is no version negotiation: a client that does not support the reported major
version should stop using the custom requests, while standard LSP methods remain
available.

Within one Semantic API version, the server may add a new request, an optional request
parameter with a default, an optional response member, or a new advertised capability.
Clients must ignore unknown object members. Removing or renaming a request or response
member, changing an existing value's type or meaning, making an optional parameter
required, or adding a `status` value requires a new Semantic API version.

The versioned contract snapshot is
[`tests/Contract/semantic-query-v1.json`](../tests/Contract/semantic-query-v1.json).
Its test verifies all public method names, handler argument names/types/defaults,
success envelope and top-level data members, failure envelope, error members, and the
complete status set against live handler responses.

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
normally non-null only for `ok`. A failed query may carry partial data when it proves a
narrower result without proving the requested target. In particular,
`bear/template/forResource` returns `not_found` with the resolved Resource and ordered
`searched` convention paths when the Resource exists but no template does. Failed
results also contain an `error` object with a stable snake_case `code` and a safe
human-readable `message`; no exception trace is exposed.

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

## Methods

| Method | Params | Successful data |
|---|---|---|
| `bear/project/info` | `{contextPath?}` | `{semanticApiVersion, workspaceName, projectPath, composerPath, psr4Roots, excludedPsr4Roots, resourceCount, capabilities, versions, compatibilityIssues}` |
| `bear/resource/resolve` | `{uri, contextPath?}` | `{uri, fqn, path}` |
| `bear/resource/list` | `{scheme?, prefix?, limit?}` | `{resources, total, truncated}` |
| `bear/resource/describe` | `{uri, contextPath?, incomingLimit?}` | `{resource, methods, relationsOut, relationsIn, templates, schemas}` |
| `bear/resource/attributes` | `{uri, contextPath?}` | `{resource, attributes}` |
| `bear/resource/attributeIndex` | `{scheme?, prefix?, limit?}` | `{items, total, truncated}` |
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
paths in the order actually checked. A missing Resource still has `data: null`; an
existing Resource without a template has `status: not_found`, `path: null`, non-null
Resource data, the complete searched path list, and saved-file provenance for the
Resource. Missing paths are descriptive candidates and are not reported as file
provenance.

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
has a maximum of 200. Duplicate URIs from different PSR-4 roots remain separate,
and results are ordered by URI, path, then FQN. The supported Phpactor version does
not expose a provider chain for `workspace/symbol`, so this identifier-based request
is kept separate instead of replacing Phpactor's PHP symbol search.

No BEAR provider is registered for `textDocument/documentSymbol` either. In the
supported Phpactor release, both document and workspace symbol methods have a single
provider/handler and no extension composition chain. Replacing them would discard
Phpactor's PHP class/method symbols or indexed symbol search. Resource classes and
`on*` methods already appear as PHP symbols; BEAR identifiers retain their structure
through the read-only custom queries. Route names and URIs are not emitted as fake PHP
symbols. This decision should be revisited if Phpactor exposes composable providers.

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

`bear/resource/attributeIndex` applies the same `scheme`, `prefix`, and `limit` bounds
as Resource listing. Every selected Resource has its own `status` and `attributes`.
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
representation's contained descriptor IDs form the ALPS surface. Static Resource body
shape is not implemented, so the Resource response surface reports `unsupported`
instead of inferring assignments or executing PHP.

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
