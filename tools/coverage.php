<?php

declare(strict_types=1);

/**
 * Measure how often the extension answers definition jumps, and whether each
 * destination is the one the BEAR.Sunday conventions imply.
 *
 * The script scans the target app for every place this extension should
 * answer (resource URIs, SQL query names, route paths, resource class names),
 * asks the real phpactor language-server for textDocument/definition (and
 * textDocument/typeDefinition for class names — the convention jump moved
 * there, PLAN.md §2.6), and compares each returned destination against an
 * expectation computed here from the conventions alone.
 *
 * The lib/ resolvers are deliberately not used for the expectation: if the
 * resolver and the meter shared one implementation, a wrong convention would
 * confirm itself and never surface. The 2026-08-27 bug (a mini-app class
 * jumping to the project root's schema) was an app-attribution mistake; the
 * meter decides the app independently, so the same mistake shows up as a
 * mismatch.
 *
 *   php tools/coverage.php /path/to/bear-app
 *
 * Output: per kind, the number of collected sites plus counts of match /
 * silent / mismatch / undeterminable / picker / no-response, plus one detail
 * block per mismatch (source file:line, expected destination, actual
 * destination). match is a jump that landed on the expected file; silent is
 * a site that correctly stayed quiet because no convention file exists. The
 * two are never summed — together they make a feature that almost never
 * fires look as if it works everywhere (PLAN.md §2.14).
 */

$app = $argv[1] ?? null;
if ($app === null || !is_dir($app)) {
    fwrite(STDERR, "usage: php tools/coverage.php <bear-sunday-app-dir>\n");
    exit(1);
}
$app = (string) realpath($app);
require dirname(__DIR__) . '/vendor/autoload.php';
$bin = dirname(__DIR__) . '/vendor/bin/phpactor';

/** 対象アプリの PHP ファイルを全部集める（vendor は除く） */
function phpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (str_contains($p, '/vendor/') || !str_ends_with($p, '.php')) {
            continue;
        }
        $out[] = $p;
    }
    sort($out);

    return $out;
}

/** バイトオフセットを LSP の 0起点 行/桁 に変換する */
function toPosition(string $text, int $offset): array
{
    $before = substr($text, 0, $offset);
    $line = substr_count($before, "\n");
    $lineStart = (int) strrpos("\n" . $before, "\n");

    return ['line' => $line, 'character' => $offset - $lineStart];
}

/**
 * この拡張が答えるべき場所を数え上げる。
 *
 * 正規表現ではなく構文解析で集める。コメントの中に書かれた 'app://self/user' のような
 * 文字列を数えてしまうと、拡張が正しく「飛ばない」と判断した場所を「外した」と誤って
 * 数えることになるため（実際に一度そうなった）。
 * ただし @Query だけは docblock の中にあるのが正しい書き方なので、そこだけトークンを見る。
 *
 * 種類ごとに [file, offset, 対象文字列] を返す。
 */
