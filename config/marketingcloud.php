<?php

return [
    'org_id' => env('MC_ORG_ID'),
    'client_id' => env('MC_CLIENT_ID'),
    'client_secret' => env('MC_CLIENT_SECRET'),
    'list_id' => env('MC_LIST_ID'),

    'data_extensions' => [
        'newsletter' => [
            'key' => env('MC_NEWSLETTER_DE_KEY'),
            'primary_key' => 'Subscriber Key',
        ],
    ],
];
