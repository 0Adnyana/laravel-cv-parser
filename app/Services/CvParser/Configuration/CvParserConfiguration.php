<?php

namespace App\Services\CvParser\Configuration;

class CvParserConfiguration
{
    public const ALLOWED_PDF_ENGINES = ['cloudflare-ai', 'mistral-ocr', 'native'];

    public const TEXT_EXTRACTION_ENGINE = 'cloudflare-ai';

    public function getWarning(): ?string
    {
        $config = config('services.openrouter');

        if (empty($config['api_key'])) {
            return 'CV parsing is unavailable until OPENROUTER_API_KEY is set.';
        }

        if (empty($config['model'])) {
            return 'CV parsing is unavailable until OPENROUTER_MODEL is set.';
        }

        $engine = $config['pdf_engine'] ?? 'cloudflare-ai';

        if (! in_array($engine, self::ALLOWED_PDF_ENGINES, true)) {
            return 'CV parsing is unavailable until OPENROUTER_PDF_ENGINE is set to one of: cloudflare-ai, mistral-ocr, native.';
        }

        return null;
    }

    public function isAvailable(): bool
    {
        return $this->getWarning() === null;
    }
}
