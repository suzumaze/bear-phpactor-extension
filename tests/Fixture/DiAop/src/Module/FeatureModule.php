<?php

declare(strict_types=1);

namespace Acme\DiAop\Module;

use Acme\DiAop\Annotation\Primary;
use Acme\DiAop\Service\Clock;
use Acme\DiAop\Service\ConnectionInterface;
use Acme\DiAop\Service\ConnectionProvider;
use Acme\DiAop\Service\FileLogger;
use Acme\DiAop\Service\LoggerInterface;
use Acme\DiAop\Service\Mailer;
use Acme\DiAop\Service\NotifierInterface;
use Ray\Di\AbstractModule;
use Ray\Di\InjectionPoints;
use Ray\Di\Scope;

final class FeatureModule extends AbstractModule
{
    protected function configure(): void
    {
        // Statically readable declarations.
        $this->bind(LoggerInterface::class)->annotatedWith(Primary::class)->to(FileLogger::class)->in(Scope::SINGLETON);
        $this->bind(ConnectionInterface::class)->toProvider(ConnectionProvider::class, 'read')->in('Prototype');
        $this->bind(ConnectionInterface::class)->annotatedWith('write')
            ->toProvider(context: 'write', provider: ConnectionProvider::class);
        $this->bind(Mailer::class)->in(Scope::SINGLETON);
        $this->bind(Mailer::class)->annotatedWith('smtp')
            ->toConstructor(Mailer::class, 'host=smtp_host,port=smtp_port');
        $this->bind(Mailer::class)->annotatedWith('queue')
            ->toConstructor(Mailer::class, ['host' => 'queue_host'], null, 'init');
        $this->bind(NotifierInterface::class)->toNull();
        $this->bind()->annotatedWith('app_name')->toInstance('acme');
        $this->bind()->annotatedWith('retry')->toInstance((int) $this->retry);
        $this->bind()->annotatedWith('hosts')->toInstance(['a.example', 'b.example']);
        $this->bind(Clock::class)->toInstance(new Clock());
        $this->bind()->annotatedWith('config')->toInstance($this->config);

        // Unresolved declarations; each line triggers exactly one reason.
        $this->bind(Clock::class)->to(Clock::class)->asEagerSingleton();
        $this->bind(LoggerInterface::class)->to(FileLogger::class)->toNull();
        $this->bind(LoggerInterface::class)->annotatedWith('a')->annotatedWith('b')->to(FileLogger::class);
        $this->bind(LoggerInterface::class)->to(FileLogger::class)->annotatedWith('late');
        $this->bind(LoggerInterface::class)->in(Scope::SINGLETON)->to(FileLogger::class);
        $this->bind(NotifierInterface::class)->toNull('extra');
        $this->bind(LoggerInterface::class)->annotatedWith($qualifier)->to(FileLogger::class);
        $this->bind(LoggerInterface::class)->to(FileLogger::class)->in($scope);
        $this->bind(LoggerInterface::class)->to(FileLogger::class)->in('singleton');
        $this->bind(LoggerInterface::class)->to($loggerClass);
        $this->bind(ConnectionInterface::class)->toProvider(ConnectionProvider::class, $context);
        $this->bind(Mailer::class)->toConstructor(Mailer::class, $names);
        $this->bind(Mailer::class)->toConstructor(
            Mailer::class,
            'host=smtp_host',
            (new InjectionPoints())->addMethod('setLogger'),
        );
        $this->bind(Mailer::class)->toConstructor(Mailer::class, [], null, $postConstruct);
        $this->bind()->annotatedWith('orphan');
    }
}
