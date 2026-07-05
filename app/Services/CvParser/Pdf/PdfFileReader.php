<?php

namespace App\Services\CvParser\Pdf;

use App\Services\CvParser\Exceptions\CvParserExtractionException;
use Illuminate\Http\UploadedFile;

class PdfFileReader
{
    /**
     * @return array{0: string, 1: string}
     */
    public function read(UploadedFile $file): array
    {
        $pdfContents = file_get_contents($file->getRealPath());

        if ($pdfContents === false) {
            throw new CvParserExtractionException('Failed to read uploaded PDF.');
        }

        $filename = $file->getClientOriginalName() ?: 'cv.pdf';

        return [base64_encode($pdfContents), $filename];
    }
}
