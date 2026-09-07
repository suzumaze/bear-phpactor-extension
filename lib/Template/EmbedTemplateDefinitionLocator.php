<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Template;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Util\PathGuard;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\Token;
use Phpactor\ReferenceFinder\DefinitionLocator;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\ReferenceFinder\TypeLocation;
use Phpactor\ReferenceFinder\TypeLocations;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\Location;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\TypeFactory;

/**
 * BEAR.Sunday の #[Embed] 関係から、Twig/Qiq の埋め込み先テンプレートへ飛ぶ。
 *
 * 対象は BEAR の標準テンプレート配置にある、次の変数参照だけ:
 *
 * - var/templates/{App,Page}/.../*.html.twig の {{ rel }} / {{ rel|raw }}
 * - var/qiq/template/{App,Page}/.../*.php の {{= $rel }} / {{h $rel }}
 *   (Qiq 1.x の $this->rel も互換性のため対象)
 *
 * 親 Resource の #[Embed] に対応する実在の Resource と同種テンプレートが
 * ある場合だけ、埋め込み先テンプレートを返す。相対 src は親の scheme を使う。
 * Twig 一般の include / extends や任意の Qiq/PHP 式は解釈しない。
 */
final class EmbedTemplateDefinitionLocator implements DefinitionLocator
{
    private const TWIG_TEMPLATE_DIR = 'var/templates';

    private const QIQ_TEMPLATE_DIR = 'var/qiq/template';

    private const TWIG_EXTENSION = '.html.twig';

    private const QIQ_EXTENSION = '.php';

    private const EMBED_SHORT_NAME = 'Embed';

    private const EMBED_FQN = 'BEAR\\Resource\\Annotation\\Embed';

    public function __construct(
        private Parser $parser = new Parser(),
    ) {
    }

    public function locateDefinition(TextDocument $document, ByteOffset $byteOffset): TypeLocations
    {
        $documentUri = $document->uri();
        if ($documentUri === null || $documentUri->scheme() !== 'file') {
            throw new CouldNotLocateDefinition('Template document is not a file');
        }

        $project = Project::locate($documentUri->path());
        if ($project === null) {
            throw new CouldNotLocateDefinition('No project found for template document');
        }

        $template = $this->templateAtPath($project, $documentUri->path());
        if ($template === null) {
            throw new CouldNotLocateDefinition('Document is not a BEAR Twig or Qiq template');
        }

        $relation = $this->relationAtOffset($document->__toString(), $byteOffset->toInt(), $template['kind']);
        if ($relation === null) {
            throw new CouldNotLocateDefinition('Offset is not on a BEAR embedded template variable');
        }

        $parentUri = $this->resourceUriForTemplate($template['relative']);
        if ($parentUri === null) {
            throw new CouldNotLocateDefinition('Template path does not map to a Resource');
        }

        $parentFile = $project->classFile($parentUri);
        if (
            $parentFile === null
            || !is_file($parentFile)
            || !$this->isRealPathInsideProject($project->root(), $parentFile)
        ) {
            throw new CouldNotLocateDefinition('Parent Resource does not exist inside the project');
        }

        $embeddedUri = $this->embeddedUri($parentFile, $parentUri, $relation);
        if ($embeddedUri === null || $embeddedUri->host() !== 'self') {
            throw new CouldNotLocateDefinition('No supported self-hosted Embed relation found');
        }

        $embeddedResource = $project->classFile($embeddedUri);
        if (
            $embeddedResource === null
            || !is_file($embeddedResource)
            || !$this->isRealPathInsideProject($project->root(), $embeddedResource)
        ) {
            throw new CouldNotLocateDefinition('Embedded Resource does not exist inside the project');
        }

        $target = PathGuard::resolveInside(
            $project->root(),
            $template['directory'] . '/'
                . str_replace('\\', '/', $embeddedUri->classPath())
                . $template['extension'],
        );
        if (
            $target === null
            || !is_file($target)
            || !$this->isRealPathInsideProject($project->root(), $target)
        ) {
            throw new CouldNotLocateDefinition('Embedded template does not exist inside the project');
        }

        return new TypeLocations([
            new TypeLocation(
                TypeFactory::stringLiteral($relation),
                Location::fromPathAndOffsets($target, 0, 0),
            ),
        ]);
    }