function collectSites(string $app): array
{
    $parser = new Microsoft\PhpParser\Parser();
    $sites = ['resource_uri' => [], 'resource_uri_other_host' => [], 'sql_query' => [], 'route_path' => [], 'schema_class' => []];

    foreach (phpFiles($app) as $file) {
        $text = (string) file_get_contents($file);
        $root = $parser->parseSourceFile($text);
        $isRoute = basename($file) === 'aura.route.php';
        $inResourceDir = (bool) preg_match('#/Resource/(App|Page)/#', str_replace('\\', '/', $file));

        foreach ($root->getDescendantNodes() as $node) {
            // クラス宣言名（規約による JSON Schema ジャンプ）
            if ($inResourceDir && $node instanceof Microsoft\PhpParser\Node\Statement\ClassDeclaration) {
                $name = $node->name;
                if ($name !== null) {
                    // The namespace is captured too: the schema convention
                    // derives its candidates from the \Resource\App\ or
                    // \Resource\Page\ sub-namespace, not from the file path.
                    $ns = $node->getNamespaceDefinition();
                    $nsText = $ns !== null && $ns->name instanceof Microsoft\PhpParser\Node\QualifiedName
                        ? (string) $ns->name->getText($text)
                        : '';
                    $sites['schema_class'][] = [$file, $name->getStartPosition(), (string) $name->getText($text), $nsText];
                }
                continue;
            }

            if (!$node instanceof Microsoft\PhpParser\Node\StringLiteral) {
                continue;
            }

            $value = $node->getStringContentsText();
            $offset = $node->getStartPosition() + 1;   // 開きクォートの内側

            // self 以外のホストも数える。アプリは ImportApp で別パッケージを
            // 別ホストに割り当てられる (例: app://tags/ -> Acme\Tags)。
            // self だけ数えていたせいで、対応漏れが測定に現れていなかった。
            if (preg_match('#^(app|page)://([a-z0-9_-]+)/#', $value, $hm)) {
                $bucket = $hm[2] === 'self' ? 'resource_uri' : 'resource_uri_other_host';
                $sites[$bucket][] = [$file, $offset, $value];
                continue;
            }

            // #[DbQuery('name')] の第1引数
            // #[DbQuery('name')] の第1引数だけ。type: 'row' のような名前付き引数は対象外
            // （拡張も第1引数しか見ないので、ここで数えると外したことにされてしまう）
            $arg = $node->getParent();
            $list = $arg?->getParent();
            $attr = $list?->getParent();
            if ($attr instanceof Microsoft\PhpParser\Node\Attribute
                && str_contains((string) $attr->getText(), 'DbQuery')
                && $arg instanceof Microsoft\PhpParser\Node\Expression\ArgumentExpression
                && $arg->name === null
                && ($list?->children[0] ?? null) === $arg) {
                $sites['sql_query'][] = [$file, $offset, $value];
                continue;
            }

            if ($isRoute && str_starts_with($value, '/')) {
                $sites['route_path'][] = [$file, $offset, $value];
            }
        }

        // @Query("name") は docblock の中にあるのが正しい
        if (preg_match_all('#@Query\s*\(\s*[\'"]([a-z0-9_]+)[\'"]#i', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$val, $off]) {
                $sites['sql_query'][] = [$file, $off, $val];
            }
        }
    }

    return $sites;
}

// ---- 期待する行き先の計算 (規約からの独立実装) --------------------------
//
// The expected destination of each site is computed here from the BEAR.Sunday
// conventions alone. The lib/ resolvers (JsonSchemaPathResolver, Project,
// SqlDefinitionLocator, ...) are deliberately not called: if the resolver and
// the meter shared one implementation, a wrong convention would be confirmed
// by itself and never surface. The 2026-08-27 bug (a mini-app class jumping
// to the project root's schema) was an app-attribution mistake; the meter
// decides the app independently, so the same mistake shows up as a mismatch.

/**
 * The app root a file belongs to: the directory before the /Resource/App/ or
 * /Resource/Page/ marker. Independent copy of ProjectLocator::enclosingAppDir().
 */
function appDirOf(string $file): ?string
{
    $normalized = str_replace('\\', '/', $file);
    foreach (['/Resource/App/', '/Resource/Page/'] as $marker) {
        $at = strpos($normalized, $marker);
        if ($at !== false) {
            return substr($normalized, 0, $at);
        }
    }

    return null;
}

/**
 * The project root (composer.json with a non-empty psr-4) above a file.
 * Independent copy of ProjectLocator::locate().
 */
function projectRootOf(string $file): ?string
{
    $dir = dirname($file);
    while (true) {
        $composerJson = $dir . '/composer.json';
        if (is_file($composerJson)) {
            $json = json_decode((string) file_get_contents($composerJson), true);
            $hasPsr4 = false;
            if (is_array($json)) {
                foreach (['autoload', 'autoload-dev'] as $section) {
                    $psr4 = $json[$section]['psr-4'] ?? null;
                    if (is_array($psr4) && $psr4 !== []) {
                        $hasPsr4 = true;
                        break;
                    }
                }
            }
            if ($hasPsr4) {
                return $dir;
            }
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            return null;
        }
        $dir = $parent;
    }
}

/**
 * Absolute psr-4 directories of a project (autoload + autoload-dev).
 *
 * @return list<string>
 */
