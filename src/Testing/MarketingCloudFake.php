<?php

namespace Nlincs\MarketingCloudLaravel\Testing;

use Nlincs\MarketingCloudLaravel\Subscriber;
use PHPUnit\Framework\Assert;

/**
 * Records Marketing Cloud calls instead of sending them.
 *
 * State lives in the container, so it resets automatically with each test's
 * fresh application instead of leaking into later tests.
 */
class MarketingCloudFake
{
    protected array $upserts = [];
    protected array $subscriptions = [];
    protected array $unsubscriptions = [];

    public static function activate(): void
    {
        app()->instance(static::class, new static);
    }

    public static function deactivate(): void
    {
        app()->forgetInstance(static::class);
    }

    public static function isActive(): bool
    {
        return app()->bound(static::class);
    }

    protected static function instance(): static
    {
        return app(static::class);
    }

    public static function recordUpsert(string $dataExtension, Subscriber $subscriber): void
    {
        static::instance()->upserts[] = [
            'data_extension' => $dataExtension,
            'subscriber' => $subscriber,
        ];
    }

    public static function recordSubscribe(Subscriber $subscriber): void
    {
        static::instance()->subscriptions[] = $subscriber;
    }

    public static function recordUnsubscribe(Subscriber $subscriber): void
    {
        static::instance()->unsubscriptions[] = $subscriber;
    }

    public static function assertUpserted(string $dataExtension, Subscriber $subscriber): void
    {
        $found = collect(static::instance()->upserts)->contains(
            fn ($upsert) => $upsert['data_extension'] === $dataExtension
                && $upsert['subscriber']->subscriberKey() === $subscriber->subscriberKey()
        );

        Assert::assertTrue($found, "Subscriber was not upserted into data extension [{$dataExtension}].");
    }

    public static function assertSubscribed(Subscriber $subscriber): void
    {
        Assert::assertTrue(
            static::contains(static::instance()->subscriptions, $subscriber),
            'Subscriber was not subscribed.'
        );
    }

    public static function assertUnsubscribed(Subscriber $subscriber): void
    {
        Assert::assertTrue(
            static::contains(static::instance()->unsubscriptions, $subscriber),
            'Subscriber was not unsubscribed.'
        );
    }

    public static function assertNothingSent(): void
    {
        $fake = static::instance();

        Assert::assertEmpty(
            [...$fake->upserts, ...$fake->subscriptions, ...$fake->unsubscriptions],
            'Unexpected Marketing Cloud calls were recorded.'
        );
    }

    protected static function contains(array $subscribers, Subscriber $subscriber): bool
    {
        return collect($subscribers)->contains(
            fn ($s) => $s->subscriberKey() === $subscriber->subscriberKey()
        );
    }
}
