<?php

namespace App\Jobs;

use App\Models\{AdminConsoleRecord, User};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SendAdminConsoleCampaign implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $campaignId) {}

    public function handle(): void
    {
        $campaign = AdminConsoleRecord::findOrFail($this->campaignId);
        $p = $campaign->payload;
        if ($p['status'] !== 'Sending') return;
        $users = User::where('console_status', 'Active');
        if ($p['audience'] === 'Creators') $users->whereHas('roles', fn ($q) => $q->where('name', 'creator'));
        if ($p['audience'] === 'Viewers') $users->whereDoesntHave('roles', fn ($q) => $q->where('name', 'creator'));
        $delivered = 0;
        $users->chunkById(200, function ($users) use ($p, &$delivered) {
            foreach ($users as $user) {
                // A deterministic UUID makes queue retries safe for every recipient.
                $id = (string) Str::uuid5(Str::NAMESPACE_DNS, 'kulsah-campaign-'.$this->campaignId.'-'.$user->id);
                DB::table('notifications')->insertOrIgnore(['id' => $id, 'type' => 'admin.campaign', 'notifiable_type' => User::class, 'notifiable_id' => $user->id,
                    'data' => json_encode(['type' => 'admin.campaign', 'title' => $p['title'], 'body' => $p['message'], 'campaign_id' => $this->campaignId]),
                    'created_at' => now(), 'updated_at' => now()]);
                $delivered++;
            }
        });
        $campaign->payload = [...$p, 'status' => 'Sent', 'delivered' => $delivered]; $campaign->save();
    }
}
