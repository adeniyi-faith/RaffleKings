<?php

/*
|--------------------------------------------------------------------------
| Public status page (/status) and status banner
|--------------------------------------------------------------------------
|
| Switched on in Settings → On / off → New features; the message is set in
| Settings → Backups & status. The page also shows, live, which parts of
| the site are paused (Settings → On / off) and any maintenance.
|
*/

return [
    // ok | info | degraded | outage
    'level' => 'ok',
    'message' => null,
    // Also show the message as a thin banner on every page (not for "ok").
    'banner' => true,
];
