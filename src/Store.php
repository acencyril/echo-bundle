<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;

/**
 * The two tables (see Entity\Counter and Entity\Entry), read and written through DBAL so
 * that Echo never flushes the application's entity manager. Times are stored in UTC,
 * days in the configured timezone.
 *
 * @internal
 */
final class Store
{
    public const COUNTERS = 'echo_counter';
    public const ENTRIES = 'echo_entry';

    public function __construct(private readonly Connection $db)
    {
    }

    /** Adds $n to the event's counter of $day (Y-m-d). */
    public function count(string $day, string $event, int $n = 1): void
    {
        $platform = $this->db->getDatabasePlatform();
        $params = ['d' => $day, 'e' => $event, 'n' => $n];

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->db->executeStatement('INSERT INTO echo_counter (day, event, total) VALUES (:d, :e, :n) ON DUPLICATE KEY UPDATE total = total + VALUES(total)', $params);

            return;
        }
        // PostgreSQL and SQLite (3.24+) share the ON CONFLICT syntax.
        if ($platform instanceof PostgreSQLPlatform || str_contains(strtolower($platform::class), 'sqlite')) {
            $this->db->executeStatement('INSERT INTO echo_counter (day, event, total) VALUES (:d, :e, :n) ON CONFLICT (day, event) DO UPDATE SET total = echo_counter.total + excluded.total', $params);

            return;
        }
        // Any other platform: update, else insert, else (a concurrent insert won) update.
        if (0 < $this->db->executeStatement('UPDATE echo_counter SET total = total + :n WHERE day = :d AND event = :e', $params)) {
            return;
        }
        try {
            $this->db->insert(self::COUNTERS, ['day' => $day, 'event' => $event, 'total' => $n]);
        } catch (UniqueConstraintViolationException) {
            $this->db->executeStatement('UPDATE echo_counter SET total = total + :n WHERE day = :d AND event = :e', $params);
        }
    }

    /** @return array<string, int> event => total on $day, names starting with "_" excluded */
    public function counters(string $day): array
    {
        $rows = $this->db->fetchAllKeyValue('SELECT event, total FROM echo_counter WHERE day = ? ORDER BY event', [$day]);
        $out = [];
        foreach ($rows as $event => $total) {
            if (!str_starts_with((string) $event, '_') && (int) $total > 0) {
                $out[(string) $event] = (int) $total;
            }
        }

        return $out;
    }

    public function total(string $day, string $event): int
    {
        return (int) $this->db->fetchOne('SELECT total FROM echo_counter WHERE day = ? AND event = ?', [$day, $event]);
    }

    /** @param array<string, scalar|null> $context */
    public function record(string $event, array $context, bool $mailed, \DateTimeImmutable $at): void
    {
        $this->db->insert(self::ENTRIES, [
            // Sortable: the insertion time in microseconds, then random.
            'id' => sprintf('%014x', (int) (microtime(true) * 1e6)).bin2hex(random_bytes(9)),
            'event' => $event,
            'context' => json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR),
            'mailed' => $mailed,
            'created_at' => self::utc($at),
        ], ['mailed' => Types::BOOLEAN]);
    }

    /** Immediate emails sent for $event since $since. */
    public function mailedSince(string $event, \DateTimeImmutable $since): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM echo_entry WHERE event = ? AND mailed = ? AND created_at >= ?',
            [$event, true, self::utc($since)],
            [Types::STRING, Types::BOOLEAN, Types::STRING],
        );
    }

    /**
     * @return list<array{event: string, context: array<string, mixed>, mailed: bool, at: \DateTimeImmutable}> oldest first
     */
    public function entries(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT event, context, mailed, created_at FROM echo_entry WHERE created_at >= ? AND created_at < ? ORDER BY created_at, id',
            [self::utc($from), self::utc($to)],
        );

        return array_map(static fn (array $r) => [
            'event' => (string) $r['event'],
            'context' => \is_array($c = json_decode((string) $r['context'], true)) ? $c : [],
            'mailed' => (bool) $r['mailed'],
            'at' => new \DateTimeImmutable((string) $r['created_at'], new \DateTimeZone('UTC')),
        ], $rows);
    }

    public function purge(\DateTimeImmutable $before): int
    {
        return (int) $this->db->executeStatement('DELETE FROM echo_entry WHERE created_at < ?', [self::utc($before)]);
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
