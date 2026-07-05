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
        $config = config('services.openrouter');

        return Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer '.$config['api_key'],
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])
            ->post(rtrim((string) $config['base_url'], '/').'/chat/completions', $payload);
    }
}
