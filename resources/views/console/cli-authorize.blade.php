<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize TicketLens CLI</title>
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
            text-align: center;
        }
        .logo {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: #cdd9e5;
            margin-bottom: 28px;
        }
        .logo span { color: #388bfd; }
        h1 {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 8px;
            color: #cdd9e5;
        }
        .subtitle {
            font-size: 13px;
            color: #8b949e;
            margin: 0 0 28px;
            line-height: 1.5;
        }
        .meta {
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 24px;
            text-align: left;
        }
        .meta-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }
        .meta-row + .meta-row { margin-top: 8px; }
        .meta-label { color: #8b949e; min-width: 68px; }
        .meta-value { color: #cdd9e5; font-weight: 500; }
        .btn {
            display: block;
            width: 100%;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: opacity 0.15s;
        }
        .btn:hover { opacity: 0.85; }
        .btn-primary {
            background: #238636;
            color: #fff;
            margin-bottom: 10px;
        }
        .btn-secondary {
            background: transparent;
            border: 1px solid #30363d;
            color: #8b949e;
            text-decoration: none;
            line-height: 1;
            padding: 9px 16px;
        }
        .footer {
            margin-top: 24px;
            font-size: 12px;
            color: #6e7681;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">Ticket<span>Lens</span></div>

        <h1>Authorize CLI access</h1>
        <p class="subtitle">
            <strong>{{ $userName }}</strong>, a TicketLens CLI on your machine is requesting
            access to your account. Authorizing will replace any existing CLI token.
        </p>

        <div class="meta">
            <div class="meta-row">
                <span class="meta-label">Account</span>
                <span class="meta-value">{{ $userName }}</span>
            </div>
            @if ($hostname)
            <div class="meta-row">
                <span class="meta-label">Machine</span>
                <span class="meta-value">{{ $hostname }}</span>
            </div>
            @endif
        </div>

        <form method="POST" action="{{ route('console.auth.cli.authorize') }}">
            @csrf
            <input type="hidden" name="port"     value="{{ $port }}">
            <input type="hidden" name="state"    value="{{ $state }}">
            <input type="hidden" name="hostname" value="{{ $hostname ?? '' }}">
            <button type="submit" class="btn btn-primary">Authorize TicketLens CLI</button>
        </form>

        <a href="http://localhost:{{ $port }}/callback?error=access_denied&state={{ $state }}" class="btn btn-secondary">Cancel</a>

        <p class="footer">
            Only authorize if you initiated this request from your terminal.<br>
            Your token is stored locally — never shared with third parties.
        </p>
    </div>
</body>
</html>
