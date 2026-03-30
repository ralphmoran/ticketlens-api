<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function show(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function destroy(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }
}
