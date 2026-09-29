<?php

namespace App\Services\Ai;

/** "Write with AI" for admin text boxes: a first draft, or a rewrite of what is already typed. */
class AiWriter
{
    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @param  string  $purpose  What the text is, e.g. "a short announcement shown to every customer".
     * @param  string  $current  What is already in the box (may be empty).
     * @param  string  $instruction  What the staff member asked for (may be empty).
     * @param  bool  $html  True for rich-text boxes (basic HTML), false for plain text.
     */
    public function write(string $purpose, string $current, string $instruction, bool $html, ?string $context = null): string
    {
        $site = config('app.name');
        $rules = trim((string) config('ai.instructions'));

        $system = "You write text for {$site}, a Nigerian raffle and prize platform, for its admin team.\n"
            ."Write clear, friendly, simple English that ordinary customers understand. Use ₦ for naira.\n"
            ."Use only facts you are given. Never invent prizes, amounts, dates, odds or promises of winning.\n"
            .($html
                ? "Format with simple HTML only (<p>, <h2>, <ul>, <li>, <strong>, <a>). No <script>, no styles, no markdown.\n"
                : "Plain text only: no markdown, no HTML, no quotation marks around the whole text.\n")
            ."Reply with the finished text only, with no introduction or explanation.\n"
            .($rules !== '' ? "House rules from the team: {$rules}\n" : '');

        $prompt = "Write {$purpose}.\n"
            .($context ? "Details: {$context}\n" : '')
            .($instruction !== '' ? "What the team wants: {$instruction}\n" : '')
            .(trim(strip_tags($current)) !== '' ? "The box already contains this (improve or rewrite it, keep its facts):\n{$current}\n" : '');

        return trim($this->gemini->generate('write:'.mb_substr($purpose, 0, 40), $system, $prompt));
    }
}
