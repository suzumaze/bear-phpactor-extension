<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaDirectoryQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Template\TemplatePathResolver;

use function array_column;
use function array_unique;
use function count;
use function rtrim;

/**
 * The directory Qiq finds templates in.
 *
 * BEAR.QiqModule takes the directory as its first constructor argument and binds it as
 * `qiq_template_dir` (`new QiqModule(dirname(__DIR__, 2) . '/templates')`). The directory is read from
 * the DI modules of every application context that installs Qiq, without running them:
 *
 * - every such context binds the same directory inside the workspace: that directory
 * - no context binds it, or BEAR.QiqModule is not installed: BEAR's convention `var/qiq/template`
 * - the directory is computed at runtime, the contexts disagree, or a context cannot be composed:
 *   unsupported, never a guess
 */
final class QiqTemplateRoot
{
    /**
     * Composing the contexts takes a while, so a caller that looks up many templates in one request
     * passes one SchemaDirectoryQuery to all of them; it keeps the compositions for that request only.
     *
     * @param string|null $file an absolute file of the project, used to pick the project and its contexts
     * @return SemanticResult<string|null> the absolute directory
     */
    public static function inWorkspace(
        WorkspaceContext $workspace,
        Project $project,
        ?string $file,
        ?SchemaDirectoryQuery $directories = null,
    ): SemanticResult {
        $convention = SemanticResult::ok($project->root() . '/' . TemplatePathResolver::QIQ_ROOT);
        if ((new ClassSourceIndex($project->root(), $workspace->root()))->find('BEAR\\QiqModule\\QiqModule') === null) {
            return $convention;
        }

        $relative = $file === null ? null : $workspace->accessPolicy()->inspectExisting($file)->value?->relative;
        $configuration = ($directories ?? new SchemaDirectoryQuery())->inspect($workspace, $relative, 'qiq', null);
        $bound = array_unique(array_column($configuration['candidates'], 'directory'));

        return match (true) {
            $configuration['status'] === 'convention' => $convention,
            $configuration['status'] === 'configured' && count($bound) === 1
                => SemanticResult::ok(rtrim($workspace->root() . '/' . $bound[0], '/')),
            $configuration['status'] === 'outside_workspace' => SemanticResult::outsideWorkspace(),
            default => SemanticResult::unsupported(),
        };
    }
}
