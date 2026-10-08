<?php

namespace Nlincs\MarketingCloudLaravel;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nlincs\MarketingCloudLaravel\Testing\MarketingCloudFake;
use RuntimeException;

class MarketingCloudService
{
    protected ?string $orgId;
    protected ?string $clientId;
    protected ?string $clientSecret;
    protected ?string $listId;

    public function __construct()
    {
        $this->orgId        = config('marketingcloud.org_id');
        $this->clientId     = config('marketingcloud.client_id');
        $this->clientSecret = config('marketingcloud.client_secret');
        $this->listId       = config('marketingcloud.list_id');
    }

    public function orgId(): string
    {
        return $this->required('org_id', $this->orgId);
    }

    protected function required(string $key, ?string $value): string
    {
        if (blank($value)) {
            throw new RuntimeException("Marketing Cloud config [marketingcloud.{$key}] is not set.");
        }

        return $value;
    }

    protected function getAccessToken(): string
    {
        $cacheKey = $this->tokenCacheKey();

        if ($token = Cache::get($cacheKey)) {
            return $token;
        }

        $response = $this->requestAccessToken();

        // Tokens last 20 minutes; refresh a minute early so a cached token
        // never expires mid-request.
        $ttl = max((int) $response['expires_in'] - 60, 60);

        Cache::put($cacheKey, $response['access_token'], now()->addSeconds($ttl));

        return $response['access_token'];
    }

    protected function requestAccessToken(): array
    {
        $response = Http::post(
            "https://{$this->orgId()}.auth.marketingcloudapis.com/v2/token",
            [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->required('client_id', $this->clientId),
                'client_secret' => $this->required('client_secret', $this->clientSecret),
            ]
        );

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException(
                "Failed to obtain Marketing Cloud token (HTTP {$response->status()}): {$response->body()}"
            );
        }

        return [
            'access_token' => $response->json('access_token'),
            'expires_in'   => $response->json('expires_in', 1200),
        ];
    }

    public function subscribe(Subscriber $subscriber): void
    {
        if (MarketingCloudFake::isActive()) {
            MarketingCloudFake::recordSubscribe($subscriber);
            return;
        }

        $this->updateSubscriptionStatus($subscriber, 'Active');
    }

    public function unsubscribe(Subscriber $subscriber): void
    {
        if (MarketingCloudFake::isActive()) {
            MarketingCloudFake::recordUnsubscribe($subscriber);
            return;
        }

        $this->updateSubscriptionStatus($subscriber, 'Unsubscribed');
    }

    protected function updateSubscriptionStatus(Subscriber $subscriber, string $status): void {
        $email = $subscriber->email() ?? $subscriber->subscriberKey();

        if (! $email) {
            throw new RuntimeException('Email address required for subscription status updates');
        }

        $listId = $this->required('list_id', $this->listId);
        $subscriberKey = $this->xml($subscriber->subscriberKey());
        $email = $this->xml($email);

        $this->soapRequest('Update', fn (string $token) => <<<XML
            <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
              <soap:Header>
                <fueloauth xmlns="http://exacttarget.com">{$token}</fueloauth>
              </soap:Header>
              <soap:Body>
                <UpdateRequest xmlns="http://exacttarget.com/wsdl/partnerAPI">
                 <Options>
                    <SaveOptions>
                       <SaveOption>
                          <PropertyName>*</PropertyName>
                          <SaveAction>UpdateAdd</SaveAction>
                       </SaveOption>
                    </SaveOptions>
                 </Options>
                  <Objects xsi:type="Subscriber">
                    <SubscriberKey>{$subscriberKey}</SubscriberKey>
                    <EmailAddress>{$email}</EmailAddress>
                    <Lists>
                      <ID>{$this->xml($listId)}</ID>
                      <Status>{$status}</Status>
                    </Lists>
                  </Objects>
                </UpdateRequest>
              </soap:Body>
            </soap:Envelope>
        XML);
    }

    public function dataExtension(string $name): DataExtension
    {
        $definition = config("marketingcloud.data_extensions.{$name}");

        if (! $definition) {
            throw new RuntimeException("Unknown Data Extension [{$name}]");
        }

        return new DataExtension(
            service: $this,
            name: $name,
            definition: $definition
        );
    }

    public function restRequest(callable $callback): Response
    {
        $token = $this->getAccessToken();

        $response = $callback($token);

        if ($response->status() === 401) {
            $this->forgetAccessToken();
            $token = $this->getAccessToken();
            $response = $callback($token);
        }

        return $response->throw();
    }

    protected function soapRequest(string $action, callable $envelope): Response
    {
        $send = fn (string $token) => Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction'   => $action,
        ])
            ->withBody($envelope($token), 'text/xml')
            ->post("https://{$this->orgId()}.soap.marketingcloudapis.com/Service.asmx");

        $response = $send($this->getAccessToken());

        // An expired or revoked token comes back as a 500 "Login Failed" fault.
        if ($response->failed()) {
            $this->forgetAccessToken();
            $response = $send($this->getAccessToken());
        }

        $response->throw();

        // Request-level errors (e.g. an invalid list ID) still come back as HTTP 200.
        $status = $this->soapValue($response->body(), 'OverallStatus');

        if ($status !== null && $status !== 'OK') {
            $message = $this->soapValue($response->body(), 'StatusMessage') ?? 'no status message';

            throw new RuntimeException("Marketing Cloud SOAP {$action} failed ({$status}): {$message}");
        }

        return $response;
    }

    protected function soapValue(string $body, string $element): ?string
    {
        if (! preg_match("/<{$element}>(.*?)<\/{$element}>/s", $body, $matches)) {
            return null;
        }

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1);
    }

    protected function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected function tokenCacheKey(): string
    {
        return 'marketing_cloud:token:' . $this->required('client_id', $this->clientId);
    }

    protected function forgetAccessToken(): void
    {
        Cache::forget($this->tokenCacheKey());
    }
}
