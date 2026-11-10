<?php

return [

    // Cache store for responses and locks, null means the default one.
    // It has to support locks: redis, database, file, memcached or array.
    'store' => env('IDEMPOTENCY_STORE'),

    'header' => 'Idempotency-Key',

    'methods' => ['POST', 'PATCH'],

    // How long a response is kept for replay, in seconds.
    'ttl' => 86400,

    // How long a key stays locked while the first request is running.
    'lock_seconds' => 30,

    // Reject requests without the header. Can be enabled per route: 'idempotent:required'.
    'required' => false,

];
