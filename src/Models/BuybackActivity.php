<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Buyback Manager event HR has accumulated: a published offer
 * (engagement) or a completed contract (realized contribution). Populated by
 * BuybackEventHandler off the EventBus and the one-time backfill command.
 */
class BuybackActivity extends Model
{
    public const STAGE_OFFER     = 'offer';
    public const STAGE_COMPLETED = 'completed';

    protected $table = 'hr_manager_buyback_activity';

    protected $fillable = [
        'stage',
        'character_id',
        'corporation_id',
        'target_type',
        'target_corporation_id',
        'target_character_id',
        'mode',
        'offer_public_id',
        'contract_id',
        'total_value',
        'items_count',
        'occurred_at',
    ];

    protected $casts = [
        'character_id'          => 'integer',
        'corporation_id'        => 'integer',
        'target_corporation_id' => 'integer',
        'target_character_id'   => 'integer',
        'contract_id'           => 'integer',
        'total_value'           => 'float',
        'items_count'           => 'integer',
        'occurred_at'           => 'datetime',
    ];

    public function scopeCompleted($query)
    {
        return $query->where('stage', self::STAGE_COMPLETED);
    }

    public function scopeOffers($query)
    {
        return $query->where('stage', self::STAGE_OFFER);
    }
}
