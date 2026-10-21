<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnterpriseContactRequest;
use App\Mail\EnterpriseContactMail;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EnterpriseContactController extends Controller
{
    public function __invoke(EnterpriseContactRequest $request): Response
    {
        if ($request->isSpam()) {
            return response()->noContent();
        }

        try {
            Mail::to(config('tiers.enterprise_contact_email'))->send(new EnterpriseContactMail(
                $request->string('name')->toString(),
                $request->string('email')->toString(),
                $request->string('message')->toString(),
            ));
        } catch (Throwable $e) {
            // Sent synchronously on purpose: a failure must reach the visitor, not die in a queue.
            Log::error('Enterprise contact mail failed', ['exception' => $e->getMessage()]);

            return response()->noContent(503);
        }

        return response()->noContent();
    }
}
