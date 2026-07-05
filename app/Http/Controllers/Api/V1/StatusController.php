<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CvParser\Configuration\CvParserConfiguration;
use Illuminate\Http\JsonResponse;

class StatusController extends Controller
{
    public function __invoke(CvParserConfiguration $configuration): JsonResponse
    {
        $warning = $configuration->getWarning();

        return response()->json([
            'available' => $warning === null,
            'warning' => $warning,
        ]);
    }
}
