<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Router;

use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Util\PathGuard;
use Suzumaze\BearPhpactor\Util\PhpClassDeclaration;
use Suzumaze\BearPhpactor\Util\ProjectLocator;
use Microsoft\PhpParser\Parser;
use Phpactor\ReferenceFinder\DefinitionLocator;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\ReferenceFinder\Exception\UnsupportedDocument;
use Phpactor\ReferenceFinder\TypeLocation;
use Phpactor\ReferenceFinder\TypeLocations;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\TypeFactory;

/**
 * aura.route.php のルートパスから対応する Page リソースクラスへの定義ジャンプ。
 *
 * 変換規則は idea-php-bearsunday-plugin の RouterGotoDeclarationHandler に合わせる:
 * ルートパス '/index' は 'Resource/Page/Index.php' に対応する。ただしリソースの
 * 起点ディレクトリは決め打ちせず、対象プロジェクトの composer.json の
 * autoload.psr-4 から取る (例: "MyVendor\\MyProject\\": "src/" なら
 * src/Resource/Page/Index.php を探す)。プロジェクトルートは ProjectLocator、
 * ルートパスの文字列抽出は StringLiteralAtOffset、パスの安全検査は PathGuard と、
 * 4機能共通の部品を使う。
 */
final class RouterDefinitionLocator implements DefinitionLocator
{
    private const ROUTE_FILE = 'aura.route.php';

    private const RESOURCE_DIR = 'Resource/Page';

    public function __construct(
        private Parser $parser = new Parser(),
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
    ) {
    }

    public function locateDefinition(TextDocument $document, ByteOffset $byteOffset): TypeLocations
    {
        $uri = $document->uri();
        if ($uri === null || basename($uri->path()) !== self::ROUTE_FILE) {
            throw new UnsupportedDocument(sprintf('Not an Aura.Router route file: "%s"', (string) $uri));
        }

        $path = $this->routePathAtOffset($document, $byteOffset);
        if ($path === null) {
            throw new CouldNotLocateDefinition('No route path string literal at the given offset');
        }

        $found = ProjectLocator::locate($uri->path());
        if ($found === null) {
            throw new CouldNotLocateDefinition(
                sprintf('Could not find composer.json with autoload.psr-4 above "%s"', $uri->path())
            );
        }

        // toResourceFileName() は先頭に '/' を含む ('/index' -> '/Index.php') ため、区切りは足さない
        $resourceRelative = self::RESOURCE_DIR . RouterUtil::toResourceFileName($path);
        $locations = [];
        // psr-4 は 1プレフィックスに複数ディレクトリを許す (autoload と autoload-dev の
        // 両方に同じ名前空間が現れる場合など)。値は常に配列で扱う。
        foreach ($found['psr4'] as $prefix => $dirs) {
            foreach ($dirs as $dir) {
                // ルートパスに '..' が含まれるなど、解決先がプロジェクトの外へ出る場合は対象外
                $file = PathGuard::resolveInside($found['root'], $dir . '/' . $resourceRelative);
                if ($file === null || !is_file($file)) {
                    continue;
                }
                $classFqn = $prefix . str_replace('/', '\\', substr($resourceRelative, 0, -4));
                $locations[] = new TypeLocation(
                    TypeFactory::class($classFqn),
                    PhpClassDeclaration::location($file, $this->parser)
                );
            }
        }

        if ($locations === []) {
            throw new CouldNotLocateDefinition(sprintf('No Page resource class for route path "%s"', $path));
        }

        return new TypeLocations($locations);
    }

    /**
     * カーソル位置にある文字列リテラルがルートパス ('/' 始まり) ならその内容を返す。
     * 文字列リテラルの引き当ては共通部品 StringLiteralAtOffset に委ねる (文字列の
     * 内側にカーソルがある場合のみ発火する)。
     */
    private function routePathAtOffset(TextDocument $document, ByteOffset $byteOffset): ?string
    {
        $string = ($this->stringLiteralAtOffset)($document, $byteOffset->toInt());
        if ($string === null) {
            return null;
        }
        $contents = $string[1];

        return str_starts_with($contents, '/') ? $contents : null;
    }
}
