<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

use JsonException;
use SimpleXMLElement;
use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Util\PathGuard;

/**
 * Loads one bounded, workspace-local ALPS JSON profile without executing PHP.
 */
final class AlpsProfileQuery
{
    private const MAX_INPUT_BYTES = 1048576;
    private const MAX_STRUCTURE_DEPTH = 64;
    private const MAX_CACHE_ENTRIES = 8;

    /**
     * @var array<string,array{fingerprint:string,result:SemanticResult<AlpsProfile|null>}>
     */
    private array $cache = [];

    /** @return SemanticResult<AlpsProfile|null> */
    public function load(Project $project): SemanticResult
    {
        $profilePath = $this->profilePath($project);
        if ($profilePath->value === null) {
            return SemanticResult::failure($profilePath->status);
        }

        $profileContents = $this->readInput($profilePath->value);
        if ($profileContents->value === null) {
            return SemanticResult::failure($profileContents->status);
        }
        $contents = $profileContents->value;

        $fingerprint = hash('sha256', $contents) . ':' . strlen($contents);
        $cached = $this->cache[$profilePath->value] ?? null;
        if ($cached !== null && $cached['fingerprint'] === $fingerprint) {
            return $cached['result'];
        }

        $result = $this->parseProfile($profilePath->value, $contents);
        $this->cacheResult($profilePath->value, $fingerprint, $result);

        return $result;
    }

    /** @param SemanticResult<AlpsProfile|null> $result */
    private function cacheResult(string $path, string $fingerprint, SemanticResult $result): void
    {
        if (!isset($this->cache[$path]) && count($this->cache) >= self::MAX_CACHE_ENTRIES) {
            array_shift($this->cache);
        }
        $this->cache[$path] = [
            'fingerprint' => $fingerprint,
            'result' => $result,
        ];
    }

    /** @return SemanticResult<AlpsProfile|null> */
    private function parseProfile(string $profilePath, string $contents): SemanticResult
    {
        try {
            $data = json_decode($contents, true, self::MAX_STRUCTURE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return SemanticResult::parseError();
        }
        if (!is_array($data)) {
            return SemanticResult::parseError();
        }

        $alps = $data['alps'] ?? null;
        $offsets = $this->idValueOffsets($contents);
        $missingOffset = false;
        $descriptors = is_array($alps)
            ? $this->descriptors($alps['descriptor'] ?? null, $offsets, $missingOffset)
            : [];
        if ($missingOffset) {
            return SemanticResult::parseError();
        }

        return SemanticResult::ok(new AlpsProfile($profilePath, $descriptors));
    }

    /** @return SemanticResult<string|null> */
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

        $apidocContents = $this->readInput($apidocPath);
        if ($apidocContents->value === null) {
            return SemanticResult::failure($apidocContents->status);
        }
        $contents = $apidocContents->value;
        if ($this->containsForbiddenXmlDeclaration($contents)) {
            return SemanticResult::parseError();
        }
        $xml = @simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false || $this->exceedsXmlDepth($xml)) {
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

    /** @return SemanticResult<string|null> */
    private function readInput(string $path): SemanticResult
    {
        $contents = @file_get_contents($path, false, null, 0, self::MAX_INPUT_BYTES + 1);
        if ($contents === false) {
            return SemanticResult::notFound();
        }
        if (strlen($contents) > self::MAX_INPUT_BYTES) {
            return SemanticResult::parseError();
        }

        return SemanticResult::ok($contents);
    }

    private function containsForbiddenXmlDeclaration(string $contents): bool
    {
        return stripos($contents, '<!DOCTYPE') !== false
            || stripos($contents, '<!ENTITY') !== false;
    }

    private function exceedsXmlDepth(SimpleXMLElement $element, int $depth = 1): bool
    {
        if ($depth > self::MAX_STRUCTURE_DEPTH) {
            return true;
        }

        foreach ($element->children() as $child) {
            if ($this->exceedsXmlDepth($child, $depth + 1)) {
                return true;
            }
        }

        return false;
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

    /**
     * @param array<string,list<int>> $offsets
     * @return list<AlpsProfileDescriptor>
     */
    private function descriptors(mixed $value, array &$offsets, bool &$missingOffset): array
    {
        if (!is_array($value)) {
            return [];
        }

        $values = array_is_list($value) ? $value : [$value];
        $descriptors = [];
        foreach ($values as $descriptor) {
            if (!is_array($descriptor)) {
                continue;
            }
            $id = $this->string($descriptor['id'] ?? null);
            $offset = null;
            if ($id !== null) {
                $availableOffsets = $offsets[$id] ?? [];
                $offset = array_shift($availableOffsets);
                $offsets[$id] = $availableOffsets;
                if (!is_int($offset)) {
                    $missingOffset = true;
                }
            }

            $descriptors[] = new AlpsProfileDescriptor(
                $id,
                $this->descriptorType($descriptor, $id),
                $this->string($descriptor['name'] ?? null),
                $this->string($descriptor['rt'] ?? null),
                $this->string($descriptor['href'] ?? null),
                $this->string($descriptor['rel'] ?? null),
                $this->doc($descriptor['doc'] ?? null),
                $this->string($descriptor['def'] ?? null),
                $this->string($descriptor['tag'] ?? null),
                $this->string($descriptor['title'] ?? null),
                $offset,
                $this->descriptors($descriptor['descriptor'] ?? null, $offsets, $missingOffset),
            );
        }

        return $descriptors;
    }

    /** @param array<mixed> $descriptor */
    private function descriptorType(array $descriptor, ?string $id): ?string
    {
        $type = $this->string($descriptor['type'] ?? null);

        return $type ?? ($id === null ? null : 'semantic');
    }

    private function doc(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }
        if (!is_array($value)) {
            return null;
        }

        $doc = $this->string($value['value'] ?? null);
        if ($doc === null) {
            return null;
        }
        $doc = trim($doc);

        return $doc === '' ? null : $doc;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @return array<string,list<int>> */
    private function idValueOffsets(string $contents): array
    {
        $offsets = [];
        $length = strlen($contents);
        for ($i = 0; $i < $length; ++$i) {
            if ($contents[$i] !== '"') {
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
            $rawValue = $this->rawJsonString($contents, $valueStart);
            if ($rawValue === null) {
                return [];
            }
            try {
                $value = json_decode($rawValue, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
            if (is_string($value)) {
                $offsets[$value][] = $valueStart;
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
