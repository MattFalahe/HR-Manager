<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Note extends Model
{
    use SoftDeletes;

    protected $table = 'hr_manager_notes';

    protected $fillable = [
        'noteable_type',
        'noteable_id',
        'author_id',
        'content',
        'is_private',
    ];

    protected $casts = [
        'is_private' => 'boolean',
    ];

    // ---- Relationships ----

    public function noteable()
    {
        return $this->morphTo();
    }

    public function author()
    {
        return $this->belongsTo(\Seat\Web\Models\User::class, 'author_id');
    }

    // ---- Scopes ----

    /**
     * Scope to only return notes visible to the given user.
     * Private notes are ONLY visible to their author.
     */
    public function scopeVisibleTo($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('is_private', false)
              ->orWhere('author_id', $userId);
        });
    }

    /**
     * Scope to notes for a specific noteable (application or member).
     */
    public function scopeForNoteable($query, string $type, int $id)
    {
        return $query->where('noteable_type', $type)
                     ->where('noteable_id', $id);
    }

    public function scopeForApplication($query, int $applicationId)
    {
        return $query->forNoteable('application', $applicationId);
    }

    public function scopeForMember($query, int $characterId)
    {
        return $query->forNoteable('member', $characterId);
    }
}
