<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

/**
 * Parsed ALPS profile independent of LSP positions and transport types.
 */
final readonly class AlpsProfile
{
    /** @var array<string,list<AlpsProfileDescriptor>> */
    private array $descriptorsById;

    /** @param list<AlpsProfileDescriptor> $descriptors */
    public function __construct(
        public string $file,
        public array $descriptors,
    ) {
        $descriptorsById = [];
        $this->indexDescriptors($descriptors, $descriptorsById);
        $this->descriptorsById = $descriptorsById;
    }

    /** @return list<AlpsProfileDescriptor> */
    public function descriptorsById(string $descriptorId): array
    {
        return $this->descriptorsById[$descriptorId] ?? [];
    }

    /**
     * @param list<AlpsProfileDescriptor> $descriptors
     * @param array<string,list<AlpsProfileDescriptor>> $descriptorsById
     */
    private function indexDescriptors(array $descriptors, array &$descriptorsById): void
    {
        foreach ($descriptors as $descriptor) {
            if ($descriptor->id !== null) {
                $descriptorsById[$descriptor->id][] = $descriptor;
            }
            $this->indexDescriptors($descriptor->children, $descriptorsById);
        }
    }
}
