<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\DependencyInjection;

use Acencyril\EchoBundle\Settings;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class EchoExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Maps the two entities, so doctrine:migrations:diff creates the tables without the
     * application having to declare anything. Skipped when the application declares its
     * own entity managers: it then adds the mapping itself (see the README).
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            if (isset($config['orm']['entity_managers'])) {
                return;
            }
        }
        $container->prependExtensionConfig('doctrine', ['orm' => ['mappings' => ['EchoBundle' => [
            'type' => 'attribute',
            'is_bundle' => false,
            'dir' => \dirname(__DIR__).'/Entity',
            'prefix' => 'Acencyril\EchoBundle\Entity',
            'alias' => 'Echo',
        ]]]]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        (new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config')))->load('services.php');

        // Escaped: a label or a site name may hold a "%".
        $container->getDefinition(Settings::class)
            ->setArgument(0, $container->getParameterBag()->escapeValue($config));
    }

    public function getAlias(): string
    {
        return 'echo';
    }
}
