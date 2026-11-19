<?php

namespace HrManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Who is in a corp right now, counted as people.
 *
 * The classifier picks its subjects by this, and the pages that count its
 * results narrow to it, so the two always agree: a classification shown is one
 * the nightly prune keeps. Classifications are written while somebody is a
 * member and only removed by that prune or by departure detection, so a read
 * by corporation alone counts people who have left in between, or forever in
 * a corp the prune never reaches.
 */
class CurrentMemberService
{
    private const CHUNK = 1000;

    /** @var array<int, array<int>|null> */
    private array $memo = [];

    /**
     * Accounts with a live token on a character SeAT places in this corp.
     *
     * An empty array is an answer: nobody registered is in the corp now, so
     * every classification it still has is stale. Null means SeAT's tables
     * are missing or could not be read, and the caller falls back to reading
     * by corporation rather than showing a corp with nobody in it.
     *
     * @return array<int>|null
     */
    public function userIds(int $corporationId): ?array
    {
        if (array_key_exists($corporationId, $this->memo)) {
            return $this->memo[$corporationId];
        }

        if (!Schema::hasTable('character_affiliations') || !Schema::hasTable('refresh_tokens')) {
            return $this->memo[$corporationId] = null;
        }

        try {
            $characterIds = DB::table('character_affiliations')
                ->where('corporation_id', $corporationId)
                ->pluck('character_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $userIds = [];
            foreach (array_chunk($characterIds, self::CHUNK) as $chunk) {
                $found = DB::table('refresh_tokens')
                    ->whereIn('character_id', $chunk)
                    ->whereNull('deleted_at')
                    ->pluck('user_id');
                foreach ($found as $userId) {
                    if ((int) $userId > 0) {
                        $userIds[(int) $userId] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] current members of corp ' . $corporationId . ' could not be read: ' . $e->getMessage());

            return $this->memo[$corporationId] = null;
        }

        $ids = array_keys($userIds);
        sort($ids);

        return $this->memo[$corporationId] = $ids;
    }
}
