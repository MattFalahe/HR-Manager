<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Web\Models\User;

class ApplicationHandler extends Model
{
    protected $table = 'hr_manager_application_handlers';

    protected $fillable = [
        'application_id',
        'user_id',
        'character_id',
        'role_label',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Snapshot character (the SeAT user's main at join time). Used as
     * the portrait source on the Handlers card + index avatar column.
     * Falls back gracefully in the view when null.
     */
    public function mainCharacter()
    {
        return $this->belongsTo(
            \Seat\Eveapi\Models\Character\CharacterInfo::class,
            'character_id',
            'character_id'
        );
    }
}
