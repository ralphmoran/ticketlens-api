<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ExportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Console/Export');
    }
}
