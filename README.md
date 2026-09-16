# suzumaze/bear-phpactor-extension

English | [日本語](README.ja.md)

Adds [BEAR.Sunday](https://bearsunday.github.io/manuals/1.0/en/) semantics to [Phpactor](https://phpactor.readthedocs.io/), so standard [LSP](https://microsoft.github.io/language-server-protocol/) operations understand Resource URIs, SQL, JSON Schema, ALPS, Router, Twig, and Qiq conventions.

```text
BEAR.Sunday semantics
        ↓
bear-phpactor-extension
        ↓
Phpactor / LSP
        ↓
VS Code / Neovim / Emacs / other LSP clients
```

This package registers Phpactor locators, providers, and completors. It does not implement editor-specific APIs, render templates, execute application PHP, or provide MCP tools.

## Requirements

- [PHP](https://www.php.net/) 8.2 or later
- [Composer](https://getcomposer.org/)
- Phpactor compatible with the versions in [`composer.json`](composer.json)
- A BEAR.Sunday project with `autoload.psr-4` configured

## Features

| BEAR.Sunday semantic | LSP operation and result |
|---|---|
| [Resource URI](https://bearsunday.github.io/manuals/1.0/en/resource.html) | Definition, References, Hover, URI Completion, and Document Link for `app://self/user` |
| [SQL](https://bearsunday.github.io/manuals/1.0/en/database.html) | Definition, References, and Hover for `#[DbQuery('point_distance')]` and `@Query("point_distance")` |
| [JSON Schema](https://bearsunday.github.io/manuals/1.0/en/validation.html) | Definition, Type Definition, References, Hover, and body-property Completion |
| [ALPS](https://bearsunday.github.io/manuals/1.0/en/apidoc.html) | Definition, References, and Hover for descriptors selected through `apidoc.xml` |
| [Twig and Qiq](https://bearsunday.github.io/manuals/1.0/en/html.html) | Definition, References, Hover, and Document Link for static template references; Definition from `#[Embed]` relations |
| [Aura Router](https://bearsunday.github.io/manuals/1.0/en/router.html) | Definition, References, and Hover from a route name to its Page Resource |

Project roots and namespace prefixes come from the project's `composer.json`. Normal PHP definitions remain handled by Phpactor.

## Headless semantic queries

Standard position-based LSP methods remain the primary interface. For clients that
already have a BEAR identifier but no open document position, the Language Server also
provides 18 read-only `bear/*` requests for project, Resource, Route, SQL, Template,
ALPS, and Schema facts. Resource attribute facts and their workspace inventory are
available without executing application PHP. `bear/project/info` reports Semantic API version `1` and the
available capabilities. The complete versioned contract is documented in
[`docs/lsp-semantic-requests.md`](docs/lsp-semantic-requests.md).

An IDE is not required. The included client starts a real Phpactor stdio process:

```bash
php tools/semantic-lsp-query.php /path/to/bear-project \
  bear/resource/describe \
  '{"uri":"app://self/user","contextPath":"src/Resource/App/User.php"}'
```

For an AI client auditing cache and Resource metadata across a workspace:

```bash
php tools/semantic-lsp-query.php /path/to/bear-project \
  bear/resource/attributeIndex \
  '{"scheme":"app","limit":50}'
```

These requests inspect saved workspace files only. They do not execute the BEAR
application, modify files, access the network, or provide an MCP server.

## Twig and Qiq

Template navigation implements only confirmed BEAR.Sunday relationships from the standard [Qiq](https://bearsunday.github.io/manuals/1.0/en/html-qiq.html) and [Twig](https://bearsunday.github.io/manuals/1.0/en/html-twig-v2.html) layouts.

| Reference | Supported cursor position | Target |
|---|---|---|
| Twig path | Static string in the first argument of `extends`, `include`, or `include()`, or the second argument of `block()` | Existing template under `src/Resource`, then `var/templates` |
| Qiq path | Static string in `setLayout()`, `render()`, or `extends()`, in Qiq helper syntax or native PHP | Existing `.php` template under `var/qiq/template`; `./` and `../` are relative to the current template |
| Twig Embed relation | Leading variable in `{{ rel }}` or `{{ rel|raw }}` under `var/templates/{App,Page}/.../*.html.twig` | Twig template for the Resource declared by the parent Resource's `#[Embed]` |
| Qiq Embed relation | `$rel` in `{{= $rel }}` or `{{h $rel }}` under `var/qiq/template/{App,Page}/.../*.php`; legacy `$this->rel` is also accepted | Qiq template for the Resource declared by the parent Resource's `#[Embed]` |

Embed navigation reads only named static string arguments `rel:` and `src:`. Absolute `app://self/...` and `page://self/...` URIs are supported. A relative `/...` source inherits the parent Resource scheme and resolves against `self`.

Dynamic expressions, Twig imports and property expressions, unknown Qiq/PHP calls, imported-app Resources, custom template roots, and ambiguous conventions are not resolved.

## Installation

Phpactor and this package must share one Composer autoloader.

Commands in this section run in a terminal, not in an editor command palette.

### VS Code

Use [Phpactor Setup for BEAR.Sunday](https://github.com/suzumaze/phpactor-setup-for-bear-sunday). It installs a tested Phpactor/core combination outside the project, configures the official VS Code client, and provides commands to inspect or update this core package.

### Manual global installation

[Phpactor recommends](https://github.com/phpactor/phpactor#installation) installing the language server outside project dependencies. The following creates a dedicated installation:

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

Generate Phpactor's global extension list while preserving other keys in an existing valid config:

```bash
mkdir -p "${XDG_CONFIG_HOME:-$HOME/.config}/phpactor"
cd "${XDG_CONFIG_HOME:-$HOME/.config}/phpactor"
PHPACTOR_BIN="$HOME/.local/share/phpactor-bear/vendor/bin/phpactor" \
  "$HOME/.local/share/phpactor-bear/vendor/bin/bear-phpactor-init"
```

Point the editor's Phpactor path to:

```text
~/.local/share/phpactor-bear/vendor/bin/phpactor
```

To update only this package within the compatible range:

```bash
cd ~/.local/share/phpactor-bear
composer update suzumaze/bear-phpactor-extension --with-dependencies
cd "${XDG_CONFIG_HOME:-$HOME/.config}/phpactor"
PHPACTOR_BIN="$HOME/.local/share/phpactor-bear/vendor/bin/phpactor" \
  "$HOME/.local/share/phpactor-bear/vendor/bin/bear-phpactor-init"
```

### Project-local installation

If Phpactor is already managed by the project, install the packages together and run the initializer from the project root:

```bash
composer require --dev \
  phpactor/phpactor:2026.07.22.0 \
  phpactor/language-server-protocol:3.17.4 \
  suzumaze/bear-phpactor-extension
vendor/bin/bear-phpactor-init
vendor/bin/phpactor config:trust --trust
```

Set the LSP client to the same installation's `vendor/bin/phpactor`. Re-run `bear-phpactor-init` after changing Phpactor versions because `container.extension_classes` replaces, rather than extends, Phpactor's built-in list.

## Editor requirements

An LSP client must start this Phpactor binary with `language-server` and send the relevant document to it.

Qiq templates use `.php` and normally reach Phpactor. The official [Phpactor VS Code client](https://github.com/phpactor/vscode-phpactor) does not select Twig documents by default. For the BEAR standard `.html.twig` layout, VS Code users can apply this workspace-local workaround:

```json
{
    "files.associations": {
        "*.html.twig": "php"
    }
}
```

This sends Twig as PHP and can affect highlighting, diagnostics, formatting, and other Twig extensions. Clients with configurable document selectors should attach Phpactor directly to Twig instead.

## Resolution rules

- Definitions are returned only when the cursor is on a supported reference and the target exists.
- Targets must remain inside the workspace; traversal and arbitrary external paths are rejected.
- Invalid syntax, missing files, and unsupported expressions return no result instead of throwing.
- Static analysis only is used. Templates are not rendered and application PHP is not executed.
- Multiple Resource candidates are sorted and presented by fully qualified name for definitions. Ambiguous reference-search sites are treated as unresolved.
- Repeated Embed relations resolve only when every occurrence points to the same normalized Resource URI.

## Definition behavior

- Resource URI, SQL, attribute-based JSON Schema, ALPS, Router, and template relationships use **Go to Definition**.
- A Resource class declaration uses **Go to Type Definition** for its convention-based JSON Schema. Normal **Go to Definition** remains owned by Phpactor.
- Router navigation uses the first argument as the route name. The second argument is an HTTP path and is intentionally not a jump site; `$map->attach()` is also excluded.

## Known limitations

- Template paths follow only the default BEAR Twig and Qiq loader layouts.
- The official VS Code client needs the Twig workaround described above.
- SQL definitions land at the beginning of the `.sql` file.
- Reference search reads saved files only and scans only `autoload` / `autoload-dev` PSR-4 roots.
- Resource completion uses a text scan for `extends ... ResourceObject`, which can produce extra candidates.
- Windows drive-letter paths are guarded for template resolution but remain incomplete in PSR-4 directory resolution.

## Related projects

- [Phpactor Setup for BEAR.Sunday](https://github.com/suzumaze/phpactor-setup-for-bear-sunday): VS Code installation and update wrapper for this package
- [BEAR.Sunday Extension Pack](https://marketplace.visualstudio.com/items?itemName=YukiAdachi.vscode-bear-sunday-extension-pack): earlier VS Code-specific implementation
- [idea-php-bearsunday-plugin](https://github.com/bearsunday/idea-php-bearsunday-plugin): PhpStorm plugin with JetBrains-specific features

These projects use different architectures and do not replace one another.

## Development

```bash
composer check
```

The suite includes unit tests and real Phpactor stdio sessions from initialize through shutdown. `tools/coverage.php` and `tools/misfire.php` provide project-level checks against [BEAR.Kata](https://github.com/bearsunday/BEAR.Kata).
