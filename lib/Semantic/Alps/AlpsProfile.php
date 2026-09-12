<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

/**
 * Parsed ALPS profile independent of LSP positions and transport types.
 */
final readonly class AlpsProfile
{
    /** @param list<AlpsProfileDescriptor> $descriptors */
    public function __construct(
        public string $file,
        public array $descriptors,
    ) {
    }

    /** @return list<AlpsProfileDescriptor> */
    public function descriptorsById(string $descriptorId): array
    {
        $matches = [];
        $this->collectById($this->descriptors, $descriptorId, $matches);

        return $matches;
    }

    /**
     * @param list<AlpsProfileDescriptor> $descriptors
     * @param list<AlpsProfileDescriptor> $matches
     */
    private function collectById(array $descriptors, string $descriptorId, array &$matches): void
    {
        foreach ($descriptors as $descriptor) {
            if ($descriptor->id === $descriptorId) {
                $matches[] = $descriptor;
            }
            $this->collectById($descriptor->children, $descriptorId, $matches);
        }
    }
}