function psr4Dirs(string $root): array
{
    $json = json_decode((string) file_get_contents($root . '/composer.json'), true);
    if (!is_array($json)) {
        return [];
    }
    $dirs = [];
    foreach (['autoload', 'autoload-dev'] as $section) {
        $psr4 = $json[$section]['psr-4'] ?? null;
        if (!is_array($psr4)) {
            continue;
        }
        foreach ($psr4 as $dir) {
            foreach ((array) $dir as $d) {
                if (!is_string($d)) {
                    continue;
                }
                $resolved = str_starts_with($d, '/') ? rtrim($d, '/') : $root . '/' . rtrim($d, '/');
                if (!in_array($resolved, $dirs, true)) {
                    $dirs[] = $resolved;
                }
            }
        }
    }

    return $dirs;
}

/**
 * Resource roots visible from a reference file, most specific first: the
 * enclosing app's Resource dir, then every psr-4 dir that has one.
 * Independent copy of Project::resourceRoots().
 *
 * @param list<string> $psr4Dirs
 * @return list<string>
 */
function resourceRoots(string $refFile, array $psr4Dirs): array
{
    $roots = [];
    $appDir = appDirOf($refFile);
    if ($appDir !== null && isUnderPsr4Dir($appDir, $psr4Dirs)) {
        $roots[] = $appDir . '/Resource';
    }
    foreach ($psr4Dirs as $dir) {
        $resourceDir = $dir . '/Resource';
        if (is_dir($resourceDir) && !in_array($resourceDir, $roots, true)) {
            $roots[] = $resourceDir;
        }
    }

    return $roots;
}

/**
 * Whether a directory is a psr-4 directory or lives under one.
 *
 * @param list<string> $psr4Dirs
 */
