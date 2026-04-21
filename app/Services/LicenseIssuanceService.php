<?php

namespace App\Services;

use App\Mail\LicenseIssuedMail;
use App\Models\License;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Issues license keys directly from the Owner Console.
 *
 * Key format: "TL-" + UUID v4. Raw key is returned ONCE and never persisted
 * or logged. Only the SHA-256 hash is stored in licenses.lemon_key_hash.
 *
 * Hash algorithm matches LemonSqueezyWebhookController to keep ValidateLicenseKey
 * middleware working uniformly across both issuance paths.
 */
class LicenseIssuanceService
{
    private const DEFAULT_SEATS = ['free' => 1, 'pro' => 1, 'team' => 5, 'enterprise' => 25];
    private const VALID_TIERS   = ['pro', 'team', 'enterprise'];
    private const KEY_PREFIX    = 'TL-';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return array{raw_key: string, license: License}
     */
    public function issue(
        User $owner,
        User $recipient,
        string $tier,
        ?Carbon $expiresAt = null,
        ?int $seats = null,
        bool $sendEmail = true,
    ): array {
        $this->assertValidTier($tier);

        $rawKey = $this->generateRawKey();
        $hash   = hash('sha256', $rawKey);

        $license = DB::transaction(function () use ($owner, $recipient, $tier, $expiresAt, $seats, $hash) {
            return License::create([
                'user_id'           => $recipient->id,
                'issued_by_user_id' => $owner->id,
                'lemon_key_hash'    => $hash,
                'status'            => 'active',
                'tier'              => $tier,
                'seats'             => $seats ?? self::DEFAULT_SEATS[$tier],
                'expires_at'        => $expiresAt,
            ]);
        });

        // Audit log includes ONLY the last 8 hex of the hash — never the raw key.
        $this->audit->log(
            actor: $owner,
            action: 'license.issued',
            target: $recipient,
            metadata: [
                'license_id' => $license->id,
                'tier'       => $tier,
                'seats'      => $license->seats,
                'hash_tail'  => substr($hash, -8),
                'emailed'    => $sendEmail,
            ],
        );

        if ($sendEmail) {
            Mail::to($recipient->email)->queue(new LicenseIssuedMail(
                rawKey:    $rawKey,
                tier:      $tier,
                seats:     $license->seats,
                expiresAt: $expiresAt,
            ));
        }

        return ['raw_key' => $rawKey, 'license' => $license];
    }

    /**
     * Soft revoke — sets status='cancelled'. Never deletes the row (audit trail).
     */
    public function revoke(User $owner, License $license): void
    {
        if ($license->status === 'cancelled') {
            return;
        }

        DB::transaction(fn () => $license->update(['status' => 'cancelled']));

        $this->audit->log(
            actor: $owner,
            action: 'license.revoked',
            target: $license->user,
            metadata: [
                'license_id' => $license->id,
                'hash_tail'  => substr($license->lemon_key_hash, -8),
            ],
        );
    }

    private function generateRawKey(): string
    {
        // Str::uuid() returns a cryptographically random v4 UUID (128 bits).
        return self::KEY_PREFIX . Str::uuid()->toString();
    }

    private function assertValidTier(string $tier): void
    {
        if (! in_array($tier, self::VALID_TIERS, true)) {
            throw new \InvalidArgumentException("Cannot issue license for tier '{$tier}'. Valid: " . implode(', ', self::VALID_TIERS));
        }
    }
}
