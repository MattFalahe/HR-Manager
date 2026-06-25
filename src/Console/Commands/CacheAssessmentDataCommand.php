<?php

namespace HrManager\Console\Commands;

use Illuminate\Console\Command;
use HrManager\Models\MemberAssessment;
use HrManager\Models\Setting;

class CacheAssessmentDataCommand extends Command
{
    protected $signature = 'hr-manager:cache-assessments
                            {--corporation_id= : Specific corporation ID to process}
                            {--force : Force refresh even if cache is fresh}';

    protected $description = 'Cache member assessment data from cross-plugin sources';

    public function handle(): int
    {
        $this->info('HR Manager: Caching assessment data...');

        $corporationId = $this->option('corporation_id');
        $force = $this->option('force');

        try {
            $service = app(\HrManager\Services\AssessmentService::class);

            $query = MemberAssessment::query();
            if ($corporationId) {
                $query->where('corporation_id', $corporationId);
            }

            if (!$force) {
                $query->where(function ($q) {
                    $cacheMinutes = (int) Setting::getValue('cache_duration', config('hr-manager.assessment.cache_duration', 60));
                    $q->whereNull('cached_at')
                      ->orWhere('cached_at', '<', now()->subMinutes($cacheMinutes));
                });
            }

            $stale = $query->get();
            $count = 0;

            foreach ($stale as $assessment) {
                // IMPORTANT: rebuild against the corporation_id this row was
                // originally created for. AssessmentService::refreshAssessment
                // looks up the character's CURRENT affiliation, which would
                // silently switch the refresh to a different corp if the
                // member moved — leaving the original row stale forever.
                $service->buildAssessment(
                    (int) $assessment->character_id,
                    $assessment->corporation_id !== null ? (int) $assessment->corporation_id : null
                );
                $count++;
            }

            $this->info("Refreshed {$count} assessment(s).");
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
