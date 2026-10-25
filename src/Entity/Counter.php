<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * How many times an event happened on a day (in the configured timezone). One row per
 * day and event, so the table stays tiny: no address, no cookie, no visitor stored.
 *
 * Written through DBAL by Store (an atomic upsert), never through the entity manager:
 * the application's pending changes are never flushed by Echo. The class exists for the
 * schema, so that doctrine:migrations:diff creates the table.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'echo_counter')]
class Counter
{
    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    public \DateTimeImmutable $day;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    public string $event;

    #[ORM\Column(type: 'integer')]
    public int $total = 0;
}
