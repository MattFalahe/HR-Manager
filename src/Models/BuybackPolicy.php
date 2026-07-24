<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-corp valuation policy for a buyback programme. The operator declares how
 * a buyback-running corp's contributions are valued and which corporation they
 * are credited to (so an alt / holding corp's buyback can count as main-corp
 * support). Keyed on the buyback-running corporation_id.
 */
class BuybackPolicy extends Model
{
    public const TIER_DIRECT    = 'direct';     // direct corp contribution
    public const TIER_COMMUNITY = 'community';  // corp life / member service
    public const TIER_PERSONAL  = 'personal';   // an individual's operation

    public const TIERS = [self::TIER_DIRECT, self::TIER_COMMUNITY, self::TIER_PERSONAL];

    /** Suggested default weight per tier (operator-tunable). */
    public const TIER_DEFAULT_WEIGHT = [
        self::TIER_DIRECT    => 1.00,
        self::TIER_COMMUNITY => 0.50,
        self::TIER_PERSONAL  => 0.00,
    ];

    protected $table = 'hr_manager_buyback_policies';

    protected $fillable = [
        'corporation_id',
        'counted',
        'tier',
        'weight',
        'attributed_corporation_id',
    ];

    protected $casts = [
        'corporation_id'            => 'integer',
        'counted'                   => 'boolean',
        'weight'                    => 'float',
        'attributed_corporation_id' => 'integer',
    ];

    /** The corp this programme's contributions are credited to (self when unset). */
    public function attributedCorporationId(): int
    {
        return (int) ($this->attributed_corporation_id ?: $this->corporation_id);
    }
}
