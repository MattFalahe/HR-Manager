<?php

namespace HrManager\Services;

use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\RefreshToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CharacterCheckService
{
    public function getCharacterSummary(int $characterId): array
    {
        $character = CharacterInfo::find($characterId);
        $affiliation = CharacterAffiliation::find($characterId);

        $employmentHistory = $character
            ? $this->employmentHistoryFor($character, $characterId)
            : collect();

        return [
            'character'          => $character,
            'affiliation'        => $affiliation,
            'employment_history' => $employmentHistory,
            'security_status'    => $character->security_status ?? null,
            'skill_points'       => $character->total_sp ?? null,
            'is_registered'      => $this->isRegisteredInSeat($characterId),
        ];
    }

    public function getEmploymentHistory(int $characterId): Collection
    {
        $character = CharacterInfo::find($characterId);

        if (!$character) {
            return collect();
        }

        return $this->employmentHistoryFor($character, $characterId);
    }

    public function isRegisteredInSeat(int $characterId): bool
    {
        return RefreshToken::where('character_id', $characterId)->exists();
    }

    public function getAffiliation(int $characterId): ?CharacterAffiliation
    {
        return CharacterAffiliation::find($characterId);
    }

    private function employmentHistoryFor(CharacterInfo $character, int $characterId): Collection
    {
        try {
            return $character->corporation_history()
                ->orderBy('record_id', 'desc')
                ->get();
        } catch (\Throwable $e) {
            Log::error('[HR Manager] Failed to load corporation_history for character', [
                'character_id' => $characterId,
                'error'        => $e->getMessage(),
            ]);
            return collect();
        }
    }
}
