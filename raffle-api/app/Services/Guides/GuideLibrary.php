<?php

namespace App\Services\Guides;

use InvalidArgumentException;

/**
 * The built-in help guides, written as plain text in database/guides/*.md (one
 * file per topic, in file-name order; the format is described in GuideParser). Each guide becomes an array:
 *
 *   key        a short permanent id, e.g. 'deposit-first-top-up'
 *   category   one of the Learning Hub categories
 *   title      what the member is trying to do, in their words
 *   excerpt    one sentence shown on the list
 *   time       reading time, e.g. '3 min'
 *   intro      a sentence or two before the steps
 *   steps      the numbered steps
 *   tips       "Good to know" points (optional)
 *   stuck      what to do if it still does not work (optional)
 *   image      name of a screenshot in public/guides/ without the extension (optional)
 *   caption    a line under that screenshot (optional)
 *   featured   true for the one guide shown big at the top (optional)
 *   blocks     instead of intro/steps/tips: a free list of ['h' => ..], ['p' => ..],
 *              ['ol' => [..]], ['ul' => [..]], ['note' => ..] (optional)
 *
 * In any text, **bold** and [label](/path) work, and {{placeholders}} are filled from
 * the site's current settings (App\Support\GuideTokens).
 */
class GuideLibrary
{
    public function __construct(private readonly GuideParser $parser) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $guides = [];
        $seen = [];

        foreach (glob(database_path('guides/*.md')) ?: [] as $file) {
            foreach ($this->parser->parse((string) file_get_contents($file), basename($file)) as $guide) {
                foreach (['key', 'category', 'title', 'excerpt'] as $required) {
                    if (empty($guide[$required])) {
                        throw new InvalidArgumentException('A guide in '.basename($file)." is missing '{$required}'.");
                    }
                }

                if (isset($seen[$guide['key']])) {
                    throw new InvalidArgumentException("Guide key '{$guide['key']}' is used twice.");
                }

                $seen[$guide['key']] = true;
                $guides[] = $guide;
            }
        }

        return $guides;
    }
}
