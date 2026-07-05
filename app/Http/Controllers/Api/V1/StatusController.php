<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CvParser\CvParserService;
use Illuminate\Http\JsonResponse;

class StatusController extends Controller
{
    public function __invoke(CvParserService $cvParserService): JsonResponse
    {
        $warning = $cvParserService->getConfigurationWarning();

        return response()->json([
            'available' => $warning === null,
            'warning' => $warning,
        ]);
    }
}
