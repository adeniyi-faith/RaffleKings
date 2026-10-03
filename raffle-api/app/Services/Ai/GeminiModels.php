<?php

namespace App\Services\Ai;

/**
 * The Gemini models offered in Settings → AI, for both the payment
 * screenshot reader and the AI assistant. Names are Google's own model
 * codes (ai.google.dev/gemini-api/docs/models), stable versions only.
 */
final class GeminiModels
{
    public const DEFAULT = 'gemini-3.8-flash';

    public const OPTIONS = [
        'gemini-3.8-flash' => 'Gemini 3.8 Flash (recommended, newest)',
        'gemini-3.7-flash' => 'Gemini 3.7 Flash',
        'gemini-3.6-flash' => 'Gemini 3.6 Flash',
        'gemini-3.5-flash' => 'Gemini 3.5 Flash',
        'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite (cheapest, fastest)',
        'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash-Lite',
    ];
}
