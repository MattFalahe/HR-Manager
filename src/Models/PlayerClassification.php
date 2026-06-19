<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cached classifier output. Nightly cron writes; Corp Health page reads.
 * Transitions between categories publish hr.player.flagged_* events for
 * external subscribers (SeAT Broadcast, etc.).
 *
 * wallet_flags: list of CWM wallet-signal flag keys that contributed to the
 * most recent category decision (e.g. ["stalled", "negative_contribution"]).
 * Used by the Corp Health page to render per-row "why" badges + the
 * suite-wide wallet-signals aggregate panel.
 */
class PlayerClassification extends Model
{
    protected $table = 'hr_manager_player_classifications';

    public const CATEGORY_ACTIVE      = 'active';
    public const CATEGORY_AT_RISK     = 'at_risk';
    public const CATEGORY_INACTIVE    = 'inactive';
    public const CATEGORY_DEAD_WEIGHT = 'dead_weight';

    protected $fillable = [
        'user_id',
        'corporation_id',
        'tier_level',
        'category',
        'is_inactive_director',
        'days_inactive',
        'threshold_days',
        'last_activity_at',
        'wallet_flags',
        'classified_at',
    ];

    protected $casts = [
        'tier_level'           => 'integer',
        'is_inactive_director' => 'boolean',
        'days_inactive'        => 'integer',
        'threshold_days'       => 'integer',
        'last_activity_at'     => 'datetime',
        'wallet_flags'         => 'array',
        'classified_at'        => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(\Seat\Web\Models\User::class, 'user_id');
    }

    public function scopeForCorporation($query, int $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }

    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeInactiveDirectors($query)
    {
        return $query->where('is_inactive_director', true);
    }

    public function scopeWithWalletFlag($query, string $flag)
    {
        // Portable JSON_CONTAINS-style filter — works on MySQL 5.7+ / MariaDB 10.2+
        return $query->whereRaw("JSON_CONTAINS(wallet_flags, ?)", ['"' . $flag . '"']);
    }

    public function getBadgeClassAttribute(): string
    {
        return match ($this->category) {
            self::CATEGORY_ACTIVE      => 'badge-accepted',
            self::CATEGORY_AT_RISK     => 'badge-interview',
            self::CATEGORY_INACTIVE    => 'badge-rejected',
            self::CATEGORY_DEAD_WEIGHT => 'badge-withdrawn',
            default                    => 'badge-secondary',
        };
    }
}
