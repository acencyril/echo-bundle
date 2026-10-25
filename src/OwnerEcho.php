<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What the application calls at each step of its funnel:
 *
 *     $echo->event('signup', ['email' => $user->email, 'plan' => 'pro']);
 *     $echo->event('payment', ['amount' => 4900, 'currency' => 'EUR', 'email' => $email]);
 *     $echo->event('page_view');
 *
 * Every event is counted for the day (unless its mode is "ignore"). An "immediate" one
 * is also emailed at once, within the hourly throttle and outside quiet hours; an
 * "immediate" or "digest" one is listed in the next morning's digest.
 *
 * Never throws: a broken database or mailer is logged, and the page carries on.
 */
final class OwnerEcho
{
    /** Crawlers, monitors, link previews, scripts. An empty user agent counts as a bot. */
    private const BOTS = '#bot|crawl|spider|slurp|preview|monitor|curl|wget|python|go-http|java/|okhttp|axios|node-fetch|headless|phantom|lighthouse|pagespeed|facebookexternalhit|embedly|uptime|pingdom|statuscake|validator#i';

    private const MAX_KEYS = 20;
    private const MAX_LENGTH = 500;

    private readonly ClockInterface $clock;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Store $store,
        private readonly Postman $postman,
        private readonly Settings $settings,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        private readonly ?RequestStack $requests = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param string                $name    lowercase: signup, payment, page_view…
     * @param array<string, mixed> $context scalars (others are dropped); "amount" in minor units
     *                                      (cents) with an optional "currency"; "user_agent" for
     *                                      the bot filter when there is no current request
     *
     * @return bool whether the event was counted (false: ignored, a bot, invalid, or an error)
     */
    public function event(string $name, array $context = []): bool
    {
        try {
            return $this->handle($name, $context);
        } catch (\Throwable $e) {
            $this->logger->error('Echo: event "{event}" lost: {error}', ['event' => $name, 'error' => $e->getMessage(), 'exception' => $e]);

            return false;
        }
    }

    public static function isBot(?string $userAgent): bool
    {
        return null === $userAgent || '' === trim($userAgent) || 1 === preg_match(self::BOTS, $userAgent);
    }

    public static function isValidName(string $name): bool
    {
        return 1 === preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $name);
    }

    private function handle(string $name, array $context): bool
    {
        if (!self::isValidName($name)) {
            $this->logger->warning('Echo: invalid event name "{event}" (lowercase letters, digits, "_", "-", "."), ignored.', ['event' => $name]);

            return false;
        }
        $rules = $this->settings->event($name);
        if ('ignore' === $rules['mode']) {
            return false;
        }

        $userAgent = $context['user_agent'] ?? $this->requests?->getCurrentRequest()?->headers->get('User-Agent');
        if ($rules['skip_bots'] && self::isBot(\is_string($userAgent) ? $userAgent : null)) {
            return false;
        }
        unset($context['user_agent']);
        $context = self::clean($context);

        $now = $this->clock->now();
        $this->store->count($this->settings->day($now), $name);

        $mode = $rules['mode'];
        if (null !== $rules['min_amount'] && (!is_numeric($context['amount'] ?? null) || (float) $context['amount'] < $rules['min_amount'])) {
            // A small payment (a test, a free order) is counted, not announced.
            return true;
        }
        if ('count' === $mode) {
            return true;
        }

        $mailed = false;
        if ('immediate' === $mode && !$this->settings->isQuiet($now)
            && $this->store->mailedSince($name, $now->modify('-1 hour')) < $this->settings->throttle($name)) {
            $mailed = $this->postman->sendEvent($name, $context, $now);
        }
        $this->store->record($name, $context, $mailed, $now);

        return true;
    }

    /** @return array<string, scalar|null> scalars only, short strings, a bounded number of keys */
    private static function clean(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (\count($out) >= self::MAX_KEYS) {
                break;
            }
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i');
            } elseif ($value instanceof \Stringable || $value instanceof \BackedEnum) {
                $value = $value instanceof \BackedEnum ? $value->value : (string) $value;
            }
            if (null !== $value && !\is_scalar($value)) {
                continue;
            }
            if (\is_string($value) && mb_strlen($value) > self::MAX_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_LENGTH);
            }
            $out[mb_substr((string) $key, 0, 64)] = $value;
        }

        return $out;
    }
}
