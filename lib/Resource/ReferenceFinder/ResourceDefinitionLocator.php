<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\ReferenceFinder;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Model\ResourceTargetResolver;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Util\PhpClassDeclaration;
use Phpactor\ReferenceFinder\DefinitionLocator;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\ReferenceFinder\TypeLocation;
use Phpactor\ReferenceFinder\TypeLocations;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\TypeFactory;

/**
 * 'app://self/user' などのリソースURI文字列から、対応するリソースクラス
 * (src/Resource/App/User.php) への定義ジャンプを提供する。
 *
 * URI → クラス の解決は ResourceTargetResolver に委譲する (参照検索と共有)。
 */
final class ResourceDefinitionLocator implements DefinitionLocator
{
    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset,
        private ResourceTargetResolver $resourceTargetResolver = new ResourceTargetResolver(),
    ) {
    }

    public function locateDefinition(TextDocument $document, ByteOffset $byteOffset): TypeLocations
    {
        // 入口の安価な事前判定: リソースURIは app:// か page:// で始まる。どちらも
        // ドキュメントに無ければ、どの文字列リテラルもリソースURIではない。
        // 構文解析より先に降りる。
        $text = $document->__toString();
        if (!str_contains($text, 'app://') && !str_contains($text, 'page://')) {
            throw new CouldNotLocateDefinition('Offset is not inside a resource URI string');
        }

        $uri = $this->resourceUriAtOffset($document, $byteOffset);
        if ($uri === null) {
            throw new CouldNotLocateDefinition('Offset is not inside a resource URI string');
        }

        $uriObject = $document->uri();
        if ($uriObject === null || $uriObject->scheme() !== 'file') {
            throw new CouldNotLocateDefinition('Document is not a file');
        }

        $project = Project::locate($uriObject->path());
        if ($project === null) {
            throw new CouldNotLocateDefinition(sprintf('Could not locate composer.json for "%s"', $uriObject->path()));
        }

        $target = $this->resourceTargetResolver->resolve($project, $uri);
        if ($target === null) {
            throw new CouldNotLocateDefinition(sprintf('Resource class for "%s" does not exist', $uri->uri()));
        }

        return new TypeLocations([
            new TypeLocation(
                TypeFactory::class($target['fqn']),
                PhpClassDeclaration::location($target['file'])
            ),
        ]);
    }

    private function resourceUriAtOffset(TextDocument $document, ByteOffset $byteOffset): ?ResourceUri
    {
        $string = ($this->stringLiteralAtOffset)($document, $byteOffset->toInt());

        return $string === null ? null : ResourceUri::fromString($string[1]);
    }
}
