<?php

namespace App\Services\CvParser\Extraction;

use App\Services\CvParser\Exceptions\CvParserExtractionException;
use App\Services\CvParser\OpenRouter\OpenRouterClient;
use App\Services\CvParser\OpenRouter\OpenRouterError;
use App\Services\CvParser\OpenRouter\OpenRouterStructuredResponseParser;
use App\Services\CvParser\Prompts\CvParserPromptBuilder;
use Illuminate\Http\Client\Response;

class MultimodalCvExtractor
{
    public function __construct(
        private readonly OpenRouterClient $openRouterClient,
        private readonly CvParserPromptBuilder $promptBuilder,
        private readonly OpenRouterStructuredResponseParser $responseParser,
    ) {}

    /**
     * @return array{personal_info: array<string, mixed>, experience_education: array<string, mixed>, skills_portfolio: array<string, mixed>}|null
     */
    public function attemptExtract(string $base64, string $filename): ?array
    {
        $config = config('services.openrouter');

        $response = $this->openRouterClient->request('post', '/chat/completions', [
            'model' => $config['model'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->promptBuilder->structuringSystemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $this->promptBuilder->extractionPrompt(),
                        ],
                        [
                            'type' => 'file',
                            'file' => [
                                'filename' => $filename,
                                'file_data' => 'data:application/pdf;base64,'.$base64,
                            ],
                        ],
                    ],
                ],
            ],
            'plugins' => [
                [
                    'id' => 'file-parser',
                    'pdf' => [
                        'engine' => $config['pdf_engine'],
                    ],
                ],
            ],
        ]);

        if (! $response->successful()) {
            if ($this->isPdfParseError($response)) {
                return null;
            }

            throw new CvParserExtractionException(
                OpenRouterError::fromResponse($response)->userMessage(),
            );
        }

        return $this->responseParser->parse($response);
    }

    private function isPdfParseError(Response $response): bool
    {
        $haystacks = [
            (string) data_get($response->json(), 'error.message'),
            (string) data_get($response->json(), 'message'),
            $response->body(),
        ];

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && stripos($haystack, 'unable to parse pdf') !== false) {
                return true;
            }
        }

        return false;
    }
}
