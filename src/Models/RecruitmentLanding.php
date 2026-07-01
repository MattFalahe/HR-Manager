<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Public-facing per-corp recruitment landing page. URL pattern:
 *   /recruit/{corp_ticker}/{slug}
 *
 * The corp ticker is resolved from SeAT's corporation_infos.ticker at
 * render time — landings store corporation_id only so a ticker change
 * (rare) doesn't break stored URLs. Slugs are unique per corp.
 */
class RecruitmentLanding extends Model
{
    protected $table = 'hr_manager_recruitment_landings';

    public const TEMPLATE_CLASSIC    = 'classic';
    public const TEMPLATE_SHOWCASE   = 'showcase';
    public const TEMPLATE_MINIMAL    = 'minimal';
    public const TEMPLATE_INDUSTRIAL = 'industrial';

    public const ALL_TEMPLATES = [
        self::TEMPLATE_CLASSIC,
        self::TEMPLATE_SHOWCASE,
        self::TEMPLATE_MINIMAL,
        self::TEMPLATE_INDUSTRIAL,
    ];

    public const MODE_DISCORD_INVITE  = 'discord_invite';
    public const MODE_SEAT_CONNECTOR  = 'seat_connector';
    public const MODE_CUSTOM          = 'custom';

    protected $fillable = [
        'corporation_id',
        'slug',
        'title',
        'headline',
        'body_markdown',
        'template_key',
        'theme_json',
        'hero_image_path',
        'default_template_id',
        'post_submission_mode',
        'discord_invite_url',
        'custom_confirmation_markdown',
        'next_steps_markdown',
        'eligibility_rules_json',
        'is_published',
        'view_count',
        'application_count',
        'created_by',
    ];

    protected $casts = [
        'theme_json'             => 'array',
        'eligibility_rules_json' => 'array',
        'is_published'           => 'boolean',
        'view_count'             => 'integer',
        'application_count'      => 'integer',
    ];

    public function defaultTemplate()
    {
        return $this->belongsTo(FormTemplate::class, 'default_template_id');
    }

    public function applications()
    {
        return $this->hasMany(Application::class, 'landing_id');
    }

    public function views()
    {
        return $this->hasMany(RecruitmentView::class, 'landing_id');
    }

    /**
     * Resolved corp ticker (from SeAT). Falls back to "#{corp_id}" if the
     * corporation_infos row is absent.
     */
    public function getCorpTickerAttribute(): string
    {
        $ticker = DB::table('corporation_infos')
            ->where('corporation_id', $this->corporation_id)
            ->value('ticker');
        return $ticker ?: '#' . $this->corporation_id;
    }

    public function getCorpNameAttribute(): ?string
    {
        return DB::table('corporation_infos')
            ->where('corporation_id', $this->corporation_id)
            ->value('name');
    }

    public function getPublicUrlAttribute(): string
    {
        return route('hr-manager.recruit.show', [
            'ticker' => $this->corp_ticker,
            'slug'   => $this->slug,
        ]);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeForCorporation($query, int $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }

    /**
     * Atomically bump one or more integer counter columns. Uses Eloquent's
     * increment() under the hood, which issues
     *   UPDATE ... SET col = col + 1, updated_at = NOW() WHERE id = ?
     * and then updates the in-memory attribute to (originalValue + 1).
     *
     * Pre-fix this used forceFill([col => DB::raw('col + 1')])->save(),
     * which crashed during the save pipeline because Eloquent's
     * originalIsEquivalent() dirty-check tried to coerce the Expression
     * to int (view_count + application_count both have 'integer' casts)
     * and threw "Object of class Illuminate\Database\Query\Expression
     * could not be converted to int".
     */
    public function incrementCounters(array $cols): void
    {
        foreach ($cols as $col) {
            $this->increment($col);
        }
    }
}
