<?php

return [

    /*
    | The business's own timezone (OVERHAUL_CHECKLIST.md item 43). A raffle's
    | expiry date means "sales close at the end of that day" here — not in
    | the server's UTC, which would close Nigerian raffles an hour early.
    */
    'timezone' => env('RAFFLES_TIMEZONE', 'Africa/Lagos'),

    /*
    | Ended or sold-out raffles stay on the raffle list (marked closed,
    | after every open one) for this many days after they end, then drop
    | off — their results live on in the Hall of Fame. Keeps the list
    | focused and fast as the number of past raffles grows.
    */
    'list_closed_for_days' => (int) env('RAFFLES_LIST_CLOSED_FOR_DAYS', 14),

];
