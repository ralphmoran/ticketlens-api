Your TicketLens license is ready.

Tier:  {{ ucfirst($tier) }}
Seats: {{ $seats }}
@if ($expiresAt)
Expires: {{ $expiresAt }}
@else
Expires: never
@endif

License key (save this — it will not be shown again):

{{ $rawKey }}

To activate, run:

  ticketlens activate {{ $rawKey }}

If you did not expect this email, ignore it — the key cannot be used
without your account.

— TicketLens
