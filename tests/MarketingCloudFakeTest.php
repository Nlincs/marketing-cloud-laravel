<?php

namespace Nlincs\MarketingCloudLaravel\Tests;

use Illuminate\Support\Facades\Http;
use Nlincs\MarketingCloudLaravel\MarketingCloudService;
use Nlincs\MarketingCloudLaravel\Subscriber;
use Nlincs\MarketingCloudLaravel\Testing\MarketingCloudFake;
use PHPUnit\Framework\ExpectationFailedException;

class MarketingCloudFakeTest extends TestCase
{
    public function test_records_calls_without_sending_requests(): void
    {
        Http::fake();
        MarketingCloudFake::activate();

        $subscriber = Subscriber::fromEmail('test@example.com');
        $mc = app(MarketingCloudService::class);

        $mc->dataExtension('newsletter')->upsert($subscriber);
        $mc->subscribe($subscriber);
        $mc->unsubscribe($subscriber);

        MarketingCloudFake::assertUpserted('newsletter', $subscriber);
        MarketingCloudFake::assertSubscribed($subscriber);
        MarketingCloudFake::assertUnsubscribed($subscriber);
        Http::assertNothingSent();
    }

    public function test_failed_assertion_is_reported_as_a_test_failure(): void
    {
        MarketingCloudFake::activate();

        $this->expectException(ExpectationFailedException::class);

        MarketingCloudFake::assertSubscribed(Subscriber::fromEmail('test@example.com'));
    }

    public function test_assert_nothing_sent(): void
    {
        MarketingCloudFake::activate();

        MarketingCloudFake::assertNothingSent();

        MarketingCloudFake::recordSubscribe(Subscriber::fromEmail('test@example.com'));

        $this->expectException(ExpectationFailedException::class);

        MarketingCloudFake::assertNothingSent();
    }

    public function test_fake_does_not_leak_into_the_next_test(): void
    {
        // Runs after the tests above activated the fake.
        $this->assertFalse(MarketingCloudFake::isActive());
    }

    public function test_deactivate(): void
    {
        MarketingCloudFake::activate();
        MarketingCloudFake::deactivate();

        $this->assertFalse(MarketingCloudFake::isActive());
    }
}
