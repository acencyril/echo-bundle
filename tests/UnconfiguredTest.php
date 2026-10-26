<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Tests;

use Acencyril\EchoBundle\Postman;
use Acencyril\EchoBundle\Settings;
use Acencyril\EchoBundle\Tests\Fixtures\MemoryLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Translation\Translator;

/** Installed without its configuration: no owner, no sender. Nothing is sent, a warning says why. */
final class UnconfiguredTest extends TestCase
{
    public function testNoEmailWithoutOwnerOrSender(): void
    {
        $mailer = new class implements MailerInterface {
            public int $sent = 0;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                ++$this->sent;
            }
        };
        $logger = new MemoryLogger();
        $postman = new Postman($mailer, new Translator('en'), $logger, new Settings(ConfigurationTest::process([])));

        self::assertFalse($postman->sendEvent('signup', ['email' => 'jane@example.test'], new \DateTimeImmutable()));
        self::assertSame(0, $mailer->sent);
        self::assertStringContainsString('set "owner" and "sender"', $logger->records[0]['message'] ?? '');
    }
}
