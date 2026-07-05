<?php

namespace App\Http\Controllers\CvParser;

use App\Http\Controllers\Controller;
use App\Http\Requests\ParseCvRequest;
use App\Services\CvParser\CvParserService;
use App\Services\CvParser\Exceptions\CvParserConfigurationException;
use App\Services\CvParser\Exceptions\CvParserExtractionException;
use Illuminate\Http\JsonResponse;

class ParseCvController extends Controller
{
    public function __invoke(ParseCvRequest $request, CvParserService $cvParserService): JsonResponse
    {
        try {
            $data = $cvParserService->parse($request->file('cv'));

            return response()->json(['data' => $data]);
        } catch (CvParserConfigurationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        } catch (CvParserExtractionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
