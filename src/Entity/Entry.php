<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One notable event (an "immediate" or "digest" one) with its details, for the digest
 * and the hourly throttle. Deleted after retention_days by echo:digest.
 *
 * The id is generated in PHP (sortable: insertion time, then random) so the raw insert
 * works on every platform, whatever the identity strategy of the application. Written through DBAL by Store.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'echo_entry')]
#[ORM\Index(name: 'idx_echo_entry_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_echo_entry_event_created', columns: ['event', 'created_at'])]
class Entry
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 32)]
    public string $id;

    #[ORM\Column(type: 'string', length: 64)]
    public string $event;

    /** The context given to event(), scalars only. */
    #[ORM\Column(type: 'json')]
    public array $context = [];

    /** Emailed at once (false: digest mode, quiet hours, throttled, or the email failed). */
    #[ORM\Column(type: 'boolean')]
    public bool $mailed = false;

    /** UTC. */
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;
}
