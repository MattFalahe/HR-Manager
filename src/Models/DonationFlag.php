<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One direct ISK transfer between a corp member and an entity the corp rates
 * badly, as it was understood on the day the scan looked at it.
 *
 * A flag is an observation, never a verdict. Members trade with people their
 * corp dislikes for entirely ordinary reasons, and the point of the record is
 * to let a director see a pattern, not to accuse anyone on the strength of one
 * transfer.
 */
class DonationFlag extends Model
{
    protected $table = 'hr_manager_donation_flags';

    /** A fact on the record: the transfer predates this human joining the corp. */
    public const TIER_NEUTRAL = 'neutral';

    /** Dated after they were already inside. The one that means something. */
    public const TIER_SUSPECT = 'suspect';

    public const DIRECTION_IN  = 'in';   // member received
    public const DIRECTION_OUT = 'out';  // member sent

    protected $fillable = [
        'character_id',
        'corporation_id',
        'journal_id',
        'counterparty_id',
        'counterparty_type',
        'counterparty_name',
        'amount',
        'direction',
        'occurred_at',
        'standing',
        'standing_from',
        'via_type',
        'via_id',
        'resolved_at',
        'tier',
        'reason',
    ];

    protected $casts = [
        'character_id'    => 'integer',
        'corporation_id'  => 'integer',
        'journal_id'      => 'integer',
        'counterparty_id' => 'integer',
        'via_id'          => 'integer',
        'amount'          => 'decimal:2',
        'standing'        => 'integer',
        'occurred_at'     => 'datetime',
        'resolved_at'     => 'datetime',
    ];

    public function scopeSuspect($query)
    {
        return $query->where('tier', self::TIER_SUSPECT);
    }

    public function scopeForCharacters($query, array $characterIds)
    {
        return $query->whereIn('character_id', $characterIds);
    }

    /**
     * Display colour. Reuses the standings palette so a -10 counterparty reads
     * the same here as it does on the standings list, rather than inventing a
     * second colour language for the same number.
     *
     * @return array{bg:string, fg:string, label:string}
     */
    public function palette(): array
    {
        return StandingEntry::palette((int) $this->standing);
    }

    /**
     * Why this entity is rated at all: usually not by name, but through the
     * corp or alliance it belongs to. A director reading "hostile" needs to
     * know it was inherited, or the flag looks like a claim about the person.
     */
    public function viaLabel(): ?string
    {
        if ($this->via_type === null || (int) $this->via_id === (int) $this->counterparty_id) {
            return null; // rated directly, nothing to explain
        }

        return $this->via_type;
    }
}
