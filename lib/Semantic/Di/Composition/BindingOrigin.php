<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

/** Source evidence only: never stores an expression, argument value, or environment profile. */
final readonly class BindingOrigin
{
    /** @param list<ModuleEdge> $via */
    public function __construct(
        public string $module,
        public ?string $path = null,
        public ?int $line = null,
        public array $via = [],
    ) {
    }

    public function through(ModuleEdge $edge): self
    {
        return new self($this->module, $this->path, $this->line, [$edge, ...$this->via]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'path' => $this->path,
            'line' => $this->line,
            'basis' => $this->path === null ? 'composition_recipe' : 'source_declaration',
            'via' => array_map(static fn (ModuleEdge $edge): array => get_object_vars($edge), $this->via),
        ];
    }
}
