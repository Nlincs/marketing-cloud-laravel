<?php

namespace Nlincs\MarketingCloudLaravel;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nlincs\MarketingCloudLaravel\Testing\MarketingCloudFake;
use RuntimeException;

class MarketingCloudService
{
    protected string $orgId;
    protected string $clientId;
    protected string $clientSecret;
    protected string $listId;

    public function __construct()
    {
        $this->orgId        = config('marketingcloud.org_id');
        $this->clientId     = config('marketingcloud.client_id');
        $this->clientSecret = config('marketingcloud.client_secret');
        $this->listId       = config('marketingcloud.list_id');
    }

    public function orgId(): string
    {
        return $this->orgId;
    }

    protected function getAccessToken(): string
    {
        $cacheKey = 'marketing_cloud:token:' . $this->clientId;

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(3500),
            fn () => $this->requestAccessToken()
        );
    }

    protected function requestAccessToken(): string
    {
        $response = Http::post(
            "https://{$this->orgId}.auth.marketingcloudapis.com/v2/token",
            [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException('Failed to obtain Marketing Cloud token');
        }

        return $response->json('access_token');
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
        $email = $subscriber->email();

        if (! $email) {
            throw new RuntimeException('Email address required for subscription status updates');
        }

        $token = $this->getAccessToken();

        $envelope = <<<XML
            <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
              <soap:Header>
                <fueloauth xmlns="http://exacttarget.com">{$token}</fueloauth>
              </soap:Header>
              <soap:Body>
                <UpdateRequest xmlns="http://exacttarget.com/wsdl/partnerAPI">
                  <Objects xsi:type="Subscriber" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
                    <SubscriberKey>{$subscriber->subscriberKey()}</SubscriberKey>
                    <EmailAddress>{$email}</EmailAddress>
                    <Lists>
                      <ID>{$this->listId}</ID>
                      <Status>{$status}</Status>
                    </Lists>
                  </Objects>
                </UpdateRequest>
              </soap:Body>
            </soap:Envelope>
        XML;

        Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction'   => 'Update',
        ])
            ->withBody($envelope, 'text/xml')
            ->post("https://{$this->orgId}.soap.marketingcloudapis.com/Service.asmx")
            ->throw();
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

    protected function forgetAccessToken(): void
    {
        cache()->forget('marketing_cloud:token:' . $this->clientId);
    }
}
