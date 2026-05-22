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

            // Use '*' — the payload contains no secrets, only an event type and
            // integration name. The origin may differ from the parent in local dev
            // (Valet vs ngrok) so a strict same-origin target would silently drop it.
            if (window.opener && !window.opener.closed) {
                window.opener.postMessage(payload, '*');
            }
            // Always attempt close. Works when opened via window.open(); silently
            // fails if the user navigated here directly — in that case the message
            // above is shown so they can close it manually.
            window.close();
        }());
    </script>
</body>
</html>
