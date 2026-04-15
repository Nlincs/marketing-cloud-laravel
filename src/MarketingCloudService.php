<?php

namespace Nlincs\MarketingCloudLaravel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use function App\Services\config;
use function App\Services\now;

class MarketingCloudService
{
    protected string $orgId;
    protected string $clientId;
    protected string $clientSecret;
    protected string $dataExtensionKey;
    protected string $listId;

    public function __construct()
    {
        $this->orgId            = config('marketing_cloud.org_id');
        $this->clientId         = config('marketing_cloud.client_id');
        $this->clientSecret     = config('marketing_cloud.client_secret');
        $this->dataExtensionKey = config('marketing_cloud.data_extension_key');
        $this->listId           = config('marketing_cloud.list_id');
    }

    protected function getAccessToken(): string
    {
        $cacheKey = 'marketing_cloud:access_token:' . $this->clientId;

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(3500), // buffer vs 3600
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

        if (!$response->successful()) {
            throw new RuntimeException(
                'Failed to retrieve Marketing Cloud access token',
                $response->status()
            );
        }

        return $response->json('access_token');
    }

    protected function restRequest(callable $callback): Response
    {
        $token = $this->getAccessToken();

        $response = $callback($token);

        // Retry once on auth failure
        if ($response->status() === 401) {
            Cache::forget('marketing_cloud:access_token:' . $this->clientId);

            $token = $this->getAccessToken();
            $response = $callback($token);
        }

        if (!$response->successful()) {
            throw new RuntimeException(
                'Marketing Cloud REST request failed',
                $response->status()
            );
        }

        return $response;
    }

    /**
     * @throws ConnectionException
     */
    public function addToDataExtension(string $email, string $subscriberKey, ?string $name = null): void
    {
        $this->restRequest(function (string $token) use ($email, $subscriberKey, $name) {
            $values = [
                'Subscriber Key' => $subscriberKey,
            ];

            if ($name) {
                $values['Name'] = $name;
            }

            return Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->post(
                "https://{$this->orgId}.rest.marketingcloudapis.com/hub/v1/dataevents/key:{$this->dataExtensionKey}/rowset",
                [[
                    'keys' => [
                        'Email address' => $email,
                    ],
                    'values' => $values,
                ]]
            );
        });
    }

    protected function soapEnvelope(string $email, string $subscriberKey, string $status): string
    {
        $token = $this->getAccessToken();

        return <<<XML
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
                       <ID>{$this->listId}</ID>
                       <Status>{$status}</Status>
                    </Lists>
                 </Objects>
              </UpdateRequest>
           </soap:Body>
        </soap:Envelope>
        XML;
    }

    protected function soapRequest(string $envelope): void
    {
        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction'   => 'Update',
        ])->withBody($envelope, 'text/xml')
            ->post("https://{$this->orgId}.soap.marketingcloudapis.com/Service.asmx");

        // SOAP returns 200 even on logical failure, so you may want XML parsing here
        if (!$response->successful()) {
            throw new RuntimeException(
                'Marketing Cloud SOAP request failed',
                $response->status()
            );
        }
    }

    public function unsubscribe(string $email, string $subscriberKey): void
    {
        $this->soapRequest(
            $this->soapEnvelope($email, $subscriberKey, 'Unsubscribed')
        );
    }

    public function subscribe(string $email, string $subscriberKey): void
    {
        $this->soapRequest(
            $this->soapEnvelope($email, $subscriberKey, 'Active')
        );
    }
}
