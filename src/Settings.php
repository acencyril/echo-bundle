<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle;

/**
 * The processed configuration, with the few rules read from it.
 *
 * @internal
 */
final class Settings
{
    public readonly \DateTimeZone $timezone;

    /** @param array<string, mixed> $config the processed echo configuration */
    public function __construct(public readonly array $config)
    {
        $this->timezone = new \DateTimeZone($config['timezone'] ?? date_default_timezone_get());
    }

    /**
     * @return array{mode: string, label: ?string, min_amount: ?int, skip_bots: bool, throttle: ?int}
     */
    public function event(string $name): array
    {
        return ($this->config['events'][$name] ?? []) + [
            'mode' => $this->config['default_mode'] ?? 'count',
            'label' => null,
            'min_amount' => null,
            'skip_bots' => false,
            'throttle' => null,
        ];
    }

    public function throttle(string $name): int
    {
        return $this->event($name)['throttle'] ?? (int) ($this->config['throttle'] ?? 10);
    }

    /** @return list<string> the configured events, in the order of the configuration */
    public function order(): array
    {
        return array_map('strval', array_keys($this->config['events'] ?? []));
    }

    /** Is $now inside the quiet window (no immediate email)? */
    public function isQuiet(\DateTimeImmutable $now): bool
    {
        $q = $this->config['quiet_hours'] ?? ['enabled' => false];
        if (!($q['enabled'] ?? false)) {
            return false;
        }
        $local = $now->setTimezone($this->timezone);
        if (($q['weekends'] ?? false) && (int) $local->format('N') >= 6) {
            return true;
        }
        $t = $local->format('H:i');
        $from = $q['from'];
        $to = $q['to'];
        if ($from === $to) {
            return false;
        }

        // A window over midnight (20:00 -> 08:00) or within the day (12:00 -> 14:00).
        return $from > $to ? ($t >= $from || $t < $to) : ($t >= $from && $t < $to);
    }

    public function day(\DateTimeImmutable $at): string
    {
        return $at->setTimezone($this->timezone)->format('Y-m-d');
    }

    public function locale(): string
    {
        return $this->config['locale'] ?? 'en';
    }
}
