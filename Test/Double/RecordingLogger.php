<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Double;

use Psr\Log\AbstractLogger;

/**
 * Records every message it is given with its level, in order, and writes nothing anywhere
 */
class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string}> Messages logged, in order
     */
    public array $records = [];

    /**
     * Record a message at a level
     *
     * @param mixed $level Level, one of the PSR-3 level names
     * @param string|\Stringable $message Message as logged
     * @param array<mixed> $context Ignored
     * @return void
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : get_debug_type($level),
            'message' => (string) $message,
        ];
    }
}
