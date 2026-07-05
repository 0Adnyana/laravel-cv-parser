<?php

namespace App\Services\CvParser\OpenRouter;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OpenRouterClient
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function request(string $method, string $path, ?array $payload = null): Response
    {
        $config = config('services.openrouter');
        $url = rtrim((string) $config['base_url'], '/').$path;

        $pendingRequest = Http::timeout((int) config('services.openrouter.request_timeout', 60))
            ->withHeaders([
                'Authorization' => 'Bearer '.$config['api_key'],
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ]);

        return match ($method) {
            'get' => $pendingRequest->get($url),
            'post' => $pendingRequest->post($url, $payload ?? []),
            default => throw new \InvalidArgumentException("Unsupported HTTP method [{$method}]."),
        };
    }
}
