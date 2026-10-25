<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle;

/**
 * The same call as OwnerEcho::event(), as an object: dispatch it through the event
 * dispatcher, or as a Messenger message (route it to an async transport to keep the
 * email out of the request). Keep the context scalar so it serializes.
 *
 *     $dispatcher->dispatch(new EchoEvent('signup', ['email' => $email]));
 *     $bus->dispatch(new EchoEvent('payment', ['amount' => 4900]));
 *
 * Off the request (an async worker), pass "user_agent" in the context for events that
 * skip bots: there is no request to read it from.
 */
final class EchoEvent
{
    /** @param array<string, scalar|null> $context */
    public function __construct(
        public readonly string $name,
        public readonly array $context = [],
    ) {
    }
}
