<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-character throttle state for the recurring CWM wallet alerts (tax
 * compliance dropped / contributions stalled). Written by WalletEventHandler to
 * decide, per operator policy, whether a given event should re-ping Discord.
 * See migration 2026_06_01_000030_create_wallet_alert_state.
 */
class WalletAlertState extends Model
{
    protected $table = 'hr_manager_wallet_alert_state';

    protected $fillable = [
        'corporation_id',
        'character_id',
        'event_type',
        'last_seen_at',
        'last_notified_at',
    ];

    protected $casts = [
        'last_seen_at'     => 'datetime',
        'last_notified_at' => 'datetime',
    ];
}
