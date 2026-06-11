<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\CliToken;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiController extends Controller
{
    public function index(Request $request): Response
    {
        $user      = $request->user();
        $providers = $user->aiProviders()->get()
            ->map(fn($provider) => $provider->toDisplayArray());

        $cliToken  = $user->cliTokens()->latest()->first();

        return Inertia::render('Console/Admin/Ai', [
            'providers'           => $providers,
            'supported_providers' => ['groq', 'anthropic', 'openai'],
            'cli_token'           => $cliToken ? [
                'name'         => $cliToken->name,
                'last_used_at' => $cliToken->last_used_at?->toDateTimeString(),
                'created_at'   => $cliToken->created_at->toDateTimeString(),
            ] : null,
        ]);
    }
}
