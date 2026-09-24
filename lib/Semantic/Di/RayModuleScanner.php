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
 * Finds direct AbstractModule subclasses without loading application classes.
 */
final readonly class RayModuleScanner
{
    private const ABSTRACT_MODULE = 'Ray\\Di\\AbstractModule';

    public function __construct(
        private Psr4PhpSourceScanner $sourceScanner = new Psr4PhpSourceScanner(),
        private Parser $parser = new Parser(),
    ) {
    }

    /** @return iterable<RayModuleSource> */
    public function scan(WorkspaceContext $workspace, Project $project): iterable
    {
        foreach ($this->sourceScanner->scan($workspace, $project) as $source) {
            $path = $workspace->accessPolicy()->inspectExisting($source->file);
            if ($path->value === null) {
                continue;
            }
            try {
                $root = $this->parser->parseSourceFile($source->contents, $source->file);
                foreach ($root->getDescendantNodes() as $node) {
                    if (!$node instanceof ClassDeclaration || !$this->isRayModule($node)) {
                        continue;
                    }
                    $name = $node->getNamespacedName();
                    yield new RayModuleSource(
                        ltrim((string) $name, '\\'),
                        $path->value->relative,
                        $source->contents,
                        $node,
                    );
                }
            } catch (Throwable) {
                continue;
            }
        }
    }

    private function isRayModule(ClassDeclaration $class): bool
    {
        $baseClause = $class->getFirstChildNode(ClassBaseClause::class);
        if (!$baseClause instanceof ClassBaseClause) {
            return false;
        }
        $base = $baseClause->baseClass->getResolvedName();

        return ltrim((string) $base, '\\') === self::ABSTRACT_MODULE;
    }
}