function isUnderPsr4Dir(string $dir, array $psr4Dirs): bool
{
    foreach ($psr4Dirs as $base) {
        if ($dir === $base || str_starts_with($dir, $base . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * URI path segment to a PascalCase class-name segment: blog-posting ->
 * BlogPosting, blogPosting -> BlogPosting. Independent copy of
 * ResourceUri::pascalSegment().
 */
function pascalSegment(string $segment): string
{
    return implode('', array_map('ucfirst', explode('-', $segment)));
}

/**
 * PascalCase to a separator-joined lowercase form (BodyTypeDemo ->
 * body-type-demo / body_type_demo). Independent copy of
 * JsonSchemaPathResolver::separatorCase().
 */
function separatorCase(string $value, string $separator): string
{
    $result = '';
    $previous = '';
    $length = strlen($value);
    for ($i = 0; $i < $length; $i++) {
        $current = $value[$i];
        if ($current === '_' || $current === '-' || $current === ' ') {
            $result = appendSeparator($result, $separator);
            $previous = $current;
            continue;
        }
        if (
            ctype_upper($current)
            && $result !== ''
            && $previous !== ''
            && $previous !== '_'
            && $previous !== '-'
            && $previous !== ' '
            && !ctype_upper($previous)
        ) {
            $result .= $separator;
        }
        $result .= strtolower($current);
        $previous = $current;
    }

    return strtolower($result);
}

function appendSeparator(string $result, string $separator): string
{
    if ($result !== '' && substr($result, -1) !== $separator) {
        return $result . $separator;
    }

    return $result;
}

/**
 * The var/json_schema candidate file names for a resource class, in the fixed
 * priority order of JsonSchemaPathResolver::conventionCandidates().
 *
 * @param list<string> $segments
 * @return list<string>
 */
function schemaCandidates(array $segments): array
{
    $candidates = [
        implode('/', array_map(static fn (string $s): string => separatorCase($s, '-'), $segments)) . '.json',
        implode('/', array_map('lcfirst', $segments)) . '.json',
        implode('_', array_map(static fn (string $s): string => separatorCase($s, '_'), $segments)) . '.json',
        lcfirst(implode('', $segments)) . '.json',
        implode('-', array_map(static fn (string $s): string => separatorCase($s, '-'), $segments)) . '.json',
    ];

    return array_values(array_unique($candidates));
}

/**
 * Resource name segments from the namespace: the part after \Resource\App\ or
 * \Resource\Page\, plus the class name. Independent copy of
 * JsonSchemaPathResolver::resourceSegments().
 *
 * @return list<string>
 */
function resourceSegments(string $namespace, string $className): array
{
    $normalized = '\\' . trim($namespace, '\\') . '\\';
    $subNamespace = null;
    foreach (['\\Resource\\App\\', '\\Resource\\Page\\'] as $marker) {
        $index = strpos($normalized, $marker);
        if ($index !== false) {
            $subNamespace = substr($normalized, $index + strlen($marker));
            break;
        }
    }
    if ($subNamespace === null) {
        return [];
    }
    $segments = array_values(array_filter(
        explode('\\', $subNamespace),
        static fn (string $s): bool => $s !== ''
    ));
    $segments[] = $className;

    return $segments;
}

/**
 * Parse a resource URI into scheme and path. Independent copy of
 * ResourceUri::fromString().
 *
 * @return array{scheme: string, path: string}|null
 */
function parseResourceUri(string $value): ?array
{
    $value = (string) preg_replace('/[{?#].*$/s', '', $value);
    if (!preg_match('#^(?<scheme>[a-z]+)://(?<host>[^/]+)(?:/(?<path>.*))?$#', $value, $m)) {
        return null;
    }
    if (!in_array($m['scheme'], ['app', 'page'], true)) {
        return null;
    }
    $segments = array_values(array_filter(explode('/', $m['path'] ?? ''), static fn (string $v): bool => $v !== ''));
    if ($segments === []) {
        return null;
    }

    return ['scheme' => $m['scheme'], 'path' => implode('/', $segments)];
}

/**
 * Expected destination of a site. The status is one of:
 *   'file'    the extension should land on this exact file
 *   'none'    the extension should stay silent (no convention file exists)
 *   'unknown' the convention cannot be computed here (counted, not judged)
 *
 * @return array{status: string, file: ?string, note?: string}
 */
function expectedSchemaFile(string $refFile, string $className, string $namespace): array
{
    $appDir = appDirOf($refFile);
    if ($appDir === null) {
        return ['status' => 'unknown', 'file' => null];
    }
    $projectRoot = projectRootOf($refFile);
    if ($projectRoot === null) {
        return ['status' => 'unknown', 'file' => null];
    }
    $psr4Dirs = psr4Dirs($projectRoot);
    $schemaRoot = in_array($appDir, $psr4Dirs, true) ? $projectRoot : $appDir;

    $segments = resourceSegments($namespace, $className);
    if ($segments === []) {
        return ['status' => 'none', 'file' => null, 'note' => $schemaRoot . ' に var/json_schema の候補が無い'];
    }
    foreach (schemaCandidates($segments) as $candidate) {
        $path = $schemaRoot . '/var/json_schema/' . $candidate;
        if (is_file($path)) {
            return ['status' => 'file', 'file' => $path];
        }
    }

    return ['status' => 'none', 'file' => null, 'note' => $schemaRoot . ' に var/json_schema の候補が無い'];
}

/**
 * Expected resource class file for a resource URI. The app of the reference
 * file decides the resource root (its own Resource dir first, then psr-4
 * roots); the extension's one-level-deeper context-prefix fallback is
 * mirrored, and it only jumps when exactly one candidate exists.
 */
function expectedResourceFile(string $refFile, string $uriValue): array
{
    $uri = parseResourceUri($uriValue);
    if ($uri === null) {
        // app://self/ with an empty path: the extension cannot resolve it
        // either (ResourceUri::fromString returns null), so it stays silent.
        return ['status' => 'none', 'file' => null, 'note' => 'URI のパスが空'];
    }
    $schemeDir = $uri['scheme'] === 'app' ? 'App' : 'Page';
    $filePath = $schemeDir . '/' . implode('/', array_map('pascalSegment', explode('/', $uri['path']))) . '.php';

    $projectRoot = projectRootOf($refFile);
    if ($projectRoot === null) {
        return ['status' => 'none', 'file' => null, 'note' => 'composer.json (psr-4) が見つからない'];
    }
    $psr4Dirs = psr4Dirs($projectRoot);
    $roots = resourceRoots($refFile, $psr4Dirs);
    if ($roots === []) {
        return ['status' => 'none', 'file' => null, 'note' => 'リソースの根が見つからない'];
    }

    foreach ($roots as $root) {
        $file = $root . '/' . $filePath;
        if (is_file($file)) {
            return ['status' => 'file', 'file' => $file];
        }
    }

    // The extension also tries one level deeper (context prefixes such as
    // Page/Content/Error400.php for page://self/error-400) and jumps only
    // when exactly one candidate exists. Mirror that.
    $base = $roots[0] . '/' . $schemeDir;
    $slash = strpos($filePath, '/');
    $rest = $slash === false ? null : substr($filePath, $slash + 1);
    if ($rest !== null && is_dir($base)) {
        $candidates = [];
        foreach (scandir($base) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $sub = $base . '/' . $entry;
            if (!is_dir($sub)) {
                continue;
            }
            $candidate = $sub . '/' . $rest;
            if (is_file($candidate)) {
                $candidates[] = $candidate;
            }
        }
        sort($candidates);
        if (count($candidates) === 1) {
            return ['status' => 'file', 'file' => $candidates[0]];
        }
    }

    return ['status' => 'none', 'file' => null, 'note' => $roots[0] . ' に ' . $filePath . ' が無い'];
}

/**
 * Expected SQL file for a query name: <project root>/var/db/sql/<name>.sql.
 */
function expectedSqlFile(string $refFile, string $queryName): array
{
    if (str_contains($queryName, '..') || str_contains($queryName, '\\') || str_starts_with($queryName, '/')) {
        return ['status' => 'none', 'file' => null, 'note' => 'クエリ名が SQL ディレクトリの外を指す'];
    }
    $projectRoot = projectRootOf($refFile);
    if ($projectRoot === null) {
        return ['status' => 'none', 'file' => null, 'note' => 'composer.json (psr-4) が見つからない'];
    }
    $path = $projectRoot . '/var/db/sql/' . $queryName . '.sql';
    if (is_file($path)) {
        return ['status' => 'file', 'file' => $path];
    }

    return ['status' => 'none', 'file' => null, 'note' => $path . ' が無い'];
}

/**
 * Expected Page resource file for a route path. The extension returns every
 * psr-4 dir that has the file, so more than one candidate is undeterminable
 * (the server would show a picker).
 */
function expectedRouteFile(string $refFile, string $routePath): array
{
    $projectRoot = projectRootOf($refFile);
    if ($projectRoot === null) {
        return ['status' => 'none', 'file' => null, 'note' => 'composer.json (psr-4) が見つからない'];
    }
    $relative = 'Resource/Page' . routeToResourceFileName($routePath);
    if (str_contains($relative, '..') || str_contains($relative, '\\') || str_starts_with($relative, '/')) {
        return ['status' => 'none', 'file' => null, 'note' => 'ルートパスが Resource/Page の外を指す'];
    }
    $found = [];
    foreach (psr4Dirs($projectRoot) as $dir) {
        $file = $dir . '/' . $relative;
        if (is_file($file)) {
            $found[] = $file;
        }
    }
    if (count($found) === 1) {
        return ['status' => 'file', 'file' => $found[0]];
    }
    if (count($found) > 1) {
        return ['status' => 'unknown', 'file' => null];
    }

    return ['status' => 'none', 'file' => null, 'note' => $projectRoot . ' に ' . $relative . ' が無い'];
}

/**
 * Route path to a Page resource file name: '/index' -> '/Index.php',
 * '/user-profile' -> '/UserProfile.php'. Independent copy of
 * RouterUtil::toResourceFileName().
 */
function routeToResourceFileName(string $path): string
{
    $segments = explode('/', $path);
    $converted = array_map(static function (string $segment): string {
        $words = array_map(
            static fn (string $word): string => ucfirst(strtolower($word)),
            explode('-', $segment)
        );

        return implode('', $words);
    }, $segments);

    return implode('/', $converted) . '.php';
}

/**
 * Normalize an LSP file:// URI to a real filesystem path. The server may
 * return /tmp while the app lives under /private/tmp (macOS symlink), so both
 * sides are realpath'd before comparison.
 */
function realpathUri(?string $target): ?string
{
    if ($target === null) {
        return null;
    }
    $path = (string) preg_replace('#^file://#', '', $target);
    $path = rawurldecode($path);
    $real = realpath($path);

    return $real === false ? $path : $real;
}

/**
 * Whether a path is a JSON schema file (var/json_schema or var/json_validate).
 */
function isSchemaFile(string $path): bool
{
    return (bool) preg_match('#/var/json_(schema|validate)/#', $path);
}

/**
 * One detail block for a mismatch: where the site is, what was expected, and
 * where the server actually jumped.
 */
function mismatchLine(string $where, array $expected, ?string $actual, string $app): string
{
    if ($expected['status'] === 'file') {
        $expectedLabel = str_replace($app . '/', '', (string) $expected['file']);
    } else {
        $note = str_replace($app . '/', '', (string) ($expected['note'] ?? '規約上のファイルが存在しない'));
        $expectedLabel = '(沈黙) — ' . $note;
    }
    $actualLabel = $actual === null ? '(応答なし)' : str_replace($app . '/', '', $actual);

    return sprintf("%s\n    期待: %s\n    実際: %s", $where, $expectedLabel, $actualLabel);
}

// ---- LSP セッション ----------------------------------------------------

$proc = proc_open([$bin, 'language-server'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app);
if (!is_resource($proc)) {
    fwrite(STDERR, "could not start phpactor\n");
    exit(1);
}
stream_set_blocking($pipes[1], false);

$send = function (array $msg) use ($pipes): void {
    $body = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    fwrite($pipes[0], "Content-Length: " . strlen($body) . "\r\n\r\n" . $body);
    fflush($pipes[0]);
};

/**
 * 1メッセージ読む。返り値は3種類:
 *   array  … メッセージ
 *   false  … 期限切れ (サーバーが黙っている)
 *   null   … EOF (サーバーが死んだ)
 *
 * 期限を持たないと、サーバーが応答しなくなったとき永久に待つ。
 * 実際に実アプリの測定が25分間ブロックした。
 */
$read = function (float $deadline) use ($pipes) {
    $waitFor = function () use ($pipes, $deadline): bool {
        $remain = $deadline - microtime(true);
        if ($remain <= 0) {
            return false;
        }
        $r = [$pipes[1]];
        $w = $x = null;

        return @stream_select($r, $w, $x, 0, (int) min($remain, 0.5) * 1000000 + 1000) > 0;
    };

    $header = '';
    while (!str_contains($header, "\r\n\r\n")) {
        if (microtime(true) > $deadline) {
            return false;
        }
        if (!$waitFor()) {
            continue;
        }
        $c = fread($pipes[1], 1);
        if ($c === false) {
            return null;
        }
        if ($c === '') {
            if (feof($pipes[1])) {
                return null;
            }
            continue;
        }
        $header .= $c;
    }

    preg_match('/Content-Length: (\d+)/i', $header, $m);
    $len = (int) $m[1];
    $body = '';
    while (strlen($body) < $len) {
        if (microtime(true) > $deadline) {
            return false;
        }
        if (!$waitFor()) {
            continue;
        }
        $c = fread($pipes[1], $len - strlen($body));
        if ($c === false) {
            return null;
        }
        if ($c === '' && feof($pipes[1])) {
            return null;
        }
        $body .= $c;
    }

    return json_decode($body, true);
};

/**
 * id が一致する応答を待つ。false=期限切れ、null=EOF。
 *
 * 途中でサーバーからクライアントへの「要求」(id と method の両方を持つ) が来たら
 * 返事をする。返事をしないとサーバーはそこで待ち続ける。
 *
 * とくに window/showMessageRequest が重要で、phpactor は定義ジャンプの候補が
 * 複数あるとき Location の配列を返さず、この選択ダイアログに載せてくる。
 * 実際これに返事をしなかったせいで測定が15秒無応答になった。
 */
$pickerCount = 0;
$readUntilId = function (int $id, float $timeout = 15.0) use ($read, $send, &$pickerCount) {
    $deadline = microtime(true) + $timeout;
    for ($i = 0; $i < 2000; $i++) {
        $msg = $read($deadline);
        if ($msg === false || $msg === null) {
            return $msg;
        }

        // サーバー -> クライアントの要求。返事をしないと相手が止まる
        if (isset($msg['id'], $msg['method'])) {
            $reply = null;
            if ($msg['method'] === 'window/showMessageRequest') {
                $actions = $msg['params']['actions'] ?? [];
                $reply = $actions[0] ?? null;
                $pickerCount++;
            }
            $send(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => $reply]);
            continue;
        }

        if (($msg['id'] ?? null) === $id) {
            return $msg;
        }
    }

    return false;
};

$send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
    'processId' => getmypid(), 'rootUri' => 'file://' . $app, 'capabilities' => new stdClass(),
]]);
$readUntilId(1);
$send(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => new stdClass()]);

