<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

/**
 * One source-declared Ray.Di binding; no winner or runtime composition is implied.
 */
final readonly class DiBindingFact
{
    public const STATE_RESOLVED = 'resolved';
    public const STATE_UNRESOLVED = 'unresolved';

    public function __construct(
        public string $state,
        public string $module,
        public ?string $sourceType,
        public ?string $targetType,
        public ?string $reason,
        public string $path,
        public int $byteStart,
        public int $byteEnd,
    ) {
    }
}
