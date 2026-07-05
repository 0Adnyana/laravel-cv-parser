<?php

namespace App\Services\CvParser;

use App\Services\CvParser\Configuration\CvParserConfiguration;
use App\Services\CvParser\Exceptions\CvParserConfigurationException;
use App\Services\CvParser\Extraction\MultimodalCvExtractor;
use App\Services\CvParser\Extraction\TextBasedCvExtractor;
use App\Services\CvParser\OpenRouter\OpenRouterClient;
use App\Services\CvParser\Pdf\PdfFileReader;
use Illuminate\Http\UploadedFile;

class CvParserService
{
    public function __construct(
        private readonly CvParserConfiguration $configuration,
        private readonly PdfFileReader $pdfFileReader,
        private readonly OpenRouterClient $openRouterClient,
        private readonly MultimodalCvExtractor $multimodalExtractor,
        private readonly TextBasedCvExtractor $textBasedExtractor,
    ) {}

    /**
     * @return array{personal_info: array<string, mixed>, experience_education: array<string, mixed>, skills_portfolio: array<string, mixed>}
     */
    public function parse(UploadedFile $file): array
    {
        $warning = $this->configuration->getWarning();

        if ($warning !== null) {
            throw new CvParserConfigurationException($warning);
        }

        $parseTimeLimit = (int) config('services.openrouter.parse_time_limit', 240);

        if ($parseTimeLimit > 0) {
            set_time_limit($parseTimeLimit);
        }

        $config = config('services.openrouter');
        [$base64, $filename] = $this->pdfFileReader->read($file);

        if (! $this->modelSupportsFileInput($config['model'])) {
            return $this->textBasedExtractor->extract($base64, $filename);
        }

        $multimodalResult = $this->multimodalExtractor->attemptExtract($base64, $filename);

        if ($multimodalResult !== null) {
            return $multimodalResult;
        }

        return $this->textBasedExtractor->extract($base64, $filename);
    }

    private function modelSupportsFileInput(string $modelSlug): bool
    {
        $response = $this->openRouterClient->request('get', '/models');

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
}
