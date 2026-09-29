<?php

namespace App\Services\CallGear;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * CallGear Data API client (JSON-RPC 2.0). Written against CallGear's public
 * Data API; confirm method names and fields once API access is granted.
 */
class CallGearClient
{
    public function isConfigured(): bool
    {
        return (bool) config('callgear.enabled') && filled(config('callgear.access_token'));
    }

    public function call(string $method, array $params = []): array
    {
        $response = Http::acceptJson()->timeout(45)->post(config('callgear.base_url'), [
            'jsonrpc' => '2.0',
            'id' => uniqid(),
            'method' => $method,
            'params' => ['access_token' => config('callgear.access_token')] + $params,
        ]);

        if ($response->failed() || $response->json('error')) {
            throw new RuntimeException("CallGear $method failed: ".mb_substr($response->body(), 0, 500));
        }

        return $response->json('result.data') ?? [];
    }

    public function callsReport(string $from, string $till, int $offset = 0, int $limit = 1000): array
    {
        return $this->call('get.calls_report', [
            'date_from' => $from,
            'date_till' => $till,
            'offset' => $offset,
            'limit' => $limit,
            'fields' => [
                'id', 'start_time', 'direction', 'finish_reason', 'is_lost', 'contact_phone_number',
                'virtual_phone_number', 'total_duration', 'wait_duration', 'site_id', 'employees',
            ],
        ]);
    }

    public function employees(): array
    {
        return $this->call('get.employees');
    }
}
