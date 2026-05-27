<?php
namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigestSchedule extends Model
{
    protected $fillable = [
        'user_id',
        'license_key_hash',
        'assigned_to_user_id',
        'email',
        'timezone',
        'deliver_at',
        'active',
        'last_delivered_at',
    ];

    protected $casts = [
        'active'             => 'boolean',
        'last_delivered_at'  => 'datetime',
    ];

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function nextDeliveryAt(): Carbon
    {
        [$h, $m] = explode(':', substr($this->deliver_at, 0, 5));
        $tz   = new \DateTimeZone($this->timezone);
        $now  = Carbon::now($tz);
        $next = $now->copy()->setTime((int) $h, (int) $m, 0);
        if ($next->isPast()) {
            $next->addDay();
        }
        return $next;
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }
}
