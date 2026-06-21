<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Web\Models\User;

class WatchlistEntry extends Model
{
    protected $table = 'hr_manager_watchlist_entries';

    public const TYPE_BLACKLIST = 'blacklist';
    public const TYPE_WHITELIST = 'whitelist';

    public const SEVERITY_LOW    = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH   = 'high';

    public const STATUS_ACTIVE  = 'active';
    public const STATUS_CLEARED = 'cleared';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'list_type',
        'scope_corporation_id',
        'scope_alliance_id',
        'character_id',
        'character_name',
        'reason',
        'severity',
        'added_by',
        'added_at',
        'expires_at',
        'status',
        'cleared_at',
        'cleared_by',
        'cleared_reason',
        'notify_on_corp_match',
        'notify_on_alliance_match',
        'notify_on_external_change',
        'last_external_corp_id',
        'last_external_check_at',
    ];

    protected $casts = [
        'character_id'              => 'integer',
        'scope_corporation_id'      => 'integer',
        'scope_alliance_id'         => 'integer',
        'added_by'                  => 'integer',
        'added_at'                  => 'datetime',
        'expires_at'                => 'datetime',
        'cleared_at'                => 'datetime',
        'cleared_by'                => 'integer',
        'notify_on_corp_match'      => 'boolean',
        'notify_on_alliance_match'  => 'boolean',
        'notify_on_external_change' => 'boolean',
        'last_external_corp_id'     => 'integer',
        'last_external_check_at'    => 'datetime',
    ];

    public function addedByUser()
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function scopeBlacklist($query)
    {
        return $query->where('list_type', self::TYPE_BLACKLIST);
    }

    public function scopeWhitelist($query)
    {
        return $query->where('list_type', self::TYPE_WHITELIST);
    }

    /**
     * Filter to entries that match the (corporation, alliance) tuple:
     *   global scope (both NULL)                always match
     *   corp scope alone matches if corp_id equals
     *   alliance scope alone matches if alliance_id equals
     *   corp + alliance scope matches if BOTH match (rare)
     */
    public function scopeForCorporation($query, int $corporationId, ?int $allianceId = null)
    {
        return $query->where(function ($q) use ($corporationId, $allianceId) {
            // Global entries (no scope at all)
            $q->where(function ($g) {
                $g->whereNull('scope_corporation_id')->whereNull('scope_alliance_id');
            });
            // Corp scope
            $q->orWhere(function ($g) use ($corporationId) {
                $g->where('scope_corporation_id', $corporationId)
                  ->whereNull('scope_alliance_id');
            });
            // Alliance scope
            if ($allianceId !== null) {
                $q->orWhere(function ($g) use ($allianceId) {
                    $g->where('scope_alliance_id', $allianceId)
                      ->whereNull('scope_corporation_id');
                });
                // Corp + alliance (rare but supported)
                $q->orWhere(function ($g) use ($corporationId, $allianceId) {
                    $g->where('scope_corporation_id', $corporationId)
                      ->where('scope_alliance_id', $allianceId);
                });
            }
        });
    }

    /**
     * Active entries: status='active' AND not past expires_at.
     * Cleared entries (status='cleared') are kept as audit history.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function scopeCleared($query)
    {
        return $query->where('status', self::STATUS_CLEARED);
    }

    /**
     * Has this entry expired?
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Display name with a fallback chain.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->character_name ?: ('Character #' . $this->character_id);
    }
}
