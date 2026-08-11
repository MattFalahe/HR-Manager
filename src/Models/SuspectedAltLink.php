<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A director's assertion that one character is an alt of another, held
 * separately from HR's authoritative identity mappings until evidence settles
 * it. See the 2026_08_01_000042 migration for why it lives on its own.
 */
class SuspectedAltLink extends Model
{
    protected $table = 'hr_manager_suspected_alt_links';

    /** Asserted by a human, no corroborating account data yet. */
    public const STATE_SUSPECTED = 'suspected';

    /** Both characters resolved to the SAME SeAT account — the claim holds. */
    public const STATE_CONFIRMED = 'confirmed';

    /** Both resolved to DIFFERENT accounts — the claim was wrong. */
    public const STATE_REFUTED = 'refuted';

    public const SOURCE_WATCHLIST = 'watchlist';
    public const SOURCE_INTEL     = 'intel';

    protected $fillable = [
        'suspected_character_id',
        'suspected_character_name',
        'main_character_id',
        'main_character_name',
        'source',
        'source_id',
        'state',
        'asserted_by',
        'asserted_at',
        'resolved_at',
        'resolution_note',
    ];

    protected $casts = [
        'suspected_character_id' => 'integer',
        'main_character_id'      => 'integer',
        'source_id'              => 'integer',
        'asserted_by'            => 'integer',
        'asserted_at'            => 'datetime',
        'resolved_at'            => 'datetime',
    ];

    public function scopeSuspected($query)
    {
        return $query->where('state', self::STATE_SUSPECTED);
    }

    /** Still an open question — nothing has proven or disproven it yet. */
    public function isOpen(): bool
    {
        return $this->state === self::STATE_SUSPECTED;
    }
}
