<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\LanguageServer;

use Phpactor\LanguageServer\Event\FilesChanged;
use Phpactor\LanguageServer\Event\TextDocumentSaved;
use Psr\EventDispatcher\ListenerProviderInterface;
use Suzumaze\BearPhpactor\Resource\Model\ImportAppRegistry;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryIndex;

/**
 * Invalidates the optional Resource inventory index from Phpactor LSP events.
 */
final class ResourceInventoryIndexListener implements ListenerProviderInterface
{
    public function __construct(private ResourceInventoryIndex $index)
    {
    }

    /** @return iterable<callable(object):void> */
    public function getListenersForEvent(object $event): iterable
    {
        if (!$event instanceof FilesChanged && !$event instanceof TextDocumentSaved) {
            return [];
        }

        return [function (): void {
            $this->index->invalidate();
            ImportAppRegistry::invalidate();
        }];
    }
}
