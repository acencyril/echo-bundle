<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\DependencyInjection;

use Acencyril\EchoBundle\OwnerEcho;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * config/packages/echo.yaml. Addresses are not checked here: they usually come from
 * %env()%, unknown until runtime. A wrong address only makes the emails fail, and a
 * failed email is logged, never thrown.
 *
 * Nothing is required: installed without its recipe (Flex registers the bundle before
 * echo.yaml exists), the application still boots; events are counted, and no email is
 * sent until "owner" and "sender" are set (a warning says so).
 */
final class Configuration implements ConfigurationInterface
{
    public const MODES = ['immediate', 'digest', 'count', 'ignore'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('echo');
        $root = $tree->getRootNode();
        $hhmm = static fn ($v) => !\is_string($v) || 1 !== preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v);

        $root
            ->children()
                ->scalarNode('owner')->defaultNull()
                    ->info('Who receives the emails: the site owner. Unset: no email is sent.')->end()
                ->scalarNode('sender')->defaultNull()
                    ->info('The From address, one your mail provider accepts for this site. Unset: no email is sent.')->end()
                ->scalarNode('site_name')->defaultValue('Echo')
                    ->info('Shown in every subject, so the emails of several sites can be told apart.')->end()
                ->enumNode('locale')->values(['fr', 'en'])->defaultValue('en')->end()
                ->scalarNode('timezone')->defaultNull()
                    ->info('Days, hours and quiet hours are read in this timezone. Default: the PHP one.')
                    ->validate()->ifTrue(static fn ($v) => null !== $v && !\in_array($v, \DateTimeZone::listIdentifiers(), true))
                        ->thenInvalid('Unknown timezone %s.')->end()
                ->end()
                ->scalarNode('admin_url')->defaultNull()
                    ->info('Optional link at the end of every email.')->end()
                ->scalarNode('currency')->defaultValue('EUR')
                    ->info('Used for an "amount" given without a "currency".')->end()
                ->enumNode('default_mode')->values(self::MODES)->defaultValue('count')
                    ->info('For an event not listed under "events".')->end()
                ->integerNode('throttle')->defaultValue(10)->min(1)
                    ->info('At most this many immediate emails per hour and per event; the rest wait for the digest.')->end()
                ->arrayNode('quiet_hours')
                    ->info('No immediate email in this window; the events wait for the digest.')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('from')->defaultValue('20:00')
                            ->validate()->ifTrue($hhmm)->thenInvalid('Expected HH:MM, got %s.')->end()
                        ->end()
                        ->scalarNode('to')->defaultValue('08:00')
                            ->validate()->ifTrue($hhmm)->thenInvalid('Expected HH:MM, got %s.')->end()
                        ->end()
                        ->booleanNode('weekends')->defaultFalse()
                            ->info('Saturday and Sunday quiet all day.')->end()
                    ->end()
                ->end()
                ->integerNode('retention_days')->defaultValue(30)->min(1)
                    ->info('Notable events (with their details) are deleted after this many days. Counters stay.')->end()
                ->arrayNode('events')
                    ->useAttributeAsKey('name')->normalizeKeys(false)
                    ->validate()
                        ->ifTrue(static fn (array $events) => [] !== array_filter(array_keys($events), static fn ($n) => !OwnerEcho::isValidName((string) $n)))
                        ->thenInvalid('Event names are lowercase letters, digits, "_", "-" or ".", 64 characters at most: %s.')
                    ->end()
                    ->arrayPrototype()
                        ->beforeNormalization()->ifString()->then(static fn (string $mode) => ['mode' => $mode])->end()
                        ->children()
                            ->enumNode('mode')->values(self::MODES)->defaultValue('count')->end()
                            ->scalarNode('label')->defaultNull()
                                ->info('Shown instead of the translation of echo.event.<name>.')->end()
                            ->integerNode('min_amount')->defaultNull()->min(0)
                                ->info('In minor units (cents). Below it, the event is only counted.')->end()
                            ->booleanNode('skip_bots')->defaultFalse()
                                ->info('Ignore crawlers, monitors and link previews (by user agent). For page views.')->end()
                            ->integerNode('throttle')->defaultNull()->min(1)
                                ->info('Overrides the global throttle for this event.')->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tree;
    }
}
