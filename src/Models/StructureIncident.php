<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Structure Manager `structure.alert.*` event, accumulated by HR's
 * StructureIncidentService. Corp-scoped; the Structure Health card rolls
 * these up into incident counts.
 */
class StructureIncident extends Model
{
    protected $table = 'hr_manager_structure_incidents';

    protected $fillable = [
        'corporation_id',
        'structure_id',
        'structure_name',
        'incident_type',
        'severity',
        'event_id',
        'occurred_at',
        'payload',
    ];

    protected $casts = [
        'corporation_id' => 'integer',
        'structure_id'   => 'integer',
        'occurred_at'    => 'datetime',
        'payload'        => 'array',
    ];
}
