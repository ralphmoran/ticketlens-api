<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnterpriseContactRequest;
use App\Mail\EnterpriseContactMail;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;

class EnterpriseContactController extends Controller
{
    public function __invoke(EnterpriseContactRequest $request): Response
    {
        if (! $request->isSpam()) {
            Mail::to(config('tiers.enterprise_contact_email'))->send(new EnterpriseContactMail(
                $request->string('name')->toString(),
                $request->string('email')->toString(),
                $request->string('message')->toString(),
            ));
        }

        return response()->noContent();
    }
}
