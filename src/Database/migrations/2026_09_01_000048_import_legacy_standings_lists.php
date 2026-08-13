<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the four legacy hostile/friendly lists into the numeric standings table.
 *
 * Those settings (assess_hostile_alliances / _corps, assess_friendly_alliances /
 * _corps) were binary: an entity was on a list or it wasn't. Mapping hostile to
 * -10 and friendly to +10 rather than the milder -5 / +5 because typing an
 * entity into a list called "hostile" is a deliberate act, not a shrug — the
 * operator meant it, and softening their judgement on their behalf would be
 * the wrong default.
 *
 * The old settings are left in place, untouched. They are released settings and
 * this is a copy, not a move; nothing reads them once the service switches over,
 * and leaving them means a bad import can be re-run rather than reconstructed.
 * Existing rows are never overwritten, so re-running is safe.
 */
class ImportLegacyStandingsLists extends Migration
{
    private const MAP = [
        'assess_hostile_alliances'  => ['alliance',    -10],
        'assess_hostile_corps'      => ['corporation', -10],
        'assess_friendly_alliances' => ['alliance',     10],
        'assess_friendly_corps'     => ['corporation',  10],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('hr_manager_standings') || !Schema::hasTable('hr_manager_settings')) {
            return;
        }

        try {
            $imported = 0;

            foreach (self::MAP as $settingKey => [$type, $standing]) {
                foreach ($this->idsFromSetting($settingKey) as $entityId) {
                    // Never clobber a value someone has already set by hand.
                    $exists = DB::table('hr_manager_standings')
                        ->where('entity_type', $type)
                        ->where('entity_id', $entityId)
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    DB::table('hr_manager_standings')->insert([
                        'entity_id'   => $entityId,
                        'entity_type' => $type,
                        'standing'    => $standing,
                        'entity_name' => null, // resolved lazily by the UI
                        'notes'       => 'Imported from the legacy ' . $settingKey . ' list.',
                        'set_by'      => null,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                    $imported++;
                }
            }

            if ($imported > 0) {
                Log::info('[HR Manager] imported ' . $imported . ' legacy standings entries.');
            }
        } catch (\Throwable $e) {
            // A failed import must never block the migration run; the operator
            // can re-enter values by hand and the old settings still exist.
            Log::warning('[HR Manager] legacy standings import failed: ' . $e->getMessage());
        }
    }

    /**
     * Read one legacy list. Stored as a JSON array of ids by the settings
     * layer, but tolerate a comma/whitespace string too — these were free-text
     * textareas before they were parsed.
     *
     * @return array<int>
     */
    private function idsFromSetting(string $key): array
    {
        $raw = DB::table('hr_manager_settings')->where('key', $key)->value('value');
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $decoded = preg_split('/[\s,]+/', (string) $raw) ?: [];
        }

        $ids = [];
        foreach ($decoded as $v) {
            $v = (int) $v;
            if ($v > 0) {
                $ids[] = $v;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Forward-only: the legacy settings still exist, so nothing is lost. */
    public function down(): void
    {
        // no-op
    }
}
