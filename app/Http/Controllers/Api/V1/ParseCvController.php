<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ParseCvRequest;
use App\Services\CvParser\CvParserConfigurationException;
use App\Services\CvParser\CvParserExtractionException;
use App\Services\CvParser\CvParserService;
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
            $payload = ['message' => $exception->getMessage()];

            if ($exception->rawContent !== null) {
                $payload['raw_content'] = $exception->rawContent;
            }

            return response()->json($payload, 422);
        }
    }
}
