<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreErrorReportRequest;
use App\Models\ErrorReport;
use App\Services\RecallSecretScanner;
use Illuminate\Http\JsonResponse;

class ErrorReportController
{
    public function __invoke(StoreErrorReportRequest $request, RecallSecretScanner $scanner): JsonResponse
    {
        // No auth.cli on this route (see routes/api.php) — an auth failure is
        // exactly one of the errors worth reporting, so the one endpoint that
        // must survive auth being broken can't itself require valid auth.
        //
        // Reused, not a second scanner: RecallSecretScanner's field shape is
        // title/body-oriented, so the report's command/message/metadata are
        // mapped onto it — same defense-in-depth precedent as
        // Recall\PushController running this same scan server-side on top of
        // the CLI's own client-side secret-scanner.mjs redaction.
        //
        // stack_trace is checked separately via containsKnownSecretPattern,
        // not folded into scan()'s full entropy pass — a real V8 stack trace
        // false-positives that pass on nearly every "at fn (path:line:col)"
        // line (found 2026-09-23), which made this field reject almost every
        // real report. A literal secret embedded in one is still caught.
        //
        // metadata's string values are folded into the full scan — this
        // endpoint has no auth, so an unscanned field is a free pass for
        // anyone to stash a secret-shaped string past the exact defense the
        // other fields enforce. Non-string values (already bounded to
        // scalars by StoreErrorReportRequest) have nothing to scan.
        $data = $request->validated();

        $metadataText = implode("\n", array_filter($data['metadata'] ?? [], 'is_string'));

        $scan = $scanner->scan([
            'title' => $data['command'] ?? '',
            'body'  => trim(implode("\n", array_filter([$data['message'], $metadataText]))),
        ]);

        if ($scan['rejected']) {
            return response()->json(['error' => 'Report rejected', 'reasons' => $scan['reasons']], 422);
        }

        if (! empty($data['stack_trace']) && $scanner->containsKnownSecretPattern($data['stack_trace'])) {
            return response()->json(['error' => 'Report rejected', 'reasons' => ['Stack trace looks like it contains a secret.']], 422);
        }

        ErrorReport::create($data);

        return response()->json(['received' => true], 201);
    }
}
