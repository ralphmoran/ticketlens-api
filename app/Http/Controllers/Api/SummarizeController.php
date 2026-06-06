<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\NoAiProviderException;
use App\Http\Requests\SummarizeRequest;
use App\Services\AiService;
use Illuminate\Http\JsonResponse;

class SummarizeController
{
    public function __construct(private readonly AiService $ai) {}

    public function handle(SummarizeRequest $request): JsonResponse
    {
        try {
            $summary = $this->ai->summarize($request->user(), $request->validated('brief'));
        } catch (NoAiProviderException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['summary' => $summary]);
    }
}
