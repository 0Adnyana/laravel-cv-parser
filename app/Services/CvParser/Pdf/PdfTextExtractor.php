<?php

namespace App\Services\CvParser\Pdf;

use App\Services\CvParser\Configuration\CvParserConfiguration;
use App\Services\CvParser\Exceptions\CvParserExtractionException;
use App\Services\CvParser\OpenRouter\OpenRouterClient;
use App\Services\CvParser\OpenRouter\OpenRouterError;

class PdfTextExtractor
{
    public function __construct(
        private readonly OpenRouterClient $openRouterClient,
    ) {}

    public function extract(string $base64, string $filename): string
    {
        $response = $this->openRouterClient->request('post', '/chat/completions', [
            'model' => config('services.openrouter.model'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Acknowledge receipt of the CV document. The extracted text is returned via file annotations.',
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Extract all text from this CV PDF.',
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
                        'engine' => CvParserConfiguration::TEXT_EXTRACTION_ENGINE,
                    ],
                ],
            ],
        ]);

        $annotations = data_get($response->json(), 'choices.0.message.annotations');

        if (is_array($annotations)) {
            $text = $this->parseFileAnnotations($annotations);

            if ($text !== '') {
                return $text;
            }
        }

        $errorAnnotations = data_get($response->json(), 'error.metadata.file_annotations');

        if (is_array($errorAnnotations)) {
            $text = $this->parseFileAnnotations($errorAnnotations);

            if ($text !== '') {
                return $text;
            }
        }

        if (! $response->successful()) {
            throw new CvParserExtractionException(
                OpenRouterError::fromResponse($response)->userMessage(),
            );
        }

        throw new CvParserExtractionException('CV extraction failed: could not extract text from PDF.');
    }

    /**
     * @param  array<int, mixed>  $annotations
     */
    private function parseFileAnnotations(array $annotations): string
    {
        $textParts = [];

        foreach ($annotations as $annotation) {
            if (! is_array($annotation) || ($annotation['type'] ?? null) !== 'file') {
                continue;
            }

            $content = data_get($annotation, 'file.content');

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (! is_array($part) || ($part['type'] ?? null) !== 'text') {
                    continue;
                }

                $text = $part['text'] ?? null;

                if (is_string($text) && trim($text) !== '') {
                    $textParts[] = trim($text);
                }
            }
        }

        return implode("\n\n", $textParts);
    }
}
