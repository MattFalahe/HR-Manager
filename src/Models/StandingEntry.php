<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entity's standing in HR's own list. Mirrors EVE's five-step scale so a
 * director reading it recognises the values, and so HR can sit on top of SeAT's
 * Standings Builder as an override layer rather than a rival list.
 */
class StandingEntry extends Model
{
    protected $table = 'hr_manager_standings';

    public const TYPE_ALLIANCE    = 'alliance';
    public const TYPE_CORPORATION = 'corporation';
    public const TYPE_CHARACTER   = 'character';

    public const TYPES = [self::TYPE_ALLIANCE, self::TYPE_CORPORATION, self::TYPE_CHARACTER];

    /** EVE's five steps. Anything else is rejected rather than rounded. */
    public const TERRIBLE = -10;
    public const BAD      = -5;
    public const NEUTRAL  = 0;
    public const GOOD     = 5;
    public const EXCELLENT = 10;

    public const VALUES = [self::TERRIBLE, self::BAD, self::NEUTRAL, self::GOOD, self::EXCELLENT];

    protected $fillable = [
        'entity_id',
        'entity_type',
        'standing',
        'entity_name',
        'notes',
        'set_by',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'standing'  => 'integer',
        'set_by'    => 'integer',
    ];

    public function scopeHostile($query)
    {
        return $query->where('standing', '<', 0);
    }

    public function scopeFriendly($query)
    {
        return $query->where('standing', '>', 0);
    }

    /** Is this a value we recognise? Used to reject anything off the scale. */
    public static function isValidStanding($value): bool
    {
        return in_array((int) $value, self::VALUES, true);
    }

    public static function isValidType($type): bool
    {
        return in_array((string) $type, self::TYPES, true);
    }

    /**
     * Display colour for a standing, matching EVE's own convention so the
     * badge reads instantly: terrible red, bad orange, neutral grey, good
     * light blue, excellent blue.
     *
     * @return array{bg:string, fg:string, label:string}
     */
    public static function palette(int $standing): array
    {
        switch (true) {
            case $standing <= self::TERRIBLE:
                return ['bg' => 'rgba(220,53,69,0.25)',  'fg' => '#f5a3ac', 'label' => 'Terrible (-10)'];
            case $standing < 0:
                return ['bg' => 'rgba(253,126,20,0.25)', 'fg' => '#ffc08a', 'label' => 'Bad (-5)'];
            case $standing === 0:
                return ['bg' => 'rgba(255,255,255,0.10)', 'fg' => '#c5cdd8', 'label' => 'Neutral (0)'];
            case $standing < self::EXCELLENT:
                return ['bg' => 'rgba(23,162,184,0.25)', 'fg' => '#8fd7e8', 'label' => 'Good (+5)'];
            default:
                return ['bg' => 'rgba(13,110,253,0.28)', 'fg' => '#9ec5fe', 'label' => 'Excellent (+10)'];
        }
    }
}
