<?php

/*
|--------------------------------------------------------------------------
| On-site ads (Site → Ads)
|--------------------------------------------------------------------------
|
| Starting values only; editable in the admin under Settings → Site → Ads.
|
*/

return [
    // Master switch: off hides every ad on the site at once (the ads themselves are kept).
    'enabled' => (bool) env('ADS_ENABLED', true),

    // Hours to wait before showing the same visitor another pop-up ad.
    'popup_gap_hours' => (int) env('ADS_POPUP_GAP_HOURS', 12),
];
