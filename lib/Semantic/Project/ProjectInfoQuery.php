<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Project;

use Composer\InstalledVersions;
use Suzumaze\BearPhpactor\Config\PhpactorVersionConflictDetector;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryIndex;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\PathGuard;
use Throwable;

/**
 * Describes the active BEAR semantic workspace without exposing absolute paths.
 */
final class ProjectInfoQuery
{
    private const EXTENSION = 'suzumaze/bear-phpactor-extension';
    private const LANGUAGE_SERVER = 'phpactor/language-server';
    private const PHPACTOR = 'phpactor/phpactor';
    private const PROTOCOL = 'phpactor/language-server-protocol';

    /** @var array<string,string>|null */
    private ?array $runtimeVersions;

    /** @param array<string,string>|null $runtimeVersions */
    public function __construct(
        ?array $runtimeVersions = null,
        private ?ResourceInventoryIndex $inventoryIndex = null,
    ) {
        $this->runtimeVersions = $runtimeVersions;
    }

    /** @return SemanticResult<ProjectInfo|null> */
    public function describeInWorkspace(WorkspaceContext $workspace, ?string $contextPath = null): SemanticResult
    {
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $projectPath = $workspace->accessPolicy()->inspectExisting($project->value->root());
        $composerPath = $workspace->accessPolicy()->inspectExisting($project->value->root() . '/composer.json');
        if ($projectPath->value === null) {
            return SemanticResult::failure($projectPath->status);
        }
        if ($composerPath->value === null) {
            return SemanticResult::failure($composerPath->status);
        }

        $psr4Roots = [];
        $excluded = 0;
        foreach ($project->value->psr4() as $namespace => $directories) {
            foreach ($directories as $directory) {
                $absolute = PathGuard::isAbsolutePath($directory)
                    ? $directory
                    : $project->value->root() . '/' . $directory;
                $path = $workspace->accessPolicy()->inspectExisting($absolute);
                if ($path->value === null || !is_dir($path->value->absolute)) {
                    ++$excluded;
                    continue;
                }

                $key = $namespace . "\0" . $path->value->relative;
                $psr4Roots[$key] = new ProjectPsr4Root(
                    $namespace,
                    $path->value->relative === '' ? '.' : $path->value->relative,
                );
            }
        }
        ksort($psr4Roots, SORT_STRING);

        $versions = $this->versions();
        $issues = [];
        $languageServer = $versions[self::LANGUAGE_SERVER] ?? null;
        $protocol = $versions[self::PROTOCOL] ?? null;
        if (
            $languageServer !== null
            && $protocol !== null
            && PhpactorVersionConflictDetector::isBrokenCombination($languageServer, $protocol)
        ) {
            $issues[] = new ProjectCompatibilityIssue(
                'phpactor_did_change_protocol_conflict',
                [self::LANGUAGE_SERVER => $languageServer, self::PROTOCOL => $protocol],
            );
        }

        $workspaceName = basename($workspace->root());

        $resourceCandidates = $this->inventoryIndex === null
            ? $project->value->resourceClassCandidates()
            : $this->inventoryIndex->candidates($project->value, $contextPath);

        return SemanticResult::ok(
            new ProjectInfo(
                $workspaceName === '' ? '/' : $workspaceName,
                $projectPath->value->relative === '' ? '.' : $projectPath->value->relative,
                $composerPath->value->relative,
                array_values($psr4Roots),
                $excluded,
                count($resourceCandidates),
                self::capabilities(),
                $versions,
                $issues,
            ),
            [Provenance::savedFile($composerPath->value->relative), Provenance::derived()],
        );
    }

    /** @return list<string> */
    private static function capabilities(): array
    {
        return [
            'alpsDescriptorFacts',
            'alpsDescriptorResolution',
            'incomingResourceRelations',
            'projectInfo',
            'resourceDescription',
            'resourceInventory',
            'resourceReferences',
            'resourceResolution',
            'routeResolution',
            'schemaFacts',
            'schemaResolution',
            'sqlResolution',
            'templateResolution',
        ];
    }

    /** @return array<string,string> */
    private function versions(): array
    {
        if ($this->runtimeVersions !== null) {
            $versions = $this->runtimeVersions;
            ksort($versions, SORT_STRING);

            return $versions;
        }

        $versions = [];
        foreach ([self::EXTENSION, self::LANGUAGE_SERVER, self::PHPACTOR, self::PROTOCOL] as $package) {
            $version = $this->installedVersion($package);
            if ($version !== null) {
                $versions[$package] = $version;
            }
        }
        ksort($versions, SORT_STRING);

        return $versions;
    }

    private function installedVersion(string $package): ?string
    {
        try {
            $root = InstalledVersions::getRootPackage();
            if ($root['name'] === $package) {
                $version = $root['pretty_version'];

                return $version !== '' ? $version : null;
            }
            if (!InstalledVersions::isInstalled($package)) {
                return null;
            }

            $version = InstalledVersions::getPrettyVersion($package)
                ?? InstalledVersions::getVersion($package);

            return is_string($version) && $version !== '' ? $version : null;
        } catch (Throwable) {
            return null;
        }
    }
}
