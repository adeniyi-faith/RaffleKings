<?php

namespace App\Services\Retention;

use App\Services\Ai\GeminiClient;
use Throwable;

/**
 * Writes the words of a comeback offer: short, warm and urgent, in the
 * style of shopping apps like Temu ("It's been a while! Here's ₦500 to
 * play the ₦500,000 Raffle Draw. Claim it in 24 hours ⏳").
 *
 * With AI switched on (Settings → AI, and "let Gemini write the messages"
 * in Settings → Reminders → Comeback offers), Gemini writes one fresh
 * message per kind of offer and segment each day, with blanks like
 * {amount} that are filled in for each customer. Only those blanks reach
 * Gemini, never a customer's name or details. Every headline opens with
 * the customer's full name ({name}), or their username if no name is saved. If Gemini is off, busy or
 * writes something unusable, a built-in message is used instead, so an
 * offer is never held up.
 */
class OfferWriter
{
    /** The blanks a message may use. */
    public const BLANKS = ['{name}', '{amount}', '{raffle}', '{prize}', '{hours}'];

    /** Built-in messages: [headline, body]. Several of each so not everyone gets the same words. */
    public const TEMPLATES = [
        'first_ticket' => [
            ['🎉 Your first ticket is on us, {name}', 'Here\'s {amount} to try the {raffle} for free. You could win {prize}! This gift disappears in {hours} hours ⏳'],
            ['🎟️ {name}, we saved you a free ticket', 'Claim {amount} and play the {raffle} on us, with {prize} up for grabs. Only {hours} hours to claim it!'],
        ],
        'raffle_ticket' => [
            ['🎁 {name}, your next ticket is on us', 'It\'s been a while! Here\'s {amount} to play the {raffle} for a shot at {prize}. Claim it in the next {hours} hours before it\'s gone ⏳'],
            ['🎟️ {name}, this free ticket has your name on it', 'We kept {amount} aside for you to play the {raffle}. One ticket, one real chance at {prize}. Only {hours} hours left to claim!'],
            ['⚡ Don\'t miss this one, {name}', '{prize} is up for grabs in the {raffle}, and your ticket is on us: {amount}, yours for the next {hours} hours.'],
        ],
        'credit' => [
            ['💸 {amount} just landed for you, {name}', 'We miss you! Here\'s {amount} of ticket credit to use on any raffle, like the {raffle} for {prize}. Claim it within {hours} hours or it\'s gone ⏰'],
            ['🎁 {name}, a gift from us: {amount}', 'It\'s been a while! Claim {amount} of ticket credit and try your luck on the {raffle}. Hurry, it expires in {hours} hours!'],
        ],
        'credit_any' => [
            ['💸 {amount} just landed for you, {name}', 'We miss you! Here\'s {amount} of ticket credit to use on any raffle. Claim it within {hours} hours or it\'s gone ⏰'],
        ],
        'points' => [
            ['⭐ {amount} are waiting for you, {name}', 'Come back and grab {amount} on us. The {raffle} is live now with {prize} to win. Claim within {hours} hours ⏳'],
            ['🔥 {name}, free {amount} just for you', 'Your bonus {amount} expire in {hours} hours. Tap to claim them, then check out the {raffle}!'],
        ],
        'points_any' => [
            ['⭐ {amount} are waiting for you, {name}', 'Come back and grab {amount} on us. Claim within {hours} hours before they disappear ⏳'],
        ],
        'last_call' => [
            ['⏳ Only {hours} hours left, {name}!', 'Your {amount} is about to expire. Tap to claim it before it\'s gone.'],
            ['⏰ {name}, last call: {amount} expires soon', 'You still haven\'t claimed your {amount}. It disappears in {hours} hours.'],
        ],
    ];

    /** @var array<string, array{0: string, 1: string, 2: string}> what the AI wrote today, per kind and segment */
    private array $written = [];

    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * The words for one offer, with the blanks filled in.
     *
     * @param  string  $style  a key of TEMPLATES
     * @param  array{name: string, amount: string, raffle?: ?string, prize?: ?string, hours: int|string}  $facts
     * @return array{headline: string, body: string, written_by: string}
     */
    public function write(string $style, string $segment, array $facts, int $variant = 0): array
    {
        [$headline, $body, $by] = $this->pattern($style, $segment, $variant);

        return [
            'headline' => mb_substr(self::fill($headline, $facts), 0, 160),
            'body' => self::fill($body, $facts),
            'written_by' => $by,
        ];
    }

