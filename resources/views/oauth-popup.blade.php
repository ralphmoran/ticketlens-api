<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $success ? 'Connected' : 'Error' }}</title>
    <style>
        body {
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: #0d1117;
            color: #cdd9e5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 14px;
            text-align: center;
        }
        .icon { font-size: 2rem; margin-bottom: 0.5rem; }
        p { margin: 0; color: #8b949e; }
    </style>
</head>
<body>
    @if ($success)
        <div>
            <div class="icon">✓</div>
            <p>Connected. This window will close automatically.</p>
        </div>
    @else
        <div>
            <div class="icon">✕</div>
            <p>{{ $message ?? 'Authorization failed.' }} You can close this window.</p>
        </div>
    @endif

    <script>
        (function () {
            var payload = {
                type:        {!! json_encode($success ? 'oauth-success' : 'oauth-error') !!},
                integration: {!! json_encode($integration) !!},
                @if (!$success)
                message:     {!! json_encode($message ?? '') !!},
                @endif
            };

            // The callback always redirects to $origin/console/oauth-close, so this
            // page is always same-origin as the opener. window.location.origin is the
            // correct target and avoids broadcasting to unrelated frames.
            if (window.opener && !window.opener.closed) {
                window.opener.postMessage(payload, window.location.origin);
            }
            // Always attempt close. Works when opened via window.open(); silently
            // fails if the user navigated here directly — in that case the message
            // above is shown so they can close it manually.
            window.close();
        }());
    </script>
</body>
</html>
