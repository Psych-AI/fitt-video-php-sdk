<?php

return [

    /*
    | The platform's origin. The client adds `/api/v1` itself.
    */
    'url' => env('FITT_VIDEO_URL', 'https://video.fitt.ai'),

    /*
    | The space's API key, `fvk_<id>.<secret>`. Keep it on the server.
    */
    'key' => env('FITT_VIDEO_KEY'),

    /*
    | The key's webhook secret, comma separated. Give two while a rotation
    | overlaps, so a delivery signed with either verifies.
    */
    'webhook_secrets' => array_values(array_filter(array_map('trim', explode(',', (string) env('FITT_VIDEO_WEBHOOK_SECRETS', ''))))),

    /*
    | How far a delivery's X-Fitt-Timestamp may be from now, either way, in seconds.
    */
    'webhook_tolerance_s' => 300,

];
