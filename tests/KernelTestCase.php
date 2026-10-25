<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Tests;

use Acencyril\EchoBundle\OwnerEcho;
use Acencyril\EchoBundle\Tests\Fixtures\MemoryLogger;
use Acencyril\EchoBundle\Tests\Fixtures\TestKernel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase as BaseKernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mime\Email;

abstract class KernelTestCase extends BaseKernelTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', true, $options['echo'] ?? []);
    }

    /** Boots the application with settings merged on top of the base ones, schema created from the entities. */
    protected static function app(array $echo = [], string $environment = 'test'): OwnerEcho
    {
        self::ensureKernelShutdown();
        self::bootKernel(['environment' => $environment, 'echo' => $echo]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropSchema($meta = $em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($meta);

        return self::getContainer()->get(OwnerEcho::class);
    }

    protected static function clock(): MockClock
    {
        return self::getContainer()->get('clock');
    }

    protected static function db(): Connection
    {
        return self::getContainer()->get('database_connection');
    }

    protected static function logger(): MemoryLogger
    {
        return self::getContainer()->get('logger');
    }

    /** @return list<Email> */
    protected static function sent(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email));
    }
}
