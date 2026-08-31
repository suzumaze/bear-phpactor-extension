# suzumaze/bear-phpactor-extension

English | [日本語](README.ja.md)

BEAR.Sunday conventions for [phpactor](https://github.com/phpactor/phpactor), the PHP language server. This Composer package plugs BEAR.Sunday's naming and directory conventions into phpactor's LSP: definition jumps and completion that know where `app://self/user` lives, where SQL files go, and how JSON Schemas are named.

It implements no LSP protocol code itself — it registers a few locators and completors with phpactor's extension container.

## Features (v0.1)

| Feature | What happens |
|---|---|
| Resource URI definition jump | Cursor on `'app://self/user'` → jumps to `src/Resource/App/User.php` (psr-4 aware). Fires anywhere a resource URI string literal appears — including inside `#[Embed(src: ...)]` / `#[Link(href: ...)]` attributes, not just in plain code |
| Resource URI completion | `'app://self/<caret>'` → completes URIs of resource classes that exist in the project |
| SQL definition jump | Cursor on `#[DbQuery('point_distance')]` (Ray.MediaQuery — the fully-qualified form written without a `use`, `#[\Ray\MediaQuery\Annotation\DbQuery('point_distance')]`, works too) or `@Query("point_distance")` (Ray.QueryModule) → jumps to `var/db/sql/point_distance.sql` |
| JSON Schema definition jump (attribute) | Cursor on `#[JsonSchema('user.json')]` → jumps to `var/json_schema/user.json`; a `params:` named argument resolves under `var/json_validate/` instead |
| JSON Schema type definition jump (convention) | Cursor on a resource class declaration name → Go to Type Definition jumps to `var/json_schema/<kebab-case>.json` (e.g. `BodyTypeDemo` → `body-type-demo.json`, `Page\Admin\UserProfile` → `admin/user-profile.json`) |
| Router definition jump | Cursor on a **route name** in `aura.route.php` — the first argument of `$map->route()` / `$map->get()` / `$map->post()` / … → jumps to the corresponding Page resource class. Context prefixes are followed (`'/article-redirector'` finds `Page/Content/ArticleRedirector.php`), and inner capitals are preserved (`'/articleRedirector'` → `ArticleRedirector`, not `Articleredirector`). The second argument (the URL pattern, e.g. `'/blogs/{blogger}'`) is deliberately **not** a jump site: it is an HTTP path, not a resource path, and jumping from it lands on the wrong class. `$map->attach()` is excluded too — its first argument is a name prefix |
| Resource reference search | Cursor on a resource URI string (`'app://self/article'`) or a resource class declaration name → lists every place in the project that references that resource (`#[Link]`/`#[Embed]`/`$this->resource->get()`, …) |

All jumps are pure path/namespace mapping — no PHP type inference is involved. Project roots and namespace prefixes come from the project's `composer.json` `autoload.psr-4`.

## Installation

phpactor itself must be installed in the project (the extension is loaded by phpactor, not the other way around):

```bash
composer require --dev phpactor/phpactor suzumaze/bear-phpactor-extension
vendor/bin/bear-phpactor-init
phpactor config:trust --trust
```

Notes:

- phpactor depends on `dev-master` packages, so your project needs `"minimum-stability": "dev"` (or a `repositories` override) for the install to resolve.
- `phpactor config:trust --trust` marks the directory as trusted; phpactor ignores `.phpactor.json` in untrusted directories.
- The `extra.phpactor.extension_class` key in this package's `composer.json` is kept for ecosystem convention only — phpactor no longer reads it. The only working load path is `container.extension_classes` in `.phpactor.json`, and it applies to the language server only (not the CLI).
- Pin `phpactor/language-server-protocol` to `3.17.4` (`composer require --dev phpactor/language-server-protocol:3.17.4`). With language-server 7.0.1 and protocol 3.17.5, `textDocument/didChange` never reaches the server: unsaved edits are ignored and every feature answers from the `didOpen` text until you save — with no error anywhere. `bear-phpactor-init` detects this combination and warns on stderr (the command still succeeds). The regression is fixed upstream in the pull request that makes the handler read `contentChanges` as objects ([phpactor/language-server#68](https://github.com/phpactor/language-server/pull/68)), but no release has been cut yet. Drop the pin once a fixed language-server is released.

## Why `.phpactor.json` lists every extension class

phpactor's `container.extension_classes` parameter **replaces** the built-in defaults instead of appending to them. There is no "add my extension" option, so a project using this extension must enumerate every built-in extension class plus this one — 69 entries at the time of writing.

The built-in list is a literal array inside `Phpactor::boot()` with no public API, so `vendor/bin/bear-phpactor-init` obtains it at runtime: it runs `phpactor config:dump --config-only` in a clean temporary directory (so no project config can shadow the defaults) and writes the resolved list to `.phpactor.json` with `Suzumaze\BearPhpactor\BearSundayExtension` first.

**After upgrading phpactor, re-run `vendor/bin/bear-phpactor-init`.** Re-running regenerates the list from the new environment. The command is idempotent: it de-duplicates the extension list, keeps your own extension first, and preserves every other key of an existing `.phpactor.json`.

Skipping it fails in two different ways, and the second one is worse:

- An extension the upgrade **added** is missing from your list, so its features are absent. Nothing reports this.
- A class in your list **no longer exists** — renamed or removed upstream, or by this package. Then the language server does not start at all: `PhpactorDispatcherFactory` calls `new $class()` on every enumerated name and a fatal `Class "..." not found` takes the process down before it answers `initialize`. You lose every PHP language feature, not just this extension's, and the editor reports it as the server having crashed rather than as a configuration problem.

Verified by putting one non-existent class name in an otherwise valid `.phpactor.json`: the server died during startup, while the same project with the generated file answered normally.

### A trap in `config:trust`

Run `config:trust` **from inside the project directory**, or pass an absolute path:

```sh
cd /path/to/your-project && vendor/bin/phpactor config:trust --trust
```

Passing a *relative* path to `--working-dir` records that relative string verbatim in
phpactor's trust store (`~/.local/share/phpactor/trust.json`), and it never matches
afterwards. The failure mode is silent: `.phpactor.json` is not read, so this extension
is not loaded and every feature simply does nothing. If jumps and completion do nothing
at all, check that file for a relative entry.

## Definition jump behavior

Because this extension is listed **first**, its locators run before phpactor's built-in ones. The chain is first-match-wins, and this ordering is what makes the convention jumps work:

- **Cursor on a resource class declaration name** (e.g. `final class User` in `src/Resource/App/User.php`) → F12 (definition) behaves like the built-in: it stays put. The built-in answer in that situation is "you are already here", and that is what you get.
- **The class-name convention jump lives on Go to Type Definition instead.** Right-click → *Go to Type Definition* (no default keybinding) on a resource class declaration name jumps to `var/json_schema/user.json`. The JSON Schema decides the shape of the resource body, so "where is this resource's type" is the natural question for it to answer.
- **Why not F12?** The convention jump used to override definition on class declaration names. A missed Shift (⇧F12 for reference search, F12 for definition) then landed in a JSON file, which read as "reference search is broken" — one key's difference looked like a defect. Moving the jump to a feature with no default keybinding removes the collision.
- **Everything else is unaffected.** Usage sites such as `new User()` are not class declarations, so the convention jump does not fire and the built-in locator handles them as usual. Normal PHP definition jumps (variables, methods, parameters, non-resource classes) are untouched.

## Editor setup

The extension loads inside phpactor's language server, so any editor with an LSP client
works. Point the client at **your project's** `vendor/bin/phpactor` — the one whose
autoloader can see this package — not at a phpactor installed elsewhere.

### VS Code

Install the official client, then tell it which binary to run:

```sh
code --install-extension phpactor.vscode-phpactor
```

`.vscode/settings.json` in your project:

```json
{
    "phpactor.path": "vendor/bin/phpactor"
}
```

The client bundles its own phpactor, and that copy cannot autoload this package. Setting
`phpactor.path` is what makes the difference between the extension working and silently
doing nothing.

**Trust the folder.** VS Code opens an unfamiliar folder in Restricted Mode, and no
language server starts there — verified by opening a project and finding no phpactor
process at all. Nothing errors; the features are simply absent. Accept the trust prompt,
or use *Manage Workspace Trust* from the command palette.

Between these two, "I installed it and nothing happens" has two likely causes. Check
Restricted Mode first, since it costs one click.

`phpactor.config` in the same settings file does **not** work for loading this extension.
The server reads `container.extension_classes` before it merges the client's
initialization options, so `.phpactor.json` remains the only route.

### Neovim

```lua
vim.lsp.start({
  name = 'phpactor',
  cmd = { 'vendor/bin/phpactor', 'language-server' },
  root_dir = vim.fs.dirname(vim.fs.find({ 'composer.json' }, { upward = true })[1]),
})
```

### What an ambiguous jump looks like

When a URI names more than one class — the context-prefix case above — the server does not
return a list of locations. It asks the editor to show a picker and waits for an answer:

```
Goto type
  ▸ MyVendor\MyProject\Resource\Page\Admin\Error400
  ▸ MyVendor\MyProject\Resource\Page\Content\Error400
```

Choosing an entry jumps to that class. Candidates are listed by fully qualified name, in
directory order.

Reference search (`textDocument/references`) uses the same resolution and the same rule:
a site whose URI names two or more classes is treated as unresolved and does not count
as a reference. Keeping the two features on one judgment avoids explaining and
implementing them twice.

The reference search finds every site that **resolves to the same file** as the one
under the cursor — not every site with the same URI string. Two mini-apps may both use
`'app://self/article'`; each string is resolved from its own file's position, so a
reference is reported only when it points at the file you asked about.

## Measuring how much of a real project this covers

Fixtures prove a feature fires once. They do not say what fraction of a real
application it reaches. `tools/coverage.php` answers that: it parses every PHP file
in a target project, collects every site this extension claims to answer
(resource URIs, query names, route paths, resource class declarations), asks a real
language server for a definition at each one, and reports the hit rate plus every miss
with its file and line.

```sh
cd /path/to/your-app && php /path/to/bear-phpactor-extension/bin/bear-phpactor-init
cd /path/to/your-app && vendor/bin/phpactor config:trust --trust
php /path/to/bear-phpactor-extension/tools/coverage.php /path/to/your-app
```

Measured on [BEAR.Kata](https://github.com/bearsunday/BEAR.Kata), BEAR.Sunday's own
public tutorial application: 474 sites, **0 mismatches**. 388 sites got the expected
answer; the remaining 85 are sites where returning nothing is the correct answer (most
are resource classes with no matching JSON Schema file under the naming convention —
Kata's tutorial-sized codebase does not give every resource one). The expected file for
each site is computed independently of this extension's own code, directly from the
BEAR.Sunday naming convention, so a mistake shared by both would still surface as a
mismatch here.

A separate probe for false positives — jumping from a site that should not jump — found
**0 misfires** across 948 checks (`tools/misfire.php`). Completion candidates are not
covered by either tool; verifying those needs inspecting each suggestion list, which is
a different kind of check.

## Known limitations

- **SQL jumps land at file start (0,0).** The Router and Resource URI locators land on the class-declaration name, and both JSON Schema locators (attribute and convention) land on the `title` key inside the schema file. Only the SQL locator returns the `.sql` file's first line. Cosmetic, but visible in the editor.
- **The resource-class scan regex can false-positive.** `Project::resourcePhpFiles()` matches files whose text contains `extends ... ResourceObject`; a docblock sentence or a class extending `MyResourceObject` can match, which bloats URI completion candidates.
- **Reference search only reads files from disk, and only inside psr-4 directories.** A `#[Link]` you have typed but not saved does not appear in the results, and neither do sites in files outside the `autoload`/`autoload-dev` psr-4 roots — `bin/*.php`, `public/index.php`, and the like. Measured on BEAR.Kata: 16 of the sites a plain text search finds live in `bin/`. The definition jump is unaffected; it works from the buffer the editor sends.
- **Windows absolute-path detection is incomplete in psr-4 resolution.** Paths starting with `/` are treated as absolute; drive-letter paths (`C:/src`) are handled by `PathGuard` but not by the psr-4 directory resolution side.
- **"Find all references" with `includeDeclaration: true` lists the class itself as the declaration.** On a resource class declaration name, the definition chain no longer resolves to `var/json_schema/<name>.json` (the convention jump moved to Go to Type Definition), so the built-in locator answers and VS Code's "Find All References" shows the class itself in the declaration slot before the actual reference sites.

## Development

```bash
vendor/bin/phpunit
vendor/bin/phpcs
vendor/bin/phpstan analyse
```
