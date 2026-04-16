<?php

namespace Nlincs\MarketingCloudLaravel\Testing;

use Nlincs\MarketingCloudLaravel\Subscriber;
use RuntimeException;

class MarketingCloudFake
{
    protected static bool $active = false;

    protected static array $upserts = [];
    protected static array $subscriptions = [];
    protected static array $unsubscriptions = [];

    public static function activate(): void
    {
        static::$active = true;
        static::$upserts = [];
        static::$subscriptions = [];
        static::$unsubscriptions = [];
    }

    public static function isActive(): bool
    {
        return static::$active;
    }

    public static function recordUpsert(string $dataExtension, Subscriber $subscriber): void
    {
        static::$upserts[] = [
            'data_extension' => $dataExtension,
            'subscriber' => $subscriber,
        ];
    }

    public static function recordSubscribe(Subscriber $subscriber): void
    {
        static::$subscriptions[] = $subscriber;
    }

    public static function recordUnsubscribe(Subscriber $subscriber): void
    {
        static::$unsubscriptions[] = $subscriber;
    }

    public static function assertUpserted(string $dataExtension, Subscriber $subscriber): void
    {
        foreach (static::$upserts as $upsert) {
            if (
                $upsert['data_extension'] === $dataExtension &&
                $upsert['subscriber']->subscriberKey() === $subscriber->subscriberKey()
            ) {
                return;
            }
        }

        throw new RuntimeException("Subscriber was not upserted into data extension [{$dataExtension}].");
    }

    public static function assertSubscribed(Subscriber $subscriber): void
    {
        foreach (static::$subscriptions as $s) {
            if ($s->subscriberKey() === $subscriber->subscriberKey()) {
                return;
            }
        }

        throw new RuntimeException('Subscriber was not subscribed.');
    }

    public static function assertUnsubscribed(Subscriber $subscriber): void
    {
        foreach (static::$unsubscriptions as $s) {
            if ($s->subscriberKey() === $subscriber->subscriberKey()) {
                return;
            }
        }

        throw new RuntimeException('Subscriber was not unsubscribed.');
    }
}
