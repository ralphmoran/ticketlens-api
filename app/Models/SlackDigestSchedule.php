<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlackDigestSchedule extends Model
{
    protected $fillable = [
        'group_id',
        'day_of_week',
        'deliver_at',
        'timezone',
        'target_type',
        'target_id',
        'target_label',
        'active',
        'last_delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week'       => 'integer',
            'active'            => 'boolean',
            'last_delivered_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Returns true when this schedule should fire right now.
     * Matches day-of-week + HH:MM in the schedule's timezone,
     * and has not already been delivered in the last 23 hours.
     */
    public function isDue(?Carbon $now = null): bool
    {
        $tz  = new \DateTimeZone($this->timezone);
        $now = ($now ?? Carbon::now())->copy()->setTimezone($tz);

        if ($now->dayOfWeek !== $this->day_of_week) {
            return false;
        }

        if ($now->format('H:i') !== $this->deliver_at) {
            return false;
        }

        if ($this->last_delivered_at && $this->last_delivered_at->gt($now->copy()->subHours(23))) {
            return false;
        }

        return true;
    }
}
