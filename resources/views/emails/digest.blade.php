{{-- resources/views/emails/digest.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TicketLens Triage Digest</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
  .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; }
  .header { background: #1a1a2e; color: #fff; padding: 20px 24px; }
  .header h1 { margin: 0; font-size: 18px; font-weight: 600; }
  .header p { margin: 4px 0 0; font-size: 13px; color: #aaa; }
  .body { padding: 24px; }
  .section-title { font-size: 13px; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin: 20px 0 8px; }
  .ticket { display: flex; align-items: flex-start; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
  .dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; }
  .dot-red { background: #e53e3e; }
  .dot-yellow { background: #d69e2e; }
  .ticket-info { margin-left: 10px; flex: 1; }
  .ticket-key { font-weight: 600; font-size: 13px; color: #333; text-decoration: none; }
  .ticket-summary { font-size: 13px; color: #555; margin: 2px 0; }
  .ticket-meta { font-size: 12px; color: #999; }
  .footer { padding: 16px 24px; background: #f9f9f9; border-top: 1px solid #eee; font-size: 12px; color: #999; }
  .footer a { color: #999; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>Triage Digest</h1>
    <p>{{ now()->format('l, F j, Y') }} · {{ $digestData['profile'] ?? 'default' }}</p>
  </div>
  <div class="body">

    @php
      $needsResponse = collect($digestData['tickets'] ?? [])->where('urgency', 'needs-response');
      $aging = collect($digestData['tickets'] ?? [])->where('urgency', 'aging');
    @endphp

    @if($needsResponse->isNotEmpty())
    <div class="section-title">Needs Response ({{ $needsResponse->count() }})</div>
    @foreach($needsResponse as $ticket)
    <div class="ticket">
      <div class="dot dot-red"></div>
      <div class="ticket-info">
        <a href="{{ e($ticket['url'] ?? '#') }}" class="ticket-key">{{ e($ticket['ticketKey']) }}</a>
        <div class="ticket-summary">{{ e($ticket['summary']) }}</div>
        <div class="ticket-meta">
          {{ e($ticket['status']) }}
          @if(!empty($ticket['lastComment']['author']))
          · {{ e($ticket['lastComment']['author']) }}
          @if(!empty($ticket['lastComment']['created']))
          · {{ \Carbon\Carbon::parse($ticket['lastComment']['created'])->diffForHumans() }}
          @endif
          @endif
        </div>
      </div>
    </div>
    @endforeach
    @endif

    @if($aging->isNotEmpty())
    <div class="section-title">Aging — no activity > {{ $digestData['staleDays'] ?? 5 }} days ({{ $aging->count() }})</div>
    @foreach($aging as $ticket)
    <div class="ticket">
      <div class="dot dot-yellow"></div>
      <div class="ticket-info">
        <a href="{{ e($ticket['url'] ?? '#') }}" class="ticket-key">{{ e($ticket['ticketKey']) }}</a>
        <div class="ticket-summary">{{ e($ticket['summary']) }}</div>
        <div class="ticket-meta">
          {{ e($ticket['status']) }}
          @if(!empty($ticket['daysSinceUpdate']))
          · {{ $ticket['daysSinceUpdate'] }}d stale
          @endif
        </div>
      </div>
    </div>
    @endforeach
    @endif

    @if($needsResponse->isEmpty() && $aging->isEmpty())
    <p style="color: #28a745; font-weight: 600;">&#10003; All clear — no tickets need attention today.</p>
    @endif

  </div>
  <div class="footer">
    <a href="#">Unsubscribe</a> · Powered by <a href="https://ticketlens.test">TicketLens</a>
  </div>
</div>
</body>
</html>
