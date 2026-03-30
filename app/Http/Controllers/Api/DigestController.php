<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DigestController extends Controller
{
    public function deliver(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }
}
