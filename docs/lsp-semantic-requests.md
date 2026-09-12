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
