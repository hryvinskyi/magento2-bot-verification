<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Text;

use Hryvinskyi\BotVerification\Api\LineListReaderInterface;

/**
 * Reads a text as a list of lines: split at every line break, each line trimmed, blank lines dropped
 *
 * The text is read as bytes, so invalid UTF-8 is split at the same breaks as valid text and never fails.
 */
class LineListReader implements LineListReaderInterface
{
    /**
     * @inheritDoc
     */
    public function read(string $text): array
    {
        $lines = preg_split('/\R/', $text);

        return array_values(array_filter(
            array_map('trim', $lines === false ? [] : $lines),
            static fn (string $line): bool => $line !== ''
        ));
    }
}
