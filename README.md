# Marketing Cloud Laravel

A lightweight Laravel package for interacting with Salesforce Marketing Cloud.

- Handles authentication, token caching and refresh internally
- Supports Data Extension upserts
- Supports subscribe / unsubscribe
- Works with email-only subscribers or your own subscriber keys

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
# Tenant subdomain, e.g. mc0sh2543... from https://mc0sh2543....auth.marketingcloudapis.com
MC_ORG_ID=
MC_CLIENT_ID=
MC_CLIENT_SECRET=
# Only needed for subscribe / unsubscribe
MC_LIST_ID=
```

`MC_ORG_ID` is your tenant-specific subdomain (shown on the API Integration
component of your Installed Package), not the account MID.

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

## Custom Subscriber Keys

If your Data Extension or subscriber list uses something other than the email
address as the key, build the subscriber with `fromKey()`. Include the
`Email address` attribute when subscribing so Marketing Cloud knows where to
send:

```php
$subscriber = Subscriber::fromKey($user->subscriber_key, [
    'Email address' => $user->email,
    'Name' => $user->name,
]);
```

Attributes are sent as Data Extension column values, so their names must
match your Data Extension's columns.

## Error Handling

Failures throw rather than failing silently:

- A missing config value throws a `RuntimeException` naming the key.
- Authentication failures throw a `RuntimeException` including the HTTP status
  and response body.
- Expired tokens are refreshed and the request retried once automatically.
- HTTP errors throw `Illuminate\Http\Client\RequestException`.
- SOAP requests that return HTTP 200 with a non-`OK` status throw a
  `MarketingCloudRejectedException` (a `RuntimeException`) with Marketing
  Cloud's status message, e.g. `TriggeredSpamFilter` for a fake address like
  `test@test.com`.

When calling Marketing Cloud from a request (e.g. a sign-up form), catch these
so the user sees a friendly error. From a queued job, let them throw so the
job is retried or recorded in `failed_jobs`, but fail the job straight away on
a `MarketingCloudRejectedException`, since retrying the same data will be
rejected again. Always set `$tries` on the job too:

```php
public $tries = 3;

public $backoff = [60, 300];

public function handle(MarketingCloudService $marketingCloud): void
{
    try {
        $marketingCloud->subscribe($subscriber);
    } catch (MarketingCloudRejectedException $e) {
        $this->fail($e);
    }
}
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
$mc = app(MarketingCloudService::class);

$mc->dataExtension('newsletter')->upsert($subscriber);
$mc->subscribe($subscriber);

// Assertions
MarketingCloudFake::assertUpserted('newsletter', $subscriber);
MarketingCloudFake::assertSubscribed($subscriber);
MarketingCloudFake::assertUnsubscribed($subscriber);
MarketingCloudFake::assertNothingSent();
```

The fake's state is stored in the container, so it resets automatically for
each test. Call `MarketingCloudFake::deactivate()` to turn it off mid-test.

## Development

The package is tested with Orchestra Testbench, so no host Laravel app is
needed:

```bash
composer install
composer test
```
