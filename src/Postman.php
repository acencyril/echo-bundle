<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writes and sends the two emails: one event, and the digest. Plain text only, short
 * lines, nothing remote: readable on a phone, no tracking pixel, no link rewriting.
 * A failed email is logged and reported as false, never thrown.
 *
 * @internal
 */
final class Postman
{
    public const DOMAIN = 'echo';

    /** Currencies without minor unit: "amount" is then in units (same list as Stripe). */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Shown in a line or a subject in this order, before the other keys. */
    private const FIRST = ['email', 'amount'];

    /** Never shown as such. */
    private const HIDDEN = ['currency', 'user_agent'];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        ?LoggerInterface $logger,
        private readonly Settings $settings,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /** @param array<string, scalar|null> $context */
    public function sendEvent(string $event, array $context, \DateTimeImmutable $at): bool
    {
        $label = $this->label($event);
        $summary = $this->summary($context);
        $subject = '' === $summary
            ? $this->t('echo.mail.event.subject_bare', ['%label%' => $label])
            : $this->t('echo.mail.event.subject', ['%label%' => $label, '%summary%' => $summary]);

        $lines = [$label, $this->date($at).', '.$this->time($at), ''];
        foreach ($this->details($context) as $line) {
            $lines[] = $line;
        }

        return $this->deliver(self::cut($subject, 140), $lines);
    }

    /**
     * @param array<string, int>                                                               $counters event => total
     * @param list<array{event: string, context: array<string, mixed>, mailed: bool, at: \DateTimeImmutable}> $entries
     */
    public function sendDigest(\DateTimeImmutable $day, array $counters, array $entries, int $limit = 50): bool
    {
        return $this->deliver(...$this->digest($day, $counters, $entries, $limit));
    }

    /**
     * The digest as [subject, lines], also used by "echo:digest --dry-run".
     *
     * @param array<string, int>                                                               $counters
     * @param list<array{event: string, context: array<string, mixed>, mailed: bool, at: \DateTimeImmutable}> $entries
     *
     * @return array{0: string, 1: list<string>}
     */
    public function digest(\DateTimeImmutable $day, array $counters, array $entries, int $limit = 50): array
    {
        $date = $this->date($day);
        $figures = [];
        foreach (\array_slice($counters, 0, 3, true) as $event => $n) {
            $figures[] = $this->label($event).' '.$n;
        }
        $subject = $this->t('echo.digest.subject', ['%date%' => $date, '%figures%' => implode(' · ', $figures)]);

        $lines = [$this->t('echo.digest.title', ['%date%' => $date]), ''];
        foreach ($counters as $event => $n) {
            $lines[] = $this->t('echo.digest.counter', ['%label%' => $this->label($event), '%count%' => $n]);
        }

        if ([] !== $entries) {
            $lines[] = '';
            $lines[] = $this->t('echo.digest.notable');
            foreach (\array_slice($entries, 0, $limit) as $e) {
                $summary = $this->summary($e['context']);
                $lines[] = $this->time($e['at']).'  '.$this->label($e['event']).('' === $summary ? '' : ' — '.$summary);
            }
            if (\count($entries) > $limit) {
                $lines[] = $this->t('echo.digest.more', ['%count%' => \count($entries) - $limit]);
            }
            $held = \count(array_filter($entries, fn (array $e) => !$e['mailed'] && 'immediate' === $this->settings->event($e['event'])['mode']));
            if ($held > 0) {
                $lines[] = '';
                $lines[] = $this->t('echo.digest.held', ['%count%' => $held]);
            }
        }

        return [self::cut($subject, 140), $lines];
    }

    public function label(string $event): string
    {
        $label = $this->settings->event($event)['label'];
        if (null !== $label && '' !== $label) {
            return $label;
        }
        $key = 'echo.event.'.$event;
        $t = $this->translator->trans($key, [], self::DOMAIN, $this->settings->locale());

        return $t !== $key ? $t : ucfirst(str_replace(['_', '-', '.'], ' ', $event));
    }