    /**
     * @return array{kind: 'twig'|'qiq', directory: string, extension: string, relative: string}|null
     */
    private function templateAtPath(Project $project, string $path): ?array
    {
        $templates = [
            ['kind' => 'twig', 'directory' => self::TWIG_TEMPLATE_DIR, 'extension' => self::TWIG_EXTENSION],
            ['kind' => 'qiq', 'directory' => self::QIQ_TEMPLATE_DIR, 'extension' => self::QIQ_EXTENSION],
        ];
        foreach ($templates as $template) {
            $base = PathGuard::resolveInside($project->root(), $template['directory']);
            if ($base === null || !$this->isRealPathInsideProject($project->root(), $base)) {
                continue;
            }

            $realBase = realpath($base);
            $realPath = realpath($path);
            if ($realBase === false || $realPath === false || !str_starts_with($realPath, $realBase . '/')) {
                continue;
            }

            $relative = substr($realPath, strlen($realBase) + 1);
            if (!str_ends_with($relative, $template['extension'])) {
                continue;
            }

            $classPath = substr($relative, 0, -strlen($template['extension']));
            if (!$this->isResourceClassPath($classPath)) {
                continue;
            }

            return [...$template, 'relative' => $relative];
        }

        return null;
    }

    private function isResourceClassPath(string $path): bool
    {
        return preg_match('#^(?:App|Page)(?:/[A-Za-z_][A-Za-z0-9_]*)+$#', $path) === 1;
    }

    private function isRealPathInsideProject(string $projectRoot, string $path): bool
    {
        $realRoot = realpath($projectRoot);
        $realPath = realpath($path);

        return $realRoot !== false
            && $realPath !== false
            && ($realPath === $realRoot || str_starts_with($realPath, $realRoot . '/'));
    }

    private function resourceUriForTemplate(string $relative): ?ResourceUri
    {
        $withoutExtension = preg_replace('/(?:\.html\.twig|\.php)$/', '', $relative);
        if (!is_string($withoutExtension)) {
            return null;
        }

        $segments = explode('/', $withoutExtension);
        $scheme = strtolower(array_shift($segments));
        if ($segments === []) {
            return null;
        }

        return ResourceUri::fromString(sprintf(
            '%s://self/%s',
            $scheme,
            implode('/', array_map(static fn (string $segment): string => lcfirst($segment), $segments)),
        ));
    }

    private function relationAtOffset(string $source, int $offset, string $kind): ?string
    {
        $pattern = $kind === 'twig'
            ? '/\{\{\s*(?<relation>[A-Za-z_][A-Za-z0-9_]*)\s*(?:\|[^{}]+)?\}\}/'
            : '/\{\{(?:=|h)\s+\$(?:this->)?(?<relation>[A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        foreach ($matches['relation'] as [$relation, $start]) {
            if ($offset >= $start && $offset < $start + strlen($relation)) {
                return $relation;
            }
        }

        return null;
    }

    private function embeddedUri(string $resourceFile, ResourceUri $parentUri, string $relation): ?ResourceUri
    {
        $source = @file_get_contents($resourceFile);
        if ($source === false) {
            return null;
        }

        $uris = [];
        $root = $this->parser->parseSourceFile($source, $resourceFile);
        foreach ($root->getDescendantNodes() as $node) {
            if (!$node instanceof Attribute || !$this->isEmbedAttribute($node)) {
                continue;
            }

            $arguments = $this->namedStringArguments($node, $source);
            if (($arguments['rel'] ?? null) !== $relation || !isset($arguments['src'])) {
                continue;
            }

            $uri = $this->embedSourceUri($arguments['src'], $parentUri);
            if ($uri === null) {
                return null;
            }

            $uris[$uri->uri()] = $uri;
        }

        return count($uris) === 1 ? reset($uris) : null;
    }

    private function embedSourceUri(string $source, ResourceUri $parentUri): ?ResourceUri
    {
        if (preg_match('#^/(?!/)#', $source) === 1) {
            $source = sprintf('%s://self%s', $parentUri->scheme(), $source);
        }

        return ResourceUri::fromString($source);
    }

    private function isEmbedAttribute(Attribute $attribute): bool
    {
        if (!$attribute->name instanceof QualifiedName) {
            return false;
        }

        $name = ltrim($attribute->name->getText(), '\\');

        return $name === self::EMBED_SHORT_NAME || $name === self::EMBED_FQN;
    }

    /** @return array<string, string> */
    private function namedStringArguments(Attribute $attribute, string $source): array
    {
        foreach ($attribute->getChildNodes() as $child) {
            if (!$child instanceof ArgumentExpressionList) {
                continue;
            }

            $arguments = [];
            foreach ($child->getChildNodes() as $argument) {
                if (!$argument instanceof ArgumentExpression || !$argument->name instanceof Token) {
                    continue;
                }
                if (!$argument->expression instanceof StringLiteral) {
                    continue;
                }

                $arguments[$argument->name->getText($source)] = $argument->expression->getStringContentsText();
            }

            return $arguments;
        }

        return [];
    }
}
