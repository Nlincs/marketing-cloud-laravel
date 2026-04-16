# Marketing Cloud Laravel

A lightweight Laravel package for interacting with Salesforce Marketing Cloud.

- Handles authentication and token refresh internally
- Supports Data Extension upserts
- Supports subscribe / unsubscribe
- Works with email-only subscribers or models

## Installation

```bash
composer require nlincs/marketing-cloud-laravel
```

Publish the config:
```bash
php artisan vendor:publish --tag=marketingcloud-config
```

Configure .env:
```bash
MC_ORG_ID=
MC_CLIENT_ID=
MC_CLIENT_SECRET=
MC_LIST_ID=
```

## Configuration

After publishing the config file, declare your Data Extensions in
`config/marketingcloud.php`.

Example:

```php
return [
    'org_id' => env('MC_ORG_ID'),
    'client_id' => env('MC_CLIENT_ID'),
    'client_secret' => env('MC_CLIENT_SECRET'),

    // Used for subscribe / unsubscribe (SOAP API)
    'list_id' => env('MC_LIST_ID'),

    // Data Extensions used for storing subscriber data
    'data_extensions' => [
        'newsletter' => [
            'key' => env('MC_NEWSLETTER_DE_KEY'),
            'primary_key' => 'Subscriber Key',
        ],
    ],
];
```

## Basic Usage (Email-only Subscribers)

This package does not require a User model.  
You can work with email-only subscribers using the `Subscriber` value object.

```php
use Nlincs\MarketingCloudLaravel\Subscriber;
use Nlincs\MarketingCloudLaravel\MarketingCloudService;

$subscriber = Subscriber::fromEmail('test@example.com');

$mc = app(MarketingCloudService::class);

// Store subscriber data
$mc->dataExtension('newsletter')->upsert($subscriber);

// Subscribe the user
$mc->subscribe($subscriber);
```

## Data Extensions vs Subscriptions

- **Data Extensions** are used to store subscriber data (REST API).
- **Subscribe / Unsubscribe** actions are performed against a Marketing Cloud List (SOAP API).

These are configured separately and serve different purposes.

## Testing

The package includes a built-in fake to allow testing without making HTTP
requests to Marketing Cloud.

```php
use Nlincs\MarketingCloudLaravel\Testing\MarketingCloudFake;
use Nlincs\MarketingCloudLaravel\Subscriber;

MarketingCloudFake::activate();

$subscriber = Subscriber::fromEmail('test@example.com');

$mc->dataExtension('newsletter')->upsert($subscriber);
$mc->subscribe($subscriber);

// Assertions
MarketingCloudFake::assertUpserted('newsletter', $subscriber);
MarketingCloudFake::assertSubscribed($subscriber);
```
