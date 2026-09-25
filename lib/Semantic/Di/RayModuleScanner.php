<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

use Microsoft\PhpParser\Node\ClassBaseClause;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Parser;
use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Workspace\Psr4PhpSourceScanner;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Throwable;

/**
 * Finds AbstractModule subclasses without loading application classes.
 *
 * BEAR application modules normally extend AbstractAppModule, and context
 * modules may extend another application module. Resolve that saved-source
 * inheritance chain before deciding whether a declaration is a Ray.Di module.
 */
final readonly class RayModuleScanner
{
    private const ABSTRACT_MODULE = 'Ray\\Di\\AbstractModule';
    private const ABSTRACT_APP_MODULE = 'BEAR\\Package\\AbstractAppModule';

    public function __construct(
        private Psr4PhpSourceScanner $sourceScanner = new Psr4PhpSourceScanner(),
        private Parser $parser = new Parser(),
    ) {
    }

    /** @return iterable<RayModuleSource> */
    public function scan(WorkspaceContext $workspace, Project $project): iterable
    {
        /** @var array<string, RayModuleSource> $classes */
        $classes = [];
        /** @var array<string, string> $parents */
        $parents = [];
        foreach ($this->sourceScanner->scan($workspace, $project) as $source) {
            $path = $workspace->accessPolicy()->inspectExisting($source->file);
            if ($path->value === null) {
                continue;
            }
            try {
                $root = $this->parser->parseSourceFile($source->contents, $source->file);
                foreach ($root->getDescendantNodes() as $node) {
                    if (!$node instanceof ClassDeclaration) {
                        continue;
                    }
                    $name = $node->getNamespacedName();
                    $module = ltrim((string) $name, '\\');
                    $parent = $this->parent($node);
                    if ($module === '' || $parent === null) {
                        continue;
                    }
                    $classes[$module] = new RayModuleSource(
                        $module,
                        $parent,
                        $path->value->relative,
                        $source->contents,
                        $node,
                    );
                    $parents[$module] = $parent;
                }
            } catch (Throwable) {
                continue;
            }
        }

        foreach ($classes as $module => $source) {
            if ($this->isRayModule($module, $parents)) {
                yield $source;
            }
        }
    }

    private function parent(ClassDeclaration $class): ?string
    {
        $baseClause = $class->getFirstChildNode(ClassBaseClause::class);
        if (!$baseClause instanceof ClassBaseClause) {
            return null;
        }
        $base = $baseClause->baseClass->getResolvedName();

        return $base === null ? null : ltrim((string) $base, '\\');
    }

    /** @param array<string, string> $parents */
    private function isRayModule(string $class, array $parents): bool
    {
        $visited = [];
        while (isset($parents[$class]) && !isset($visited[$class])) {
            $visited[$class] = true;
            $class = $parents[$class];
            if ($class === self::ABSTRACT_MODULE || $class === self::ABSTRACT_APP_MODULE) {
                return true;
            }
        }

        return false;
    }
}
