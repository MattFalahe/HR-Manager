<?php

namespace HrManager\Services;

use HrManager\Models\Application;
use HrManager\Models\ApplicationAnswer;
use HrManager\Models\ApplicationHandler;
use HrManager\Models\ApplicationStatusHistory;
use HrManager\Models\FormTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApplicationService
{
    /**
     * Valid state transitions.
     */
    private const TRANSITIONS = [
        'applied'      => ['under_review', 'rejected', 'withdrawn'],
        'under_review' => ['interview', 'accepted', 'rejected', 'withdrawn'],
        'interview'    => ['accepted', 'rejected', 'withdrawn', 'under_review'],
        'accepted'     => [],
        'rejected'     => [],
        'withdrawn'    => [],
    ];

    /**
     * Statuses that require director permission.
     */
    private const REQUIRES_DIRECTOR = ['accepted', 'rejected'];

    /**
     * Event-bus schema version for hr.application.* events.
     */
    private const EVENT_SCHEMA_VERSION = 1;

    private NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * Submit a new application.
     */
    public function submitApplication(int $characterId, int $templateId, ?int $corporationId, array $answers, ?int $submitterUserId = null): Application
    {
        // Require an authenticated submitter — never default to 0
        $userId = $submitterUserId ?? auth()->user()?->id;
        if (!$userId) {
            throw new \RuntimeException('Cannot submit application without an authenticated user context.');
        }

        $application = DB::transaction(function () use ($characterId, $templateId, $corporationId, $answers, $userId) {
            $application = Application::create([
                'character_id'   => $characterId,
                'template_id'    => $templateId,
                'corporation_id' => $corporationId,
                'status'         => 'applied',
                'submitted_at'   => now(),
                // Unguessable public-tracking slug for /recruit/track/{token}.
                // 48 chars of base62 → ~286 bits of entropy, well past the
                // collision threshold for a unique-indexed column.
                'tracking_token' => Str::random(48),
            ]);

            // Get template questions for snapshotting (fail if template doesn't exist)
            $template = FormTemplate::with('questions')->findOrFail($templateId);

            foreach ($template->questions as $question) {
                ApplicationAnswer::create([
                    'application_id' => $application->id,
                    'question_id'    => $question->id,
                    'question_text'  => $question->question_text,
                    'answer_text'    => $answers[$question->id] ?? null,
                ]);
            }

            // Record initial status
            ApplicationStatusHistory::create([
                'application_id' => $application->id,
                'old_status'     => null,
                'new_status'     => 'applied',
                'changed_by'     => $userId,
                'comment'        => 'Application submitted.',
            ]);

            return $application;
        });

        // Side effects fire OUTSIDE the transaction — a failed notification or
        // event publish must not roll back the application row.
        $this->safelyNotify(fn() => $this->notifications->notifyApplicationSubmitted($application));
        $this->publishApplicationEvent('hr.application.submitted', $application, null, null);

        // Mint a temporary `seat-connector.view` grant so the applicant can
        // reach the SeAT Connector identity page and link their Discord while
        // the application is open. No-ops when the feature is off or the
        // Connector isn't installed. Held until they join the corp (or the
        // application closes); see ApplicantConnectorAccessService.
        try {
            app(\HrManager\Services\ApplicantConnectorAccessService::class)
                ->grant($application, (int) $userId);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] connector access grant on submit failed: ' . $e->getMessage());
        }

        return $application;
    }

    /**
     * Update application status with validation.
     */
    public function updateStatus(Application $application, string $newStatus, int $userId, ?string $comment = null): bool
    {
        $oldStatus = null;

        $success = DB::transaction(function () use ($application, $newStatus, $userId, $comment, &$oldStatus) {
            // Lock the row to prevent concurrent status changes
            $locked = Application::lockForUpdate()->find($application->id);

            if (!$locked || !$this->canTransition($locked->status, $newStatus)) {
                return false;
            }

            $oldStatus = $locked->status;

            $locked->update([
                'status'      => $newStatus,
                'reviewed_at' => $newStatus === 'under_review' ? now() : $locked->reviewed_at,
                'decided_at'  => in_array($newStatus, ['accepted', 'rejected']) ? now() : $locked->decided_at,
                'decided_by'  => in_array($newStatus, ['accepted', 'rejected']) ? $userId : $locked->decided_by,
            ]);

            ApplicationStatusHistory::create([
                'application_id' => $locked->id,
                'old_status'     => $oldStatus,
                'new_status'     => $newStatus,
                'changed_by'     => $userId,
                'comment'        => $comment,
            ]);

            // Auto-track this user as a handler. Idempotent via the
            // (application_id, user_id) unique constraint — re-changing
            // status doesn't double-row. firstOrCreate keeps joined_at
            // as the first time they touched it, role_label sticky.
            // Snapshot their main_character_id for portrait rendering.
            $mainCharId = optional(\Seat\Web\Models\User::find($userId))->main_character_id;
            ApplicationHandler::firstOrCreate(
                [
                    'application_id' => $locked->id,
                    'user_id'        => $userId,
                ],
                [
                    'character_id' => $mainCharId,
                    'joined_at'    => now(),
                ]
            );

            // Mutate the caller's $application reference so post-transaction
            // side effects see the new status without an extra DB round trip.
            $application->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($success && $oldStatus !== null) {
            $this->safelyNotify(fn() => $this->notifications->notifyStatusChange($application, $oldStatus, $newStatus, $userId, $comment));
            $this->publishApplicationEvent($this->eventNameForStatus($newStatus), $application, $oldStatus, $comment);

            // Revoke every recruiter's temporary SeAT access to the
            // applicant's character data when the application closes
            // (accepted / rejected / withdrawn). Each recruiter's
            // attachment to the per-application role is detached
            // individually; the role itself is dropped when zero
            // recruiters remain. Other roles each recruiter has
            // (Director, etc.) are unaffected.
            if (in_array($newStatus, ['accepted', 'rejected', 'withdrawn'], true)) {
                try {
                    app(\HrManager\Services\ApplicantAccessService::class)
                        ->revokeAllForApplication(
                            (int) $application->id,
                            'application_' . $newStatus
                        );
                } catch (\Throwable $e) {
                    Log::warning('[HR Manager] access revoke on application close failed: ' . $e->getMessage());
                }
            }

            // The applicant's own temporary Connector-link grant is pulled
            // when the application closes WITHOUT a join (rejected / withdrawn).
            // On 'accepted' we KEEP it — they still need to link before the
            // in-game join; DetectCorpJoinsCommand revokes it once they
            // actually appear in the corp.
            if (in_array($newStatus, ['rejected', 'withdrawn'], true)) {
                try {
                    app(\HrManager\Services\ApplicantConnectorAccessService::class)
                        ->revokeForApplication(
                            (int) $application->id,
                            'application_' . $newStatus
                        );
                } catch (\Throwable $e) {
                    Log::warning('[HR Manager] connector access revoke on application close failed: ' . $e->getMessage());
                }
            }
        }

        return (bool) $success;
    }

    /**
     * Assign a recruiter to an application.
     */
    public function assignRecruiter(Application $application, int $recruiterId): void
    {
        $application->update(['assigned_recruiter' => $recruiterId]);
    }

    /**
     * Check if a transition is valid.
     */
    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? []);
    }

    /**
     * Get available next statuses for current status.
     */
    public function getAvailableStatuses(string $currentStatus): array
    {
        return self::TRANSITIONS[$currentStatus] ?? [];
    }

    /**
     * Check if transitioning to this status requires director permission.
     */
    public function requiresDirector(string $newStatus): bool
    {
        return in_array($newStatus, self::REQUIRES_DIRECTOR);
    }

    /**
     * Check if a character has a pending application.
     */
    public function hasPendingApplication(int $characterId): bool
    {
        return Application::where('character_id', $characterId)
            ->pending()
            ->exists();
    }

    /**
     * Map a destination status to a published-event name.
     */
    private function eventNameForStatus(string $status): string
    {
        return match ($status) {
            'accepted'  => 'hr.application.accepted',
            'rejected'  => 'hr.application.rejected',
            'withdrawn' => 'hr.application.withdrawn',
            default     => 'hr.application.status_changed',
        };
    }

    /**
     * Publish an event to Manager Core's EventBus when MC is installed.
     * Never throws — publish failure does not affect the calling write path.
     */
    private function publishApplicationEvent(string $eventName, Application $application, ?string $oldStatus, ?string $comment): void
    {
        // Topics::publish is the canonical publish path (registry lookup +
        // required-field validation + idempotency-template composition +
        // payload sanitization). It internally no-ops when MC is absent, so
        // the class_exists guard below is belt-and-suspenders for standalone.
        if (!class_exists('\\ManagerCore\\Topics')) {
            return;
        }

        try {
            // Enrich the payload with current handler user_ids so EventBus
            // subscribers (SeAT Broadcast etc.) can target the right
            // recruiters with DMs / mentions instead of broadcasting to
            // the whole corp recruitment channel.
            $handlerUserIds = ApplicationHandler::where('application_id', $application->id)
                ->pluck('user_id')
                ->map(fn($id) => (int) $id)
                ->all();

            $payload = [
                'source_plugin'   => 'hr-manager',
                'schema_version'  => self::EVENT_SCHEMA_VERSION,
                'event_id'        => 'hr-evt-' . Str::uuid()->toString(),
                'application_id'  => $application->id,
                'character_id'    => $application->character_id,
                'corporation_id'  => $application->corporation_id,
                'template_id'     => $application->template_id,
                'status'          => $application->status,
                'old_status'      => $oldStatus,
                'submitted_at'    => optional($application->submitted_at)->toIso8601String(),
                'decided_at'      => optional($application->decided_at)->toIso8601String(),
                'decided_by'      => $application->decided_by,
                'comment'         => $comment,
                'handler_user_ids' => $handlerUserIds,
            ];

            // Publisher attribution + idempotency key are resolved from the
            // topic registry entry (publisher => hr-manager), so we pass only
            // the topic name and payload here.
            \ManagerCore\Topics::publish($eventName, $payload);
        } catch (\Throwable $e) {
            Log::warning("[HR Manager] Topics publish failed for {$eventName}: " . $e->getMessage());
        }
    }

    /**
     * Run a notification side effect. Swallow + log any throw so a webhook
     * outage cannot roll back a submitted application.
     */
    private function safelyNotify(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] Notification dispatch failed: ' . $e->getMessage());
        }
    }
}
