<?php

namespace HrManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\Application;
use HrManager\Models\Note;
use HrManager\Models\Setting;

class NoteController extends Controller
{
    use ScopesCorporationAccess;

    /**
     * Whether notes may be marked private ("Enable Private Notes", Features
     * tab). When off, the per-note private flag is forced false so every note
     * is shared with all recruiters.
     */
    private function privateNotesEnabled(): bool
    {
        return (bool) Setting::getValue(
            'enable_private_notes',
            config('hr-manager.features.enable_private_notes', true)
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'noteable_type' => 'required|in:application,member',
            'noteable_id'   => 'required|integer',
            'content'       => 'required|string|max:5000',
            'is_private'    => 'nullable|boolean',
        ]);

        // Verify the user has access to the note's target (app's corp or member's corp)
        $this->assertCanAccessNoteable($request->noteable_type, (int) $request->noteable_id);

        $isPrivate = $this->privateNotesEnabled() && !empty($request->is_private);

        Note::create([
            'noteable_type' => $request->noteable_type,
            'noteable_id'   => $request->noteable_id,
            'author_id'     => auth()->user()->id,
            'content'       => $request->content,
            'is_private'    => $isPrivate,
        ]);

        // Tell the application's OTHER handlers. Public application notes only:
        // a private note is a recruiter thinking aloud, and pinging a co-handler
        // about text they may not be permitted to read is worse than silence.
        if (!$isPrivate && $request->noteable_type === 'application') {
            $this->notifyHandlersOfNote((int) $request->noteable_id, (string) $request->content);
        }

        return redirect()->back()->with('success', trans('hr-manager::notes.note_created'));
    }

    public function update(Request $request, int $id)
    {
        $note = Note::findOrFail($id);

        // Only author can edit their notes
        if ($note->author_id !== auth()->user()->id) {
            abort(403);
        }

        $request->validate([
            'content'    => 'required|string|max:5000',
            'is_private' => 'nullable|boolean',
        ]);

        $note->update([
            'content'    => $request->content,
            'is_private' => $this->privateNotesEnabled() && !empty($request->is_private),
        ]);

        return redirect()->back()->with('success', trans('hr-manager::notes.note_updated'));
    }

    public function destroy(int $id)
    {
        $note = Note::findOrFail($id);

        // Only author can delete their notes
        if ($note->author_id !== auth()->user()->id) {
            abort(403);
        }

        $note->delete();

        return redirect()->back()->with('success', trans('hr-manager::notes.note_deleted'));
    }

    /**
     * Verify user has corp access to the note target.
     */
    private function assertCanAccessNoteable(string $type, int $id): void
    {
        if ($type === 'application') {
            $application = Application::find($id);
            if (!$application) {
                abort(404, 'Application not found.');
            }
            $this->assertCanAccessCorp($application->corporation_id);
        } elseif ($type === 'member') {
            // For member notes, noteable_id is the EVE character_id
            $this->assertCanAccessCharacter($id);
        }
    }

    /**
     * Ping the application's handlers about a new public note. Best-effort:
     * a notification failure must never cost the operator the note they just
     * wrote, so everything here is wrapped and swallowed.
     */
    private function notifyHandlersOfNote(int $applicationId, string $content): void
    {
        try {
            $application = Application::find($applicationId);
            if (!$application) {
                return;
            }

            $handlerIds = \HrManager\Models\ApplicationHandler::where('application_id', $applicationId)
                ->pluck('user_id')
                ->map(fn ($u) => (int) $u)
                ->filter()
                ->unique()
                ->values()
                ->all();

            // One handler (or none) means the only person to tell is the one
            // who just wrote it — notifyHandlerNote() drops those anyway, but
            // there's no reason to build the payload.
            if (count($handlerIds) < 2) {
                return;
            }

            app(\HrManager\Services\NotificationService::class)->notifyHandlerNote(
                $application,
                $handlerIds,
                (int) auth()->user()->id,
                $content
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] handler-note notification failed: ' . $e->getMessage());
        }
    }
}