    /** One short line: the email, the amount, then the other values. */
    public function summary(array $context): string
    {
        $parts = [];
        foreach ($this->ordered($context) as $key => $value) {
            $parts[] = \in_array($key, self::FIRST, true) ? $value : $key.': '.$value;
            if (\count($parts) >= 4) {
                break;
            }
        }

        return self::cut(implode(', ', $parts), 120);
    }

    /** @return list<string> "key: value", one per line */
    public function details(array $context): array
    {
        $out = [];
        foreach ($this->ordered($context) as $key => $value) {
            $out[] = $key.': '.$value;
        }

        return $out;
    }

    public function money(int|float|string $amount, ?string $currency = null): string
    {
        $currency = strtoupper((string) ($currency ?: $this->settings->config['currency'] ?? 'EUR'));
        $value = (float) $amount / (\in_array($currency, self::ZERO_DECIMAL, true) ? 1 : 100);
        $locale = $this->settings->locale();
        if (class_exists(\NumberFormatter::class)) {
            $f = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            $s = $f->formatCurrency($value, $currency);
            if (false !== $s) {
                return str_replace(["\u{202F}", "\u{A0}"], ' ', $s);
            }
        }
        $digits = \in_array($currency, self::ZERO_DECIMAL, true) ? 0 : 2;

        return 'fr' === $locale
            ? number_format($value, $digits, ',', ' ').' '.$currency
            : $currency.' '.number_format($value, $digits, '.', ',');
    }

    /** @return array<string, string> the displayable values, email and amount first */
    private function ordered(array $context): array
    {
        $out = [];
        foreach (self::FIRST as $key) {
            if (isset($context[$key]) && '' !== $context[$key]) {
                $out[$key] = 'amount' === $key && is_numeric($context[$key])
                    ? $this->money($context[$key], isset($context['currency']) ? (string) $context['currency'] : null)
                    : $this->value($context[$key]);
            }
        }
        foreach ($context as $key => $value) {
            $key = (string) $key;
            if (isset($out[$key]) || \in_array($key, self::HIDDEN, true) || null === $value || '' === $value) {
                continue;
            }
            $out[$key] = $this->value($value);
        }

        return $out;
    }

    private function value(mixed $v): string
    {
        if (\is_bool($v)) {
            return $this->t($v ? 'echo.yes' : 'echo.no');
        }

        return self::cut(preg_replace('/\s+/u', ' ', trim((string) (\is_scalar($v) ? $v : json_encode($v)))) ?? '', 100);
    }

    /** @param list<string> $lines */
    private function deliver(string $subject, array $lines): bool
    {
        $c = $this->settings->config;
        $footer = ['', '--'];
        if (!empty($c['admin_url'])) {
            $footer[] = $this->t('echo.mail.admin', ['%url%' => $c['admin_url']]);
        }
        $footer[] = $this->t('echo.mail.footer');

        try {
            $email = (new Email())
                ->from(new Address((string) $c['sender'], (string) $c['site_name']))
                ->to((string) $c['owner'])
                ->subject($subject)
                ->text(implode("\n", array_merge($lines, $footer))."\n");
            // No auto-reply ("out of office") to a machine.
            $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Echo: the email "{subject}" could not be sent: {error}', ['subject' => $subject, 'error' => $e->getMessage(), 'exception' => $e]);

            return false;
        }
    }

    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params + ['%site%' => (string) ($this->settings->config['site_name'] ?? '')], self::DOMAIN, $this->settings->locale());
    }

    private function date(\DateTimeImmutable $at): string
    {
        $local = $at->setTimezone($this->settings->timezone);
        if (class_exists(\IntlDateFormatter::class)) {
            $pattern = 'fr' === $this->settings->locale() ? 'EEEE d MMMM y' : 'EEEE, MMMM d, y';
            $f = new \IntlDateFormatter($this->settings->locale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $this->settings->timezone, null, $pattern);
            $s = $f->format($local);
            if (false !== $s) {
                return $s;
            }
        }

        return $local->format('Y-m-d');
    }

    private function time(\DateTimeImmutable $at): string
    {
        return $at->setTimezone($this->settings->timezone)->format('H:i');
    }

    private static function cut(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)).'…' : $s;
    }
}
