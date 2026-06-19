<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

class FormTemplateQuestion extends Model
{
    protected $table = 'hr_manager_form_template_questions';

    protected $fillable = [
        'template_id',
        'question_text',
        'question_type',
        'options',
        'is_required',
        'sort_order',
        'help_text',
        'placeholder',
        'validation_rules',
    ];

    protected $casts = [
        'options'     => 'array',
        'is_required' => 'boolean',
    ];

    public function template()
    {
        return $this->belongsTo(FormTemplate::class, 'template_id');
    }
}
