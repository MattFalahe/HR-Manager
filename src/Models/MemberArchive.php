<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One completed membership, frozen at the moment it ended.
 *
 * Not a "former member" record: a person is a former member only while they
 * are not currently on the roster, and that is derived rather than stored. This
 * is the record of a stint, and it stays true whether or not they ever come
 * back.
 */
class MemberArchive extends Model
{
    protected $table = 'hr_manager_member_archives';

    /** Snapshotted at departure. Complete as of that date. */
    public const SOURCE_RECORDED = 'recorded';

    /** Rebuilt afterwards from whatever survived. Partial by definition. */
    public const SOURCE_RECONSTRUCTED = 'reconstructed';

    public const DEPARTURE_RESIGNED = 'resigned';
    public const DEPARTURE_PURGED   = 'purged';
    public const DEPARTURE_KICKED   = 'kicked';
    public const DEPARTURE_UNKNOWN  = 'unknown';

    protected $fillable = [
        'character_id', 'corporation_id', 'character_name',
        'user_id', 'main_character_id',
        'joined_at', 'left_at', 'days_in_corp',
        'destination_corporation_id', 'destination_corporation_name',
        'departure_type',
        'tier_at_departure', 'classification_at_departure', 'days_inactive_at_departure',
        'wallet_contributed', 'mining_contributed', 'tax_compliance_pct',
        'token_valid_at_departure',
        'note_count', 'intel_count', 'was_blacklisted',
        'source',
    ];

    protected $casts = [
        'character_id'               => 'integer',
        'corporation_id'             => 'integer',
        'user_id'                    => 'integer',
        'main_character_id'          => 'integer',
        'destination_corporation_id' => 'integer',
        'joined_at'                  => 'datetime',
        'left_at'                    => 'datetime',
        'days_in_corp'               => 'integer',
        'days_inactive_at_departure' => 'integer',
        'wallet_contributed'         => 'decimal:2',
        'mining_contributed'         => 'decimal:2',
        'tax_compliance_pct'         => 'integer',
        'token_valid_at_departure'   => 'boolean',
        'note_count'                 => 'integer',
        'intel_count'                => 'integer',
        'was_blacklisted'            => 'boolean',
    ];

    public function scopeReconstructed($query)
    {
        return $query->where('source', self::SOURCE_RECONSTRUCTED);
    }

    /**
     * The key that groups stints into one human.
     *
     * Falls back through account, then nominated main, then the character
     * itself, because an unregistered member has neither of the first two and
     * still deserves a row rather than being dropped or merged into a bucket
     * with every other unregistered leaver.
     */
    public function humanKey(): string
    {
        if ($this->user_id) {
            return 'user:' . $this->user_id;
        }
        if ($this->main_character_id) {
            return 'main:' . $this->main_character_id;
        }

        return 'char:' . $this->character_id;
    }

    /** A reconstructed row is only as complete as whatever survived. */
    public function isReconstructed(): bool
    {
        return $this->source === self::SOURCE_RECONSTRUCTED;
    }
}
