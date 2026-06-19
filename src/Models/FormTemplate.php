<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'hr_manager_form_templates';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_default',
        'is_active',
        'corporation_id',
        'created_by',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function questions()
    {
        return $this->hasMany(FormTemplateQuestion::class, 'template_id')->orderBy('sort_order');
    }

    public function applications()
    {
        return $this->hasMany(Application::class, 'template_id');
    }

    /**
     * True once any applicant has used this template. Counts soft-deleted
     * applications too: a withdrawn/deleted application still carries the
     * applicant's submitted answers, so the template is "in use" and its
     * questions are locked (edit them via Duplicate instead).
     */
    public function isInUse(): bool
    {
        return $this->applications()->withTrashed()->exists();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
