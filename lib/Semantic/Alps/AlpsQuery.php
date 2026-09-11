<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

use SimpleXMLElement;
use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\PathGuard;

/**
 * Resolves an ALPS semantic descriptor through apidoc.xml without booting the app.
 */
final class AlpsQuery
{
    /**
     * @return SemanticResult<AlpsDescriptorResolution|null>
     */
    public function resolve(Project $project, string $descriptorId): SemanticResult
    {
        if ($descriptorId === '' || str_contains($descriptorId, "\0")) {
            return SemanticResult::invalidInput();
        }

        $profile = $this->profilePath($project);
        if ($profile->value === null) {
            return SemanticResult::failure($profile->status);
        }

        $contents = @file_get_contents($profile->value);
        if ($contents === false) {
            return SemanticResult::notFound();
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return SemanticResult::parseError();
        }
        $descriptors = $data['alps']['descriptor'] ?? null;
        if (!is_array($descriptors)) {
            return SemanticResult::notFound();
        }

        $count = 0;
        foreach ($descriptors as $descriptor) {
            if (is_array($descriptor) && ($descriptor['id'] ?? null) === $descriptorId) {
                ++$count;
            }
        }
        if ($count === 0) {
            return SemanticResult::notFound();
        }

        $offsets = $this->idValueOffsets($contents, $descriptorId);
        if (count($offsets) < $count) {
            return SemanticResult::parseError();
        }
        $resolutions = array_map(
            static fn (int $offset): AlpsDescriptorResolution => new AlpsDescriptorResolution(
                $descriptorId,
                $profile->value,
                $offset,
            ),
            array_slice($offsets, 0, $count),
        );

        return $count === 1
            ? SemanticResult::ok($resolutions[0])
            : SemanticResult::ambiguous($resolutions);
    }

    /**
     * @return SemanticResult<AlpsDescriptorResolution|null>
     */
    public function resolveInWorkspace(
        WorkspaceContext $workspace,
        string $descriptorId,
        ?string $contextPath = null,
    ): SemanticResult {
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $result = $this->resolve($project->value, $descriptorId);
        if ($result->status === SemanticStatus::Ok && $result->value !== null) {
            return $this->enforceWorkspace($workspace, $result->value);
        }
        if ($result->status !== SemanticStatus::Ambiguous) {
            return $result;
        }

        $candidates = [];
        foreach ($result->candidates as $candidate) {
            $checked = $this->workspaceResolution($workspace, $candidate);
            if ($checked->value === null) {
                return SemanticResult::failure($checked->status);
            }
            $candidates[] = $checked->value;
        }

        return SemanticResult::ambiguous($candidates);
    }

    /**
     * @return SemanticResult<string|null>
     */
    private function profilePath(Project $project): SemanticResult
    {
        $root = realpath($project->root());
        if ($root === false) {
            return SemanticResult::notFound();
        }
        $root = $this->normalize($root);

        $apidocPath = realpath($root . '/apidoc.xml');
        if ($apidocPath === false || !is_file($apidocPath)) {
            return SemanticResult::notFound();
        }
        $apidocPath = $this->normalize($apidocPath);
        if (!$this->contains($root, $apidocPath)) {
            return SemanticResult::outsideWorkspace();
        }

        $contents = @file_get_contents($apidocPath);
        if ($contents === false) {
            return SemanticResult::notFound();
        }
        $xml = @simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            return SemanticResult::parseError();
        }

        $relativePath = trim((string) $xml->alps);
        if ($relativePath === '') {
            return SemanticResult::notFound();
        }
        if (!$this->isSafeRelativePath($relativePath)) {
            return SemanticResult::outsideWorkspace();
        }

        $profilePath = realpath($root . '/' . $relativePath);
        if ($profilePath === false || !is_file($profilePath)) {
            return SemanticResult::notFound();
        }
        $profilePath = $this->normalize($profilePath);
        if (!$this->contains($root, $profilePath)) {
            return SemanticResult::outsideWorkspace();
        }

        return SemanticResult::ok($profilePath);
    }

    private function isSafeRelativePath(string $path): bool
    {
        if (
            str_contains($path, "\0")
            || str_contains($path, '\\')
            || PathGuard::isAbsolutePath($path)
        ) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /** @return list<int> */
    private function idValueOffsets(string $contents, string $descriptorId): array
    {
        $expected = json_encode($descriptorId);
        if ($expected === false) {
            return [];
        }

        $offsets = [];
        $length = strlen($contents);
        for ($i = 0; $i < $length; ++$i) {
            $char = $contents[$i];
            if ($char === '/' && ($contents[$i + 1] ?? '') === '/') {
                $newline = strpos($contents, "\n", $i);
                $i = $newline === false ? $length : $newline;

                continue;
            }
            if ($char === '/' && ($contents[$i + 1] ?? '') === '*') {
                $end = strpos($contents, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;

                continue;
            }
            if ($char !== '"') {
                continue;
            }

            $key = $this->rawJsonString($contents, $i);
            if ($key === null) {
                return [];
            }
            if ($key !== '"id"') {
                $i += strlen($key) - 1;

                continue;
            }

            $valueStart = $this->valueStart($contents, $i + strlen($key));
            if ($valueStart === null) {
                $i += strlen($key) - 1;

                continue;
            }
            $value = $this->rawJsonString($contents, $valueStart);
            if ($value === $expected) {
                $offsets[] = $valueStart;
            }
            $i += strlen($key) - 1;
        }

        return $offsets;
    }

    private function valueStart(string $contents, int $offset): ?int
    {
        $length = strlen($contents);
        while ($offset < $length && $this->isWhitespace($contents[$offset])) {
            ++$offset;
        }
        if (($contents[$offset] ?? '') !== ':') {
            return null;
        }
        ++$offset;
        while ($offset < $length && $this->isWhitespace($contents[$offset])) {
            ++$offset;
        }

        return ($contents[$offset] ?? '') === '"' ? $offset : null;
    }

    private function isWhitespace(string $character): bool
    {
        return $character === ' ' || $character === "\t" || $character === "\n" || $character === "\r";
    }

    private function rawJsonString(string $contents, int $start): ?string
    {
        $length = strlen($contents);
        if (($contents[$start] ?? '') !== '"') {
            return null;
        }

        $end = $start + 1;
        while ($end < $length) {
            if ($contents[$end] === '\\') {
                $end += 2;

                continue;
            }
            if ($contents[$end] === '"') {
                return substr($contents, $start, $end - $start + 1);
            }
            ++$end;
        }

        return null;
    }

    /** @return SemanticResult<AlpsDescriptorResolution|null> */
    private function enforceWorkspace(
        WorkspaceContext $workspace,
        AlpsDescriptorResolution $resolution,
    ): SemanticResult {
        return $this->workspaceResolution($workspace, $resolution);
    }

    /** @return SemanticResult<AlpsDescriptorResolution|null> */
    private function workspaceResolution(
        WorkspaceContext $workspace,
        AlpsDescriptorResolution $resolution,
    ): SemanticResult {
        $path = $workspace->accessPolicy()->inspectExisting($resolution->profileFile);
        if ($path->value === null) {
            return SemanticResult::failure($path->status);
        }

        return SemanticResult::ok(new AlpsDescriptorResolution(
            $resolution->descriptorId,
            $path->value->absolute,
            $resolution->offset,
        ));
    }

    private function contains(string $root, string $path): bool
    {
        return $root === '/'
            ? str_starts_with($path, '/')
            : $path === $root || str_starts_with($path, $root . '/');
    }

    private function normalize(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return $normalized === '' ? '/' : $normalized;
    }
}
