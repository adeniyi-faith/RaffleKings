<?php

namespace App\Services\Guides;

use InvalidArgumentException;

/**
 * Reads the guide files in database/guides/*.md. Each guide starts with a line
 * "=== the-key", then "name: value" lines (category, title, excerpt, time, image,
 * caption, featured), a blank line, then the text:
 *
 *   An opening paragraph or two.
 *
 *   1. A step.
 *   2. Another step (a line indented under a step carries on that step).
 *
 *   Good to know:
 *   - A tip.
 *
 *   Stuck: What to do if it still does not work.
 *
 * Guides that need their own layout use "## Heading", "> a highlighted note" and
 * "!image: name | caption" lines instead, and are then shown exactly in that order.
 */
class GuideParser
{
    private const HEADERS = ['category', 'title', 'excerpt', 'time', 'image', 'caption', 'featured'];

    /** @return list<array<string, mixed>> */
    public function parse(string $text, string $source = 'guides'): array
    {
        $guides = [];

        foreach (preg_split('/^=== */m', $text, -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
            if (trim($chunk) === '') {
                continue;
            }

            $lines = preg_split('/\R/', trim($chunk));
            $key = trim((string) array_shift($lines));

            if (! preg_match('/^[a-z0-9-]+$/', $key)) {
                throw new InvalidArgumentException("{$source}: '{$key}' is not a valid guide key (use lowercase letters, numbers and dashes).");
            }

            $guide = ['key' => $key];

            while ($lines !== [] && trim($lines[0]) !== '') {
                $line = array_shift($lines);

                if (! preg_match('/^([a-z]+):\s*(.*)$/', $line, $m) || ! in_array($m[1], self::HEADERS, true)) {
                    throw new InvalidArgumentException("{$source}: guide '{$key}' has an unreadable header line: {$line}");
                }

                $guide[$m[1]] = $m[1] === 'featured' ? in_array(strtolower($m[2]), ['yes', 'true', '1'], true) : trim($m[2]);
            }

            $guides[] = $guide + $this->body($lines, $key, $source);
        }

        return $guides;
    }

    /** @param  list<string>  $lines */
    private function body(array $lines, string $key, string $source): array
    {
        $blocks = $this->blocks($lines);
        $manual = collect($blocks)->contains(fn ($b) => isset($b['h']) || isset($b['note']) || isset($b['image']));

        if ($manual) {
            return ['blocks' => array_values(array_filter($blocks, fn ($b) => ! isset($b['stuck'])))] + $this->stuckOf($blocks);
        }

        $out = ['intro' => '', 'steps' => [], 'tips' => []];
        $intro = [];
        $inTips = false;

        foreach ($blocks as $block) {
            if (isset($block['stuck'])) {
                $out['stuck'] = $block['stuck'];
            } elseif (isset($block['p']) && preg_match('/^good to know:?$/i', $block['p'])) {
                $inTips = true;
            } elseif (isset($block['ol'])) {
                $out['steps'] = array_merge($out['steps'], $block['ol']);
            } elseif (isset($block['ul'])) {
                $out['tips'] = array_merge($out['tips'], $block['ul']);
            } elseif (isset($block['p'])) {
                $intro[] = $block['p'];
            }
        }

        $out['intro'] = implode("\n\n", $intro);

        if ($out['steps'] === [] && $out['tips'] === [] && $out['intro'] === '') {
            throw new InvalidArgumentException("{$source}: guide '{$key}' has no text.");
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $blocks */
    private function stuckOf(array $blocks): array
    {
        foreach ($blocks as $block) {
            if (isset($block['stuck'])) {
                return ['stuck' => $block['stuck']];
            }
        }

        return [];
    }

    /** @param  list<string>  $lines */
    private function blocks(array $lines): array
    {
        $blocks = [];
        $para = [];
        $list = null; // ['ol'|'ul', items]

        $flush = function () use (&$blocks, &$para, &$list) {
            if ($para !== []) {
                $blocks[] = ['p' => implode(' ', $para)];
                $para = [];
            }

            if ($list !== null) {
                $blocks[] = [$list[0] => $list[1]];
                $list = null;
            }
        };

        foreach ($lines as $line) {
            $trim = trim($line);

            if ($trim === '') {
                $flush();
            } elseif (preg_match('/^##\s+(.+)$/', $trim, $m)) {
                $flush();
                $blocks[] = ['h' => $m[1]];
            } elseif (preg_match('/^>\s*(.+)$/', $trim, $m)) {
                $flush();
                $blocks[] = ['note' => $m[1]];
            } elseif (preg_match('/^!image:\s*([a-z0-9-]+)\s*(?:\|\s*(.*))?$/', $trim, $m)) {
                $flush();
                $blocks[] = ['image' => $m[1], 'caption' => $m[2] ?? ''];
            } elseif (preg_match('/^stuck:\s*(.+)$/i', $trim, $m)) {
                $flush();
                $blocks[] = ['stuck' => $m[1]];
            } elseif (preg_match('/^\d+\.\s+(.+)$/', $trim, $m)) {
                if ($list === null || $list[0] !== 'ol') {
                    $flush();
                    $list = ['ol', []];
                }
                $list[1][] = $m[1];
            } elseif (preg_match('/^[-•]\s+(.+)$/', $trim, $m)) {
                if ($list === null || $list[0] !== 'ul') {
                    $flush();
                    $list = ['ul', []];
                }
                $list[1][] = $m[1];
            } elseif ($list !== null && preg_match('/^\s+/', $line)) {
                $list[1][array_key_last($list[1])] .= ' '.$trim;
            } else {
                if ($list !== null) {
                    $flush();
                }
                $para[] = $trim;
            }
        }

        $flush();

        return $blocks;
    }
}
