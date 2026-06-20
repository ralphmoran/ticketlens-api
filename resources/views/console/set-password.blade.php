<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Your Password — TicketLens</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
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
        }
        .card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 12px;
            padding: 40px;
            max-width: 420px;
            width: 100%;
            margin: 16px;
        }
        .logo {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: #cdd9e5;
            margin-bottom: 28px;
            text-align: center;
        }
        .logo span { color: #388bfd; }
        h1 {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 6px;
            color: #cdd9e5;
        }
        .subtitle {
            font-size: 13px;
            color: #8b949e;
            margin: 0 0 24px;
            line-height: 1.5;
        }
        .field { margin-bottom: 16px; }
        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #8b949e;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        input[type="password"] {
            width: 100%;
            padding: 9px 12px;
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 6px;
            color: #cdd9e5;
            font-size: 14px;
            outline: none;
            transition: border-color 0.15s;
        }
        input[type="password"]:focus { border-color: #388bfd; }
        .error {
            margin: 0 0 16px;
            padding: 10px 14px;
            background: rgba(248, 81, 73, 0.1);
            border: 1px solid rgba(248, 81, 73, 0.3);
            border-radius: 6px;
            font-size: 13px;
            color: #f85149;
        }
        .warning {
            margin: 0 0 16px;
            padding: 10px 14px;
            background: rgba(210, 153, 34, 0.1);
            border: 1px solid rgba(210, 153, 34, 0.3);
            border-radius: 6px;
            font-size: 13px;
            color: #d29922;
        }
        .btn {
            display: block;
            width: 100%;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            background: #238636;
            color: #fff;
            transition: opacity 0.15s;
            margin-top: 8px;
        }
        .btn:hover { opacity: 0.85; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">Ticket<span>Lens</span></div>

        <h1>Set your password</h1>
        <p class="subtitle">Choose a password to activate your TicketLens account.</p>

        @if ($authWarning)
            <div class="warning">
                You're signed in as {{ $authWarning }}. Sign out first, then return to this link,
                or <a href="{{ route('console.dashboard') }}" style="color:#d29922">go to dashboard</a>.
            </div>
        @else
            @if ($errors->any())
                <div class="error">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="email" value="{{ $email }}">

                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="new-password" required autofocus minlength="8">
                </div>

                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required minlength="8">
                </div>

                <button type="submit" class="btn">Set password &amp; sign in</button>
            </form>
        @endif
    </div>
</body>
</html>
