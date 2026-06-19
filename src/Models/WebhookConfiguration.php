<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookConfiguration extends Model
{
    protected $table = 'hr_manager_webhook_configurations';

    protected $fillable = [
        'name',
        'type',
        'webhook_url',
        'is_enabled',
        'notify_application_submitted',
        'notify_application_accepted',
        'notify_application_rejected',
        'notify_status_change',
        'notify_inactive_director',
        'notify_dead_weight',
        'notify_purge_reminder',
        'notify_player_status',
        'notify_wallet_stalled',
        'notify_wallet_compliance_dropped',
        'notify_wallet_milestone',
        'discord_role_id',
        'discord_username',
        'slack_channel',
        'slack_username',
        'corporation_id',
        'success_count',
        'failure_count',
        'last_success_at',
        'last_failure_at',
        'last_error',
    ];

    protected $casts = [
        'is_enabled'                    => 'boolean',
        'notify_application_submitted'  => 'boolean',
        'notify_application_accepted'   => 'boolean',
        'notify_application_rejected'   => 'boolean',
        'notify_status_change'          => 'boolean',
        'notify_inactive_director'      => 'boolean',
        'notify_dead_weight'            => 'boolean',
        'notify_purge_reminder'         => 'boolean',
        'notify_player_status'          => 'boolean',
        'notify_wallet_stalled'         => 'boolean',
        'notify_wallet_compliance_dropped' => 'boolean',
        'notify_wallet_milestone'       => 'boolean',
        'last_success_at'               => 'datetime',
        'last_failure_at'               => 'datetime',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Match webhooks targeting a given corp + global (NULL corp) webhooks.
     * NULL-corp webhooks act as the "fires on every corp's events" channel.
     */
    public function scopeForCorporation($query, ?int $corporationId)
    {
        return $query->where(function ($q) use ($corporationId) {
            $q->where('corporation_id', $corporationId)
              ->orWhereNull('corporation_id');
        });
    }

    public function recordSuccess(): void
    {
        $this->forceFill([
            'success_count'   => \Illuminate\Support\Facades\DB::raw('success_count + 1'),
            'last_success_at' => now(),
            'last_error'      => null,
        ])->save();
    }

    public function recordFailure(string $error): void
    {
        $this->forceFill([
            'failure_count'   => \Illuminate\Support\Facades\DB::raw('failure_count + 1'),
            'last_failure_at' => now(),
            'last_error'      => $error,
        ])->save();
    }
}
