<?php

namespace App\Services\CvParser;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OpenRouterClient
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function chatCompletions(array $payload): Response
    {
        return $this->request('post', '/chat/completions', $payload);
    }

    public function models(): Response
    {
        return $this->request('get', '/models');
    }

    public function supportsFileInput(string $modelSlug): bool
    {
        $response = $this->models();

        if (! $response->successful()) {
            return false;
        }

        $models = $response->json('data');

        if (! is_array($models)) {
            return false;
        }

        foreach ($models as $model) {
            if (! is_array($model) || ($model['id'] ?? null) !== $modelSlug) {
                continue;
            }

            $modalities = data_get($model, 'architecture.input_modalities');

            if (! is_array($modalities)) {
                return false;
            }

            return in_array('file', $modalities, true);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function request(string $method, string $path, ?array $payload = null): Response
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
