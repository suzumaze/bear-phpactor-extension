<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Result;

use LogicException;

/**
 * Stable machine code and safe human message for a failed semantic query.
 */
final readonly class SemanticError
{
    public function __construct(
        public string $code,
        public string $message,
    ) {
        if ($code === '' || preg_match('/^[a-z][a-z0-9_]*$/', $code) !== 1) {
            throw new LogicException('A semantic error code must be stable snake_case.');
        }
        if ($message === '') {
            throw new LogicException('A semantic error message must not be empty.');
        }
    }

    public static function fromStatus(SemanticStatus $status): self
    {
        return match ($status) {
            SemanticStatus::NotFound => new self('semantic_not_found', 'No semantic target was found.'),
            SemanticStatus::Ambiguous => new self('semantic_ambiguous', 'More than one semantic target matched.'),
            SemanticStatus::InvalidInput => new self('semantic_invalid_input', 'The semantic query input is invalid.'),
            SemanticStatus::Unsupported => new self('semantic_unsupported', 'The semantic query is not supported.'),
            SemanticStatus::ParseError => new self('semantic_parse_error', 'A semantic source could not be parsed.'),
            SemanticStatus::EngineUnavailable => new self(
                'semantic_engine_unavailable',
                'The required semantic engine is unavailable.',
            ),
            SemanticStatus::OutsideWorkspace => new self(
                'semantic_outside_workspace',
                'The semantic target is outside the workspace.',
            ),
            SemanticStatus::Timeout => new self('semantic_timeout', 'The semantic query timed out.'),
            SemanticStatus::Ok => throw new LogicException('A successful semantic result has no error.'),
        };
    }
}
