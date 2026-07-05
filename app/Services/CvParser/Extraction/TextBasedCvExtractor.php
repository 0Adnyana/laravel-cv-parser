<?php

namespace App\Services\CvParser\Extraction;

use App\Services\CvParser\Exceptions\CvParserExtractionException;
use App\Services\CvParser\OpenRouter\OpenRouterClient;
use App\Services\CvParser\OpenRouter\OpenRouterError;
use App\Services\CvParser\OpenRouter\OpenRouterStructuredResponseParser;
use App\Services\CvParser\Pdf\PdfTextExtractor;
use App\Services\CvParser\Prompts\CvParserPromptBuilder;

class TextBasedCvExtractor
{
    public function __construct(
        private readonly OpenRouterClient $openRouterClient,
        private readonly CvParserPromptBuilder $promptBuilder,
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly OpenRouterStructuredResponseParser $responseParser,
    ) {}

    /**
     * @return array{personal_info: array<string, mixed>, experience_education: array<string, mixed>, skills_portfolio: array<string, mixed>}
     */
    public function extract(string $base64, string $filename): array
    {
        $extractedText = $this->pdfTextExtractor->extract($base64, $filename);

        $response = $this->openRouterClient->request('post', '/chat/completions', [
            'model' => config('services.openrouter.model'),
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
                            'type' => 'text',
                            'text' => "--- CV TEXT ---\n{$extractedText}",
                        ],
                    ],
                ],
            ],
        ]);

        if (! $response->successful()) {
            throw new CvParserExtractionException(
                OpenRouterError::fromResponse($response)->userMessage(),
            );
        }

        return $this->responseParser->parse($response);
    }
}
