# BEAR semantic LSP requests

Position-based navigation continues to use the standard LSP methods
`textDocument/definition`, `textDocument/references`, `textDocument/hover`,
`textDocument/completion`, and `textDocument/documentLink`.

The requests below are read-only custom requests for queries that start with a BEAR
identifier instead of a text-document position. A client should not create a fake
document merely to call a standard positional method. These methods are not a
replacement for standard LSP navigation.

For standard `textDocument/hover`, a recognized Resource URI literal returns its
resolved class, workspace-relative path, public `on*` methods, and outgoing
Link/Embed count. Non-BEAR positions continue through Phpactor's built-in PHP Hover.
A valid but unresolved or unsafe Resource URI returns an empty Hover instead of a
generic PHP string description. This is implemented as a narrow middleware because
the supported Phpactor version exposes one Hover handler rather than a provider chain.

The same standard Hover method recognizes only the first string argument of
`#[Alps('descriptorId')]` (including the supported fully-qualified spellings). It
returns the workspace-relative profile, bounded descriptor fields, and up to 20
deterministically ordered incoming and outgoing explicit local relationships:
`contains`, local-fragment `href`, and local-fragment `rt`. It never fetches external
references or infers a Resource from descriptor names. An unresolved or unsafe ALPS
descriptor returns an empty Hover; other attribute arguments and ordinary PHP
positions continue to Phpactor.

Static Twig and Qiq template references supported by Definition also return standard
Hover with the engine, original name, and resolved workspace-relative path. Twig
recognition is limited to `.html.twig` documents or the `twig` language ID. Qiq Hover
recognition is deliberately narrower than the legacy Definition heuristic: the document
must use the `qiq` language ID or live below `var/qiq/template`. Dynamic expressions,
Twig block names, comments, and quote boundaries continue to Phpactor. A recognized
static reference that is missing, invalid, or unsafe returns an empty Hover without
guessing another target. For PHP-associated template documents, ALPS, explicit Schema,
and Resource URI semantics are checked first so existing BEAR Hover behavior remains
unchanged.

Explicit BEAR `#[JsonSchema(...)]` file references return standard Hover with the
request/response kind, resolved workspace-relative path, top-level types, and up to
20 deterministically ordered top-level properties with their declared types and
required flag. Recognition follows the BEAR attribute contract: the first positional
argument and `schema:` are response schemas, while `params:` is a request schema;
`key:`, `target:`, later positional arguments, dynamic expressions, foreign attributes,
and quote boundaries continue to Phpactor. Raw JSON and `$ref` targets are not returned
or expanded. A recognized missing, malformed, invalid, or outside-workspace schema
returns an empty Hover without guessing another target.

Compatibility note: in the supported Phpactor release, extension middleware runs
before the built-in trace, shutdown, and cancellation middleware, and there is no
Hover provider chain. The BEAR middleware therefore performs only syntactic
recognition there and remaps a recognized request to a registered internal handler;
semantic querying and response creation still pass through Phpactor's lifecycle
middleware. Recognition itself is consequently outside cancellation and trace timing.
This shim should move to an upstream Hover provider chain when Phpactor exposes one.

All paths supplied by a caller are relative to the workspace root. Successful paths
in responses are also workspace-relative. Every response has this envelope:

```json
{
  "status": "ok",
  "data": {},
  "candidates": []
}
```

`status` is one of `ok`, `not_found`, `ambiguous`, `invalid_input`, `unsupported`,
`parse_error`, or `outside_workspace`. `data` is non-null only for `ok`.

`bear/project/info` is the connection and capability check for headless clients. It
reports only workspace-relative project and PSR-4 paths. Missing, invalid, symlinked,
or otherwise outside-workspace PSR-4 roots are counted in `excludedPsr4Roots` and are
not exposed. `versions` contains only runtime packages whose versions can be detected;
`compatibilityIssues` is empty unless a known, safely comparable incompatibility is
present.

## Methods

| Method | Params | Successful data |
|---|---|---|
| `bear/project/info` | `{contextPath?}` | `{workspaceName, projectPath, composerPath, psr4Roots, excludedPsr4Roots, resourceCount, capabilities, versions, compatibilityIssues}` |
| `bear/resource/resolve` | `{uri, contextPath?}` | `{uri, fqn, path}` |
| `bear/resource/list` | `{scheme?, prefix?, limit?}` | `{resources, total, truncated}` |
| `bear/resource/describe` | `{uri, contextPath?, incomingLimit?}` | `{resource, methods, relationsOut, relationsIn, templates, schemas}` |
| `bear/resource/incomingRelations` | `{uri, contextPath?, limit?}` | `{resource, available, items, total, truncated}` |
| `bear/route/resolve` | `{route, contextPath?}` | `{route, resource}` |
| `bear/sql/resolve` | `{queryId, contextPath?}` | `{queryId, path}` |
| `bear/template/resolve` | `{engine, name, contextPath?}` | `{engine, name, path}` |
| `bear/template/forResource` | `{uri, engine, contextPath?}` | `{resource, engine, path}` |
| `bear/alps/resolveDescriptor` | `{descriptorId, contextPath?}` | `{descriptorId, profilePath, byteOffset}` |
| `bear/alps/describeDescriptor` | `{descriptorId, contextPath?}` | `{descriptorId, profilePath, byteOffset, type, name, rt, href, rel, doc, def, tag, title, relationsOut, relationsIn}` |
| `bear/schema/resolveNamed` | `{fileName, kind, contextPath?}` | `{kind, source, path, titleByteOffset, resource}` |
| `bear/schema/forResource` | `{uri, kind?, contextPath?}` | `{kind, source, path, titleByteOffset, resource}` |
| `bear/schema/describeNamed` | `{fileName, kind, contextPath?}` | `{kind, source, path, titleByteOffset, resource, available, types, properties}` |
| `bear/schema/describeForResource` | `{uri, kind?, contextPath?}` | `{kind, source, path, titleByteOffset, resource, available, types, properties}` |

`engine` is `twig` or `qiq`. A relative Qiq name requires `contextPath`. The ALPS
offset is a byte offset in the saved JSON profile; positional standard LSP methods
continue to use UTF-16 line/character positions.

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
the file content is unchanged. Resource inventory is deliberately not cached here: without
a filesystem watcher or Phpactor index invalidation event, detecting added, removed, or
inheritance-changing PHP files would require a complete freshness scan anyway.

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

Resource description reports public `on*` methods, declared parameter types, and
statically resolvable method-level `BEAR\\Resource\\Annotation\\Link` and `Embed`
relations. `relationsIn` contains the bounded incoming relation set with `available`,
`items`, `total`, and `truncated`. It is unavailable for an ambiguous Resource URI;
the server does not claim that a URI-only relation belongs to one physical candidate.
Dynamic target expressions and invalid resource URIs are not guessed.
For a Link with a dynamic explicit method, `targetMethod` is `null`; an omitted
method uses BEAR.Resource's `get` default. Relation byte offsets refer to the saved
PHP file. Incoming results default to 50 items and accept at most 200; the complete
workspace Resource inventory is scanned before `total` and `truncated` are reported.
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
