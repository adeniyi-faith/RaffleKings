<?php

/*
|--------------------------------------------------------------------------
| AI helpers (Gemini)
|--------------------------------------------------------------------------
|
| Starting values only; all editable in the admin under Settings → AI.
| The Gemini key itself is `services.gemini.api_key` (same key that reads
| bank statements), and the model used for writing and replying is
| `services.gemini.assistant_model`.
|
*/

return [
    // Master switch: the "Write with AI" buttons and the support agent.
    'enabled' => (bool) env('AI_ENABLED', true),

    // Let the AI answer support tickets by itself (only when the knowledge base has the answer).
    'auto_reply' => (bool) env('AI_AUTO_REPLY', false),

    // Most AI calls per day, so a bug or a flood can't run up a bill.
    'daily_limit' => (int) env('AI_DAILY_LIMIT', 300),

    // After this many automated replies on one ticket, a person takes over.
    'max_auto_replies' => (int) env('AI_MAX_AUTO_REPLIES', 3),

    // Extra house rules added to every AI prompt (tone, things to avoid).
    'instructions' => env('AI_INSTRUCTIONS', ''),

    // Raffle advisor: write a fresh report by itself once a week (Monday morning).
    'advisor_weekly' => (bool) env('AI_ADVISOR_WEEKLY', true),
];
