<?php

return [

    // NFR-13: the signing key comes from the environment and never appears
    // in tracked source. An empty value is a boot-time failure, not a
    // silent fallback to a default key.
    'secret' => env('JWT_SECRET'),

    'ttl' => (int) env('JWT_TTL', 3600),

    'algo' => 'HS256',

];
