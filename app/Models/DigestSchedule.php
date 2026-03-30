<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DigestSchedule extends Model
{
    protected $fillable = [
        'license_key_hash',
        'email',
        'timezone',
        'deliver_at',
        'active',
        'last_delivered_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'last_delivered_at' => 'datetime',
    ];

    /**
     * Hash a raw license key for safe storage.
     * Never store the raw key.
     */
    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }
}
