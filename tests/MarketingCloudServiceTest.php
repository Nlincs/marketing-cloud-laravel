<?php

namespace Nlincs\MarketingCloudLaravel\Tests;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nlincs\MarketingCloudLaravel\MarketingCloudService;
use Nlincs\MarketingCloudLaravel\Subscriber;
use RuntimeException;

class MarketingCloudServiceTest extends TestCase
{
    protected function service(): MarketingCloudService
    {
        return app(MarketingCloudService::class);
    }

    protected function tokenResponse(string $token = 'token', int $expiresIn = 1200)
    {
        return Http::response(['access_token' => $token, 'expires_in' => $expiresIn]);
    }

    protected function tokenRequests(): int
    {
        return Http::recorded(fn ($request) => str_ends_with($request->url(), '/v2/token'))->count();
    }

    public function test_upsert_posts_row_to_data_extension(): void
    {
        Http::fake([
            '*/v2/token' => $this->tokenResponse(),
            '*/rowset' => Http::response([]),
        ]);

        $this->service()->dataExtension('newsletter')->upsert(
            Subscriber::fromKey('key-1', ['Name' => 'Test'])
        );

        Http::assertSent(fn ($request) => $request->url() === 'https://test-org.rest.marketingcloudapis.com/hub/v1/dataevents/key:DE-KEY/rowset'
            && $request->header('Authorization') === ['Bearer token']
            && $request[0]['keys'] === ['Subscriber Key' => 'key-1']
            && $request[0]['values'] === ['Name' => 'Test', 'Subscriber Key' => 'key-1']);
    }

    public function test_subscribe_sends_soap_update_with_active_status(): void
    {
        Http::fake([
            '*/v2/token' => $this->tokenResponse(),
            '*/Service.asmx' => Http::response('<ok/>'),
        ]);

        $this->service()->subscribe(Subscriber::fromEmail('Test@Example.com'));

        Http::assertSent(fn ($request) => $request->url() === 'https://test-org.soap.marketingcloudapis.com/Service.asmx'
            && $request->header('SOAPAction') === ['Update']
            && str_contains($request->body(), '<fueloauth xmlns="http://exacttarget.com">token</fueloauth>')
            && str_contains($request->body(), '<SubscriberKey>test@example.com</SubscriberKey>')
            && str_contains($request->body(), '<EmailAddress>Test@Example.com</EmailAddress>')
            && str_contains($request->body(), '<ID>1130</ID>')
            && str_contains($request->body(), '<Status>Active</Status>'));
    }

    public function test_unsubscribe_sends_unsubscribed_status(): void
    {
        Http::fake([
            '*/v2/token' => $this->tokenResponse(),
            '*/Service.asmx' => Http::response('<ok/>'),
        ]);

        $this->service()->unsubscribe(Subscriber::fromEmail('test@example.com'));

        Http::assertSent(fn ($request) => str_contains($request->body(), '<Status>Unsubscribed</Status>'));
    }

    public function test_token_is_cached_between_requests(): void
    {
        Http::fake([
            '*/v2/token' => $this->tokenResponse(),
            '*/Service.asmx' => Http::response('<ok/>'),
        ]);

        $this->service()->subscribe(Subscriber::fromEmail('a@example.com'));
        $this->service()->subscribe(Subscriber::fromEmail('b@example.com'));

        $this->assertSame(1, $this->tokenRequests());
    }

    public function test_token_cache_honours_expires_in(): void
    {
        Http::fake([
            '*/v2/token' => Http::sequence()
                ->push(['access_token' => 'first', 'expires_in' => 1200])
                ->push(['access_token' => 'second', 'expires_in' => 1200]),
            '*/Service.asmx' => Http::response('<ok/>'),
        ]);

        $this->service()->subscribe(Subscriber::fromEmail('a@example.com'));

        // 19 minutes later the token is within a minute of expiring, so it's refreshed.
        $this->travel(19)->minutes();

        $this->service()->subscribe(Subscriber::fromEmail('b@example.com'));

        $this->assertSame(2, $this->tokenRequests());
        Http::assertSent(fn ($request) => str_contains($request->body(), '>second</fueloauth>'));
    }

