<?php

namespace App\Http\Controllers\Console;

use Inertia\Inertia;
use Inertia\Response;

class AdminController
{
    public function clients(): Response
    {
        return Inertia::render('Console/Admin/Clients');
    }

    public function licenses(): Response
    {
        return Inertia::render('Console/Admin/Licenses');
    }

    public function revenue(): Response
    {
        return Inertia::render('Console/Admin/Revenue');
    }
}
