<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

/** Response-local definitions: keep context membership and provenance without repeating candidates. */
final class SchemaSelectionTable
{
    /** @var array<string, array<string, mixed>> */
    private array $definitions = [];

    /**
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    public function reference(array $selection): array
    {
        if ($selection === []) {
            return [];
        }
        $id = hash('sha256', json_encode($selection, JSON_THROW_ON_ERROR));
        if (!isset($this->definitions[$id])) {
            $groups = [];
            foreach ($selection['candidates'] ?? [] as $candidate) {
                $binding = array_diff_key($candidate, ['applicationContext' => true]);
                $key = json_encode($binding, JSON_THROW_ON_ERROR);
                $groups[$key] ??= [...$binding, 'applicationContexts' => []];
                $groups[$key]['applicationContexts'][] = $candidate['applicationContext'];
            }
            $this->definitions[$id] = [
                ...SchemaDirectoryQuery::summary($selection),
                'candidateGroups' => array_values($groups),
            ];
        }

        return [...SchemaDirectoryQuery::summary($selection), 'ref' => $id];
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }
}