    public function test_soap_request_retries_with_fresh_token_after_login_failure(): void
    {
        Cache::put('marketing_cloud:token:client-id', 'expired', now()->addHour());

        Http::fake([
            '*/v2/token' => $this->tokenResponse('fresh'),
            '*/Service.asmx' => Http::sequence()
                ->push('<faultstring>Login Failed</faultstring>', 500)
                ->push('<ok/>'),
        ]);

        $this->service()->subscribe(Subscriber::fromEmail('test@example.com'));

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_contains($request->body(), '>fresh</fueloauth>'));
    }

    public function test_soap_request_throws_when_retry_also_fails(): void
    {
        Http::fake([
            '*/v2/token' => $this->tokenResponse(),
            '*/Service.asmx' => Http::response('<faultstring>Server Error</faultstring>', 500),
        ]);

        $this->expectException(RequestException::class);

        $this->service()->subscribe(Subscriber::fromEmail('test@example.com'));
    }

    public function test_rest_request_retries_with_fresh_token_after_401(): void
    {
        Cache::put('marketing_cloud:token:client-id', 'expired', now()->addHour());

        Http::fake([
            '*/v2/token' => $this->tokenResponse('fresh'),
            '*/rowset' => Http::sequence()
                ->push(['message' => 'Not Authorized'], 401)
                ->push([]),
        ]);

        $this->service()->dataExtension('newsletter')->upsert(Subscriber::fromKey('key-1'));

        Http::assertSent(fn ($request) => $request->header('Authorization') === ['Bearer fresh']);
    }

    public function test_failed_authentication_throws_with_response_details(): void
    {
        Http::fake([
            '*/v2/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 401');

        $this->service()->subscribe(Subscriber::fromEmail('test@example.com'));
    }

    public function test_successful_response_without_token_throws(): void
    {
        Http::fake([
            '*/v2/token' => Http::response([]),
        ]);

        $this->expectException(RuntimeException::class);

        $this->service()->subscribe(Subscriber::fromEmail('test@example.com'));
    }

    public function test_unknown_data_extension_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown Data Extension [missing]');

        $this->service()->dataExtension('missing');
    }

    public function test_soap_error_returned_with_http_200_throws(): void
    {
        Http::fake([
            '*/v2/token' => $this->tokenResponse(),
            '*/Service.asmx' => Http::response(
                '<UpdateResponse><Results><StatusCode>Error</StatusCode></Results>'
                .'<OverallStatus>Error</OverallStatus><StatusMessage>List &amp; ID invalid</StatusMessage></UpdateResponse>'
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Marketing Cloud SOAP Update failed (Error): List & ID invalid');

        $this->service()->subscribe(Subscriber::fromEmail('test@example.com'));
    }

    public function test_soap_ok_status_does_not_throw(): void
    {
        Http::fake([
            '*/v2/token' => $this->tokenResponse(),
            '*/Service.asmx' => Http::response('<UpdateResponse><OverallStatus>OK</OverallStatus></UpdateResponse>'),
        ]);

        $this->service()->subscribe(Subscriber::fromEmail('test@example.com'));

        Http::assertSentCount(2);
    }

    public function test_soap_values_are_xml_escaped(): void
    {
        Http::fake([
            '*/v2/token' => $this->tokenResponse(),
            '*/Service.asmx' => Http::response('<ok/>'),
        ]);

        $this->service()->subscribe(Subscriber::fromKey('a<b>', ['Email address' => "o'brien&co@example.com"]));

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/Service.asmx')) {
                return false;
            }

            return simplexml_load_string($request->body()) !== false
                && str_contains($request->body(), '<SubscriberKey>a&lt;b&gt;</SubscriberKey>')
                && str_contains($request->body(), '<EmailAddress>o&apos;brien&amp;co@example.com</EmailAddress>');
        });
    }

    public function test_missing_config_throws_a_clear_error(): void
    {
        config(['marketingcloud.client_secret' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Marketing Cloud config [marketingcloud.client_secret] is not set.');

        $this->service()->subscribe(Subscriber::fromEmail('test@example.com'));
    }

    public function test_missing_list_id_only_matters_for_subscriptions(): void
    {
        config(['marketingcloud.list_id' => null]);

        Http::fake([
            '*/v2/token' => $this->tokenResponse(),
            '*/rowset' => Http::response([]),
        ]);

        $this->service()->dataExtension('newsletter')->upsert(Subscriber::fromKey('key-1'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[marketingcloud.list_id]');

        $this->service()->subscribe(Subscriber::fromEmail('test@example.com'));
    }

    public function test_data_extension_without_key_throws(): void
    {
        config(['marketingcloud.data_extensions.newsletter.key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[marketingcloud.data_extensions.newsletter] needs a key');

        $this->service()->dataExtension('newsletter')->upsert(Subscriber::fromKey('key-1'));
    }
}
