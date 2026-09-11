<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Result;

/**
 * A transport-independent outcome of a BEAR semantic query.
 *
 * LSP adapters intentionally collapse non-successful statuses to an empty
 * result. Headless and future structured adapters can retain the distinction.
 */
enum SemanticStatus: string
{
    case Ok = 'ok';
    case NotFound = 'not_found';
    case Ambiguous = 'ambiguous';
    case InvalidInput = 'invalid_input';
    case Unsupported = 'unsupported';
    case ParseError = 'parse_error';
    case OutsideWorkspace = 'outside_workspace';
}
