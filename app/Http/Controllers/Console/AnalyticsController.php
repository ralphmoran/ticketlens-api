<?php

namespace App\Http\Controllers\Console;

use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController
{
    public function index(): Response
    {
        return Inertia::render('Console/Analytics');
    }
}
