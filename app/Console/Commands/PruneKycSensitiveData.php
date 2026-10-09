<?php

namespace App\Console\Commands;

use App\Models\KycApplication;
use App\Services\CountrySettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneKycSensitiveData extends Command
{
    protected $signature = 'kyc:prune-sensitive-data';
    protected $description = 'Remove KYC identity data and private documents after the configured retention period';

    public function handle(CountrySettingsService $countries): int
    {
        KycApplication::query()
            ->whereIn('status', ['verified', 'rejected', 'expired'])
            ->whereNotNull('submitted_at')
            ->orderBy('id')
            ->chunkById(100, function ($applications) use ($countries): void {
                foreach ($applications as $application) {
                    $days = data_get($countries->effective($application->country_code), 'verification.kyc.retentionDays');
                    if (!is_numeric($days) || (int) $days < 1 || $application->submitted_at->gt(now()->subDays((int) $days))) continue;

                    foreach ($application->documents as $document) {
                        Storage::disk('local')->delete($document->private_path);
                        $document->delete();
                    }
                    $application->applicant_data = [];
                    $application->provider_result = null;
                    $application->creator_message = null;
                    $application->save();
                    $application->notes()->delete();
                    $application->events()->update(['creator_message' => null]);
                }
            });

        return self::SUCCESS;
    }
}
