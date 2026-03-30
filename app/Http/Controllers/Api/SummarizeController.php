<?php
namespace App\Http\Controllers\Api;

use App\Http\Requests\SummarizeRequest;
use App\Services\AnthropicService;
use Illuminate\Http\JsonResponse;

class SummarizeController
{
    public function __construct(private readonly AnthropicService $anthropic) {}

    public function handle(SummarizeRequest $request): JsonResponse
    {
        $summary = $this->anthropic->summarize($request->validated('brief'));
        return response()->json(['summary' => $summary]);
    }
}
