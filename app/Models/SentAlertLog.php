<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SentAlertLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['group_id', 'alert_type', 'ticket_key', 'triggered_at'];

    protected function casts(): array
    {
        return ['triggered_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public static function recentlySent(int $groupId, string $alertType, string $ticketKey, int $cooldownHours): bool
    {
        return self::where('group_id', $groupId)
            ->where('alert_type', $alertType)
            ->where('ticket_key', $ticketKey)
            ->where('triggered_at', '>=', now()->subHours($cooldownHours))
            ->exists();
    }
}