    /** @return array{0: string, 1: string, 2: string} headline, body, who wrote it */
    private function pattern(string $style, string $segment, int $variant): array
    {
        $key = $style.':'.$segment;

        if (! array_key_exists($key, $this->written)) {
            $this->written[$key] = $this->askAi($style, $segment);
        }

        if ($this->written[$key] !== null) {
            return $this->written[$key];
        }

        $options = self::TEMPLATES[$style];
        [$headline, $body] = $options[$variant % count($options)];

        return [$headline, $body, 'template'];
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function askAi(string $style, string $segment): ?array
    {
        if (! config('retention.offers.use_ai') || ! $this->gemini->available() || $style === 'last_call') {
            return null;
        }

        $example = self::TEMPLATES[$style][0];
        $who = MemberSegments::SEGMENTS[$segment][1] ?? 'a customer';
        $offer = match ($style) {
            'first_ticket' => 'ticket credit worth exactly one ticket for {raffle}, for someone who has never bought a ticket',
            'raffle_ticket' => 'ticket credit worth exactly one ticket for {raffle}',
            'credit', 'credit_any' => 'ticket credit to spend on any raffle',
            default => 'free bonus points',
        };
        $blanks = str_ends_with($style, '_any') ? '{name}, {amount}, {hours}' : '{name}, {amount}, {raffle}, {prize}, {hours}';

        $system = 'You write short phone notifications for '.config('app.name').", a Nigerian raffle site, in the style of Temu's notifications: warm, personal, exciting, urgent, with one or two emoji.\n"
            ."Plain text only, no markdown, no quotation marks. Simple English. Never promise or suggest that anyone will win; say they could win.\n"
            ."Use these blanks exactly as written, and no others: {$blanks}. {amount} already includes the ₦ sign or the word points.\n"
            .'Start the headline with {name} (the customer\'s full name), like a friend would: "{name}, we miss you!". '
            .'The headline is at most 60 characters. The body is at most 200 characters and must say the offer runs out in {hours} hours.';

        $prompt = "Write one notification offering {$offer}.\nThe customer: {$who}\nExample of the tone (write something new):\nHeadline: {$example[0]}\nBody: {$example[1]}";

        try {
            $json = json_decode($this->gemini->generate('retention:offer', $system, $prompt, schema: [
                'type' => 'object',
                'properties' => ['headline' => ['type' => 'string'], 'body' => ['type' => 'string']],
                'required' => ['headline', 'body'],
            ], maxTokens: 512), true);
        } catch (Throwable $e) {
            return null;
        }

        $headline = trim((string) ($json['headline'] ?? ''));
        $body = trim((string) ($json['body'] ?? ''));

        return self::usable($headline, $body, $style) ? [$headline, $body, 'ai'] : null;
    }

    /** The AI's words are used only if they have the amount, the deadline, no unknown blanks and sensible lengths. */
    public static function usable(string $headline, string $body, string $style): bool
    {
        $text = $headline.' '.$body;
        preg_match_all('/\{[a-z_]+\}/', $text, $found);
        $allowed = str_ends_with($style, '_any') ? ['{name}', '{amount}', '{hours}'] : self::BLANKS;

        return $headline !== '' && $body !== ''
            && mb_strlen($headline) <= 90 && mb_strlen($body) <= 320
            && str_contains($headline, '{name}') && str_contains($text, '{amount}') && str_contains($body, '{hours}')
            && array_diff($found[0], $allowed) === []
            && ! preg_match('/(guarantee|sure win|will win|100%)/i', $text);
    }

    /** @param  array<string, mixed>  $facts */
    public static function fill(string $text, array $facts): string
    {
        $filled = str_replace(
            self::BLANKS,
            [
                (string) ($facts['name'] ?? 'there'),
                (string) ($facts['amount'] ?? ''),
                (string) ($facts['raffle'] ?? 'our raffles'),
                (string) ($facts['prize'] ?? 'great prizes'),
                (string) ($facts['hours'] ?? ''),
            ],
            $text,
        );

        return trim(preg_replace('/\{[a-z_]+\}/', '', $filled));
    }
}
