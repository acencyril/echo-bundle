<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Tests\Fixtures;

use Psr\Log\AbstractLogger;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => strtr((string) $message, array_map(static fn ($v) => \is_scalar($v) ? (string) $v : '', array_combine(array_map(static fn ($k) => '{'.$k.'}', array_keys($context)), $context)))];
    }

    /** @return list<string> */
    public function errors(): array
    {
        return array_values(array_map(static fn ($r) => $r['message'], array_filter($this->records, static fn ($r) => 'error' === $r['level'])));
    }
}