fwrite(STDERR, "indexing...\n");
$indexDeadline = microtime(true) + 900.0;
$indexStart = microtime(true);
while (true) {
    $msg = $read($indexDeadline);
    if ($msg === false) {
        fwrite(STDERR, "警告: インデックス完了の通知が900秒来なかった。そのまま測定に進む\n");
        break;
    }
    if ($msg === null) {
        fwrite(STDERR, "エラー: 言語サーバーが落ちた\n");
        exit(1);
    }
    if (($msg['method'] ?? '') === 'window/showMessage'
        && str_contains($msg['params']['message'] ?? '', 'Done indexing')) {
        break;
    }
}
fwrite(STDERR, sprintf("indexed in %.1fs\n", microtime(true) - $indexStart));

// ---- 測定 --------------------------------------------------------------

$sites = collectSites($app);
$id = 100;
$opened = [];
$results = [];

foreach ($sites as $kind => $list) {
    $results[$kind] = ['match' => 0, 'silent' => 0, 'mismatch' => [], 'unknown' => 0, 'picker' => 0, 'no_response' => []];
    foreach ($list as $site) {
        [$file, $offset, $value] = $site;
        $extra = $site[3] ?? null;
        $text = (string) file_get_contents($file);
        $uri = 'file://' . $file;
        if (!isset($opened[$file])) {
            $send(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => $uri, 'languageId' => 'php', 'version' => 1, 'text' => $text],
            ]]);
            $opened[$file] = true;
        }
        $send(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $kind === 'schema_class' ? 'textDocument/typeDefinition' : 'textDocument/definition', 'params' => [
            'textDocument' => ['uri' => $uri],
            'position' => toPosition($text, $offset),
        ]]);
        $pickerBefore = $pickerCount;
        $res = $readUntilId($id);

        $pos = toPosition($text, $offset);
        $where = sprintf('%s:%d  %s', str_replace($app . '/', '', $file), $pos['line'] + 1, $value);

        if ($res === null) {
            fwrite(STDERR, "エラー: 言語サーバーが落ちた ({$where})\n");
            break 2;
        }
        if ($res === false) {
            $results[$kind]['no_response'][] = $where . '  [15秒無応答]';
            continue;
        }

        // A selection dialog means the server found several candidates and
        // asked the client to choose. The meter auto-picks the first action
        // (readUntilId) and cannot tell which candidate is right, so these
        // are counted separately — never as a match or a mismatch.
        if ($pickerCount > $pickerBefore) {
            $results[$kind]['picker']++;
            continue;
        }

        $result = $res['result'] ?? null;
        $target = is_array($result) ? ($result['uri'] ?? ($result[0]['uri'] ?? null)) : null;
        $actual = realpathUri($target);

        $expected = match ($kind) {
            'schema_class' => expectedSchemaFile($file, $value, (string) $extra),
            'resource_uri' => expectedResourceFile($file, $value),
            'sql_query' => expectedSqlFile($file, $value),
            'route_path' => expectedRouteFile($file, $value),
            'resource_uri_other_host' => ['status' => 'unknown', 'file' => null],
        };

        if ($expected['status'] === 'unknown') {
            $results[$kind]['unknown']++;
            continue;
        }

        if ($expected['status'] === 'file') {
            if ($actual !== null && $actual === $expected['file']) {
                $results[$kind]['match']++;
            } else {
                $results[$kind]['mismatch'][] = mismatchLine($where, $expected, $actual, $app);
            }
            continue;
        }

        // Expected 'none': the extension should stay silent. For class names
        // the built-in type locator answers the class's own file when the
        // extension stays silent, so only a schema-file landing is wrong (the
        // 2026-08-27 cross-app jump). For the other kinds any landing is a
        // mismatch.
        //
        // A correctly silent site lands in its own bucket, never in 'match':
        // match means the jump actually fired and landed on the expected
        // file, silent means staying quiet was correct and the extension did
        // stay quiet. Summing them makes a feature that almost never fires
        // look as if it works at every site — BEAR.Kata has schema files for
        // only 16 of its 85 class-name sites, yet "85/85 (100%)" was once
        // reported because built-in type-locator answers (the class's own
        // file) were counted as hits. PLAN.md §2.14 retracted that banner;
        // do not reintroduce the sum.
        $correctlySilent = $actual === null;
        if ($kind === 'schema_class') {
            $correctlySilent = $actual === null || !isSchemaFile($actual);
        }
        if ($correctlySilent) {
            $results[$kind]['silent']++;
        } else {
            $results[$kind]['mismatch'][] = mismatchLine($where, $expected, $actual, $app);
        }
    }
}

