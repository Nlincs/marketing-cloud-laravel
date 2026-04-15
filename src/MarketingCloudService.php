<?php

namespace YourVendor\MarketingCloud;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    public function upsertDataExtension(string $dataExtension, string $subscriberKey, array $attributes): void {
        $definition = config("marketingcloud.data_extensions.{$dataExtension}");

        if (! $definition) {
            throw new RuntimeException("Unknown Data Extension [$dataExtension]");
        }

        $token = $this->getAccessToken();

        $keys = [
            $definition['primary_key'] => $subscriberKey,
        ];

        Http::withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->post(
            "https://{$this->orgId}.rest.marketingcloudapis.com/hub/v1/dataevents/key:{$definition['key']}/rowset",
            [[
                'keys' => $keys,
                'values' => array_merge($attributes, $keys),
            ]]
        )->throw();
    }

    public function subscribe(string $email, string $subscriberKey): void
    {
        $this->updateSubscriptionStatus($email, $subscriberKey, 'Active');
    }

    public function unsubscribe(string $email, string $subscriberKey): void
    {
        $this->updateSubscriptionStatus($email, $subscriberKey, 'Unsubscribed');
    }

    protected function updateSubscriptionStatus(string $email, string $subscriberKey, string $status): void {
        $token = $this->getAccessToken();

        $envelope = <<<XML
            <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
              <soap:Header>
                <fueloauth xmlns="http://exacttarget.com">{$token}</fueloauth>
              </soap:Header>
              <soap:Body>
                <UpdateRequest xmlns="http://exacttarget.com/wsdl/partnerAPI">
                  <Objects xsi:type="Subscriber" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
                    <SubscriberKey>{$subscriberKey}</SubscriberKey>
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
        ])->withBody($envelope, 'text/xml')
            ->post("https://{$this->orgId}.soap.marketingcloudapis.com/Service.asmx")
            ->throw();
    }
}