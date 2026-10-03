<?php

namespace App\Http\Controllers\Console;

use App\Http\Requests\Console\UpdateBehaviorRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BehaviorController
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Console/Behavior', [
            'behavior' => [
                'idle_warning_minutes'  => $user->idle_warning_minutes,
                'session_message_style' => $user->session_message_style,
                'idle_warning_choices'  => User::IDLE_WARNING_CHOICES,
                'message_styles'        => User::SESSION_MESSAGE_STYLES,
            ],
        ]);
    }

    public function update(UpdateBehaviorRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return back()->with('success', 'Behavior settings saved.');
    }
}