$send(['jsonrpc' => '2.0', 'id' => 9999, 'method' => 'shutdown', 'params' => new stdClass()]);
$readUntilId(9999);
foreach ($pipes as $p) {
    @fclose($p);
}
proc_terminate($proc);

// ---- 報告 --------------------------------------------------------------

$labels = [
    'resource_uri' => 'リソースURI(self)   → クラス',
    'resource_uri_other_host' => 'リソースURI(他ホスト) → クラス',
    'sql_query' => 'クエリ名           → SQLファイル',
    'route_path' => 'ルートパス         → Pageクラス',
    'schema_class' => 'クラス宣言名       → JSON Schema (型定義)',
];

echo "\n対象: {$app}\n";
echo str_repeat('=', 72), "\n";

// match (一致) and silent (沈黙) are printed as separate columns and never
// summed: match means the jump fired and landed on the expected file, silent
// means no convention file exists and the extension correctly stayed quiet.
// Added together they read as "works at every site" even when the jump
// almost never fires — the same exaggeration PLAN.md §2.14 retracted when
// built-in type-locator answers were counted as hits. The 地点 column is the
// number of collected sites per kind, so a broken collection (e.g. 0 route
// paths) shows up instead of being indistinguishable from "nothing to do".
$total = ['match' => 0, 'silent' => 0, 'mismatch' => 0, 'unknown' => 0, 'picker' => 0, 'no_response' => 0];
foreach ($results as $kind => $r) {
    $total['match'] += $r['match'];
    $total['silent'] += $r['silent'];
    $total['mismatch'] += count($r['mismatch']);
    $total['unknown'] += $r['unknown'];
    $total['picker'] += $r['picker'];
    $total['no_response'] += count($r['no_response']);
    printf(
        "%-34s 地点 %4d  一致 %4d  沈黙 %4d  不一致 %4d  判定不能 %4d  選択 %4d  応答なし %4d\n",
        $labels[$kind] ?? $kind,
        count($sites[$kind]),
        $r['match'],
        $r['silent'],
        count($r['mismatch']),
        $r['unknown'],
        $r['picker'],
        count($r['no_response'])
    );
}
echo str_repeat('-', 72), "\n";
printf(
    "%-34s 地点 %4d  一致 %4d  沈黙 %4d  不一致 %4d  判定不能 %4d  選択 %4d  応答なし %4d\n",
    '合計',
    array_sum(array_map('count', $sites)),
    $total['match'],
    $total['silent'],
    $total['mismatch'],
    $total['unknown'],
    $total['picker'],
    $total['no_response']
);
printf("\n  速度はこの道具では測らない (種類ごとにファイルの開かれ方が違い公平に比べられない)。\n");
printf("  tools/latency.php を使うこと。\n");

foreach ($results as $kind => $r) {
    if ($r['mismatch'] !== []) {
        echo "\n不一致 — ", ($labels[$kind] ?? $kind), "\n";
        foreach ($r['mismatch'] as $m) {
            echo $m, "\n";
        }
    }
    if ($r['no_response'] !== []) {
        echo "\n応答なし — ", ($labels[$kind] ?? $kind), "\n";
        foreach ($r['no_response'] as $m) {
            echo "  ", $m, "\n";
        }
    }
}
echo "\n";
