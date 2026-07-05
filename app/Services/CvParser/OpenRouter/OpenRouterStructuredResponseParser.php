<?php

namespace App\Services\CvParser\OpenRouter;

use App\Services\CvParser\Exceptions\CvParserExtractionException;
use App\Services\CvParser\Mapping\OnboardingFieldMapper;
use Illuminate\Http\Client\Response;

class OpenRouterStructuredResponseParser
{
    public function __construct(
        private readonly OnboardingFieldMapper $mapper,
    ) {}

    /**
     * @return array{personal_info: array<string, mixed>, experience_education: array<string, mixed>, skills_portfolio: array<string, mixed>}
     */
    public function parse(Response $response): array
    {
        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new CvParserExtractionException('CV extraction failed: empty model response.');
        }

        try {
            $decoded = $this->decodeJson($content);
        } catch (CvParserExtractionException $exception) {
            throw new CvParserExtractionException($exception->getMessage(), $content);
        }

        return $this->mapper->map($decoded);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeJson(string $content): array
    {
        $stripped = trim($content);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $stripped, $matches)) {
            $stripped = trim($matches[1]);
        }

        $decoded = json_decode($stripped, true);

        if (! is_array($decoded)) {
            throw new CvParserExtractionException('CV extraction failed: model response was not valid JSON.');
        }

        return $decoded;
    }
}
