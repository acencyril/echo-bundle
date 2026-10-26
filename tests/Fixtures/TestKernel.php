<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Tests\Fixtures;

use Acencyril\EchoBundle\EchoBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A bare application: FrameworkBundle (mailer, translator, messenger), DoctrineBundle on
 * an in-memory SQLite database (or ECHO_TEST_DATABASE_URL), and Echo. The clock is a MockClock set on a Saturday
 * morning in Paris; the "broken" environment has a mailer that cannot connect.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const NOW = '2026-10-24 10:00:00';

    public function __construct(string $environment, bool $debug, private readonly array $echo = [])
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new DoctrineBundle(), new EchoBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return \dirname(__DIR__, 2).'/var/cache/'.$this->environment.'-'.md5(serialize([$this->echo, getenv('ECHO_TEST_DATABASE_URL')]));
    }

    public function getLogDir(): string
    {
        return \dirname(__DIR__, 2).'/var/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'translator' => ['default_path' => __DIR__.'/translations', 'fallbacks' => ['en']],
            'default_locale' => 'en',
            'mailer' => ['dsn' => 'broken' === $this->environment ? 'smtp://127.0.0.1:1' : 'null://null'],
            'messenger' => ['enabled' => true],
        ]);
        $container->loadFromExtension('doctrine', [
            // ECHO_TEST_DATABASE_URL runs the suite on another platform (PostgreSQL, MySQL).
            'dbal' => ($url = getenv('ECHO_TEST_DATABASE_URL')) ? ['url' => $url] : ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm' => [
                'auto_mapping' => false,
                'controller_resolver' => ['auto_mapping' => false],
            ] + (self::doctrineBundle2() ? [
                // DoctrineBundle 2 only: version 3 made these the default and removed the options.
                'enable_lazy_ghost_objects' => true,
                'report_fields_where_declared' => true,
            ] : []),
        ]);
        $container->register('logger', MemoryLogger::class)->setPublic(true);
        $container->register('clock', MockClock::class)
            ->setArguments([self::NOW, 'Europe/Paris'])->setPublic(true);

        $container->loadFromExtension('echo', self::baseConfig());
        if ([] !== $this->echo) {
            $container->loadFromExtension('echo', $this->echo);
        }
    }

    public static function baseConfig(): array
    {
        return [
            'owner' => 'owner@example.test',
            'sender' => 'noreply@example.test',
            'site_name' => 'Example',
            'locale' => 'en',
            'timezone' => 'Europe/Paris',
            'admin_url' => 'https://example.test/admin',
            'throttle' => 3,
            'events' => [
                'signup' => 'immediate',
                'payment' => ['mode' => 'immediate', 'min_amount' => 1000],
                'newsletter' => 'digest',
                'page_view' => ['mode' => 'count', 'skip_bots' => true],
                'healthcheck' => 'ignore',
            ],
        ];
    }

    private static function doctrineBundle2(): bool
    {
        $version = \Composer\InstalledVersions::getVersion('doctrine/doctrine-bundle') ?? '2';

        return version_compare($version, '3.0.0', '<');
    }
}
