<?php

declare(strict_types=1);

namespace Acme\Blog\Module\App;

use BEAR\Package\Module\Import\ImportApp;
use BEAR\Package\Module\ImportAppModule;
use Ray\Di\AbstractModule;

/**
 * ImportApp のフィクスチャ。構文解析だけで使う (実行はしない)。
 *
 * - 'tags' → 'Acme\Tags' は第1・第2引数が文字列リテラルなので対応表に載る
 * - $dynamicHost は文字列リテラルでないため、この呼び出しは対応表に載らない
 */
final class CustomResourceUriModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->install(new ImportAppModule([
            new ImportApp('tags', 'Acme\Tags', $this->context),
            new ImportApp($dynamicHost, 'Acme\Ignored', $this->context),
        ]));
    }
}
