<?php

namespace Nlincs\MarketingCloudLaravel;

use Illuminate\Support\Facades\Http;
use Nlincs\MarketingCloudLaravel\Testing\MarketingCloudFake;
use RuntimeException;

class DataExtension
{
    public function __construct(protected MarketingCloudService $service, protected string $name, protected array $definition)
    {
        //
    }

    public function upsert(Subscriber $subscriber): void
    {
        if (MarketingCloudFake::isActive()) {
            MarketingCloudFake::recordUpsert($this->name, $subscriber);
            return;
        }

        $keys = [
            $this->definition['primary_key'] => $subscriber->subscriberKey(),
        ];

        $this->service->restRequest(function (string $token) use ($keys, $subscriber) {
            return Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->post(
                "https://{$this->service->orgId()}.rest.marketingcloudapis.com/hub/v1/dataevents/key:{$this->definition['key']}/rowset",
                [[
                    'keys' => $keys,
                    'values' => array_merge(
                        $subscriber->attributes(),
                        $keys
                    ),
                ]]
            );
        });
    }
}
