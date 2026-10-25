<?php

declare(strict_types=1);

use Acencyril\EchoBundle\Command\DigestCommand;
use Acencyril\EchoBundle\EchoEvent;
use Acencyril\EchoBundle\EventListener\EchoEventHandler;
use Acencyril\EchoBundle\OwnerEcho;
use Acencyril\EchoBundle\Postman;
use Acencyril\EchoBundle\Settings;
use Acencyril\EchoBundle\Store;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $c): void {
    $services = $c->services()->defaults()->autoconfigure(false)->autowire(false);

    // The processed configuration is set by EchoExtension.
    $services->set(Settings::class)->args([[]]);

    $services->set(Store::class)->args([service('database_connection')]);

    $services->set(Postman::class)
        ->args([service('mailer'), service('translator'), service('logger')->nullOnInvalid(), service(Settings::class)])
        ->tag('monolog.logger', ['channel' => 'echo']);

    $services->set(OwnerEcho::class)
        ->args([
            service(Store::class),
            service(Postman::class),
            service(Settings::class),
            service('clock')->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
            service('request_stack')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'echo'])
        ->public();
    $services->alias('echo', OwnerEcho::class)->public();

    $services->set(DigestCommand::class)
        ->args([service(Store::class), service(Postman::class), service(Settings::class), service('clock')->nullOnInvalid()])
        ->tag('console.command', ['command' => 'echo:digest']);

    $services->set(EchoEventHandler::class)
        ->args([service(OwnerEcho::class)])
        ->tag('kernel.event_listener', ['event' => EchoEvent::class, 'method' => '__invoke'])
        ->tag('messenger.message_handler', ['handles' => EchoEvent::class]);
};
