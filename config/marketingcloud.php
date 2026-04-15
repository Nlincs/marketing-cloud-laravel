<?php

return [

    'org_id' => env('MC_ORG_ID'),

    'client_id' => env('MC_CLIENT_ID'),

    'client_secret' => env('MC_CLIENT_SECRET'),

    /*
     * Default list for subscribe / unsubscribe
     */
    'list_id' => env('MC_LIST_ID'),

    /*
     * Data Extensions
     */
    'data_extensions' => [
        // Example:
        // 'newsletter' => [
        //     'key' => env('MC_NEWSLETTER_DE_KEY'),
        //     'primary_key' => 'Subscriber Key',
        // ],
    ],

];