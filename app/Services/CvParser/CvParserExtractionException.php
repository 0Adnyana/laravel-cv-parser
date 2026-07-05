<?php

namespace App\Services\CvParser;

use Exception;

class CvParserExtractionException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $rawContent = null,
    ) {
        parent::__construct($message);
    }
}
