<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\EventListener;

use Acencyril\EchoBundle\EchoEvent;
use Acencyril\EchoBundle\OwnerEcho;

/** Listener for EchoEvent on the event dispatcher, and its Messenger handler. */
final class EchoEventHandler
{
    public function __construct(private readonly OwnerEcho $echo)
    {
    }

    public function __invoke(EchoEvent $event): void
    {
        $this->echo->event($event->name, $event->context);
    }
}
