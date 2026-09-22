<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Project;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;

/**
 * One statically observable contract surface and its adoption state.
 */
final readonly class ContractCoverageSurface
{
    public const STATE_ABSENT = 'absent';
    public const STATE_AVAILABLE = 'available';
    public const STATE_DYNAMIC = 'dynamic';
    public const STATE_NOT_APPLICABLE = 'not_applicable';
    public const STATE_UNRESOLVED = 'unresolved';

    /**
     * @param self::STATE_* $state
     */
    public function __construct(
        public string $state,
        public SemanticStatus $status,
        public ?string $subject,
    ) {
    }
}
