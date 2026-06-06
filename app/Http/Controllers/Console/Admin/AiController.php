<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiController extends Controller
{
    public function index(Request $request): Response
    {
        $providers = $request->user()->aiProviders()->get()
            ->map(fn($provider) => $provider->toDisplayArray());

        return Inertia::render('Console/Admin/Ai', [
            'providers'          => $providers,
            'supported_providers' => ['groq', 'anthropic', 'openai'],
        ]);
    }
}
