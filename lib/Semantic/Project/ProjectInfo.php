<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Project;

final readonly class ProjectInfo
{
    /**
     * @param list<ProjectPsr4Root>           $psr4Roots
     * @param list<string>                    $capabilities
     * @param array<string,string>            $versions
     * @param list<ProjectCompatibilityIssue> $compatibilityIssues
     */
    public function __construct(
        public string $workspaceName,
        public string $projectPath,
        public string $composerPath,
        public array $psr4Roots,
        public int $excludedPsr4Roots,
        public int $resourceCount,
        public array $capabilities,
        public array $versions,
        public array $compatibilityIssues,
    ) {
    }
}
