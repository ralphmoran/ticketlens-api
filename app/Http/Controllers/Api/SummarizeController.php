<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\NoAiProviderException;
use App\Http\Requests\SummarizeRequest;
use App\Models\UsageLog;
use App\Services\AiService;
use Illuminate\Http\JsonResponse;

class SummarizeController
{
    public function __construct(private readonly AiService $ai) {}

    public function handle(SummarizeRequest $request): JsonResponse
    {
        try {
            $result = $this->ai->summarizeWithUsage($request->user(), $request->validated('brief'));
        } catch (NoAiProviderException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        UsageLog::recordAiAction($request->user(), 'summarize', $request->validated('ticketKey'), $result['tokens']);

        return response()->json(['summary' => $result['text']]);
    }
}
