<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'hr_manager_audit_log';

    public const UPDATED_AT = null; // append-only; created_at only

    protected $fillable = [
        'actor_user_id', 'actor_character_id', 'actor_name',
        'action', 'category', 'target_type', 'target_id', 'target_label',
        'summary', 'context', 'corporation_id', 'ip_hash', 'occurred_at',
    ];

    protected $casts = [
        'actor_user_id'      => 'integer',
        'actor_character_id' => 'integer',
        'target_id'          => 'integer',
        'corporation_id'     => 'integer',
        'context'            => 'array',
        'occurred_at'        => 'datetime',
    ];

    // Category buckets, for filtering + colour in the audit page.
    public const CATEGORIES = ['view', 'privilege', 'decision', 'security', 'notification', 'management', 'system'];
}
