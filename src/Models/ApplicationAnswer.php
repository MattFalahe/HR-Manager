<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ApplicationAnswer extends Model
{
    protected $table = 'hr_manager_application_answers';

    protected $fillable = [
        'application_id',
        'question_id',
        'question_text',
        'answer_text',
    ];

    /**
     * answer_text is a TEXT column but checkbox-type questions submit
     * an HTML form array (multiple values for the same field). Without
     * this attribute the insert crashes with
     * "Array to string conversion" at the PDO bindValue layer.
     *
     * Mutator: arrays get JSON-encoded (after array_values() to drop
     *   any indexed-key HTML form weirdness). Scalars and null are
     *   stored as-is so existing pre-fix rows keep working.
     * Accessor: when the stored value looks like a JSON array (starts
     *   with '['), decode + join with ", " so the view renders a
     *   human-readable string ("Option A, Option B"). Plain scalars
     *   pass through unchanged.
     *
     * This keeps the only read site (applications/show.blade.php) as a
     * trivial echo and means recruiters never see raw JSON.
     */
    protected function answerText(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null || $value === '') {
                    return $value;
                }
                // Heuristic: only attempt JSON-decode when it looks like
                // a JSON array. Scalar text rows that happen to start
                // with '[' are protected by the strict json_decode check
                // (returns null on parse failure, falling back to raw).
                if (is_string($value) && str_starts_with($value, '[')) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        return implode(', ', array_map(fn($v) => (string) $v, $decoded));
                    }
                }
                return $value;
            },
            set: function ($value) {
                if (is_array($value)) {
                    return json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                return $value;
            },
        );
    }

    public function application()
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    /**
     * Return the answer as HTML-safe text with any http(s):// URLs
     * auto-linkified into <a> tags. Splits on a URL regex, e()-escapes
     * each non-URL segment, and wraps URL segments in target=_blank /
     * rel=noopener anchors. NEVER pass raw answer_text to {!! !!} —
     * applicants control the content, so XSS-safety hinges on the
     * escape pass inside this method.
     *
     * Empty / null answers return '-' so the view doesn't have to
     * branch.
     */
    public function renderedHtml(): string
    {
        $value = $this->answer_text;

        if ($value === null || $value === '') {
            return '-';
        }

        $value = (string) $value;

        // URL regex: http or https scheme, then up to whitespace or
        // common terminators. Liberal about path/query chars so common
        // zKill / Discord / forum URLs survive intact.
        $urlPattern = '~(https?://[^\s<>"\']+)~i';

        $parts = preg_split($urlPattern, $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || count($parts) === 1) {
            return nl2br(e($value));
        }

        $out = '';
        foreach ($parts as $i => $segment) {
            if ($i % 2 === 0) {
                // Even indices are non-URL text — escape + preserve newlines
                $out .= nl2br(e($segment));
            } else {
                // Odd indices are matched URLs — escape the URL itself for
                // attribute safety, render as a clickable anchor.
                $url = trim($segment, ".,;:)]}>'\""); // strip common trailing punctuation
                if ($url === '') {
                    $out .= e($segment);
                    continue;
                }
                $safeUrl = e($url);
                $out .= '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer" style="color: var(--hr-primary-start);">' . $safeUrl . '</a>';
                // Append any trailing punctuation we stripped, escaped
                $trailing = substr($segment, strlen($url));
                if ($trailing !== '' && $trailing !== false) {
                    $out .= e($trailing);
                }
            }
        }

        return $out;
    }
}
