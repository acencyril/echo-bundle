<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Tests;

use Acencyril\EchoBundle\EchoEvent;
use Acencyril\EchoBundle\Store;
use Symfony\Component\Messenger\MessageBusInterface;

final class DispatchTest extends KernelTestCase
{
    public function testThroughTheEventDispatcher(): void
    {
        self::app();
        self::getContainer()->get('event_dispatcher')->dispatch(new EchoEvent('signup', ['email' => 'jane@example.test']));

        self::assertSame(1, self::getContainer()->get(Store::class)->total('2026-10-24', 'signup'));
        self::assertSame('[Example] Sign-up: jane@example.test', self::sent()[0]->getSubject());
    }

    public function testThroughMessenger(): void
    {
        self::app();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new EchoEvent('newsletter', ['email' => 'a@example.test']));

        self::assertSame(1, self::getContainer()->get(Store::class)->total('2026-10-24', 'newsletter'));
    }

    public function testTheServiceIdAndAlias(): void
    {
        self::app();
        self::assertSame(self::getContainer()->get('echo'), self::getContainer()->get(\Acencyril\EchoBundle\OwnerEcho::class));
    }
}
