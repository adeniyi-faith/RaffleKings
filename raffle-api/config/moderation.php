<?php

/*
|--------------------------------------------------------------------------
| Live-draw chat moderation (OVERHAUL_CHECKLIST.md item 45)
|--------------------------------------------------------------------------
|
| Applied to every chat message before it's saved and shown.
|
| Links and phone numbers are removed because on a prize site they are the
| classic scam: "you won, WhatsApp me on 080... to claim". Real winners are
| only ever contacted by RaffleKings itself.
|
*/

return [

    'remove_links' => true,

    'remove_phone_numbers' => true,

    // Whole words replaced with asterisks (case-insensitive). Extend as needed.
    'blocked_words' => array_filter(explode(',', (string) env('CHAT_BLOCKED_WORDS', 'scam,fraud,419,yahoo boy,mumu,idiot,stupid,fool,bastard,ashawo,olodo'))),

];
