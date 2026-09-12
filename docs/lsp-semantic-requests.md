# BEAR semantic LSP requests

Position-based navigation continues to use the standard LSP methods
`textDocument/definition`, `textDocument/references`, `textDocument/completion`, and
`textDocument/documentLink`.

The requests below are read-only custom requests for queries that start with a BEAR
identifier instead of a text-document position. A client should not create a fake
document merely to call a standard positional method. These methods are not a
replacement for standard LSP navigation.

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

## Methods

| Method | Params | Successful data |
|---|---|---|
| `bear/resource/resolve` | `{uri, contextPath?}` | `{uri, fqn, path}` |
| `bear/resource/list` | `{scheme?, prefix?, limit?}` | `{resources, total, truncated}` |
| `bear/resource/describe` | `{uri, contextPath?, incomingLimit?}` | `{resource, methods, relationsOut, relationsIn, templates, schemas}` |
| `bear/resource/incomingRelations` | `{uri, contextPath?, limit?}` | `{resource, available, items, total, truncated}` |
| `bear/route/resolve` | `{route, contextPath?}` | `{route, resource}` |
| `bear/sql/resolve` | `{queryId, contextPath?}` | `{queryId, path}` |
| `bear/template/resolve` | `{engine, name, contextPath?}` | `{engine, name, path}` |
| `bear/template/forResource` | `{uri, engine, contextPath?}` | `{resource, engine, path}` |
| `bear/alps/resolveDescriptor` | `{descriptorId, contextPath?}` | `{descriptorId, profilePath, byteOffset}` |
| `bear/schema/resolveNamed` | `{fileName, kind, contextPath?}` | `{kind, source, path, titleByteOffset, resource}` |
| `bear/schema/forResource` | `{uri, kind?, contextPath?}` | `{kind, source, path, titleByteOffset, resource}` |

`engine` is `twig` or `qiq`. A relative Qiq name requires `contextPath`. The ALPS
offset is a byte offset in the saved JSON profile; positional standard LSP methods
continue to use UTF-16 line/character positions.

Schema `kind` is `request` or `response`. Resource convention lookup supports only
`response`; request schemas require the explicit name recorded by `#[JsonSchema(params: ...)]`.

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
