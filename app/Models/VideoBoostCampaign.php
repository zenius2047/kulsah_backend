<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\AdminConsoleRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoBoostCampaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'budget_amount' => 'decimal:2',
            'spent_amount' => 'decimal:2',
            'impressions_count' => 'integer',
            'views_count' => 'integer',
            'engagements_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'targeting' => 'array',
            'refunded_at' => 'datetime',
        ];
    }

    public function recordImpression(?int $viewerId): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($viewerId): bool {
            $campaign = static::query()->lockForUpdate()->find($this->id);
            if (! $campaign || $campaign->status !== 'active' || (float) $campaign->spent_amount >= (float) $campaign->budget_amount) return false;
            $config = AdminConsoleRecord::payloadFor('video-boosting');
            if ($viewerId && \Illuminate\Support\Facades\Schema::hasTable('video_boost_impressions')) {
                $history = \Illuminate\Support\Facades\DB::table('video_boost_impressions')->where('campaign_id', $campaign->id)->where('viewer_id', $viewerId);
                if ($history->count() >= (int) data_get($config, 'deliveryLimits.maxImpressionsPerViewer', 3)) return false;
                if ($history->where('served_at', '>=', now()->subHours((int) data_get($config, 'deliveryLimits.cooldownHours', 24)))->exists()) return false;
                $history->insert(['campaign_id' => $campaign->id, 'viewer_id' => $viewerId, 'served_at' => now()]);
            }
            $campaign->impressions_count++;
            $campaign->spent_amount = min((float) $campaign->budget_amount, (float) $campaign->spent_amount + ((float) $campaign->budget_amount / max(1, (int) $campaign->estimated_reach)));
            $campaign->save();
            return true;
        });
    }

    public static function recordMetric(int $videoId, string $column): void
    {
        if (! in_array($column, ['views_count', 'engagements_count'], true)) return;

        static::query()->where('video_id', $videoId)->where('status', 'active')
            ->whereColumn('spent_amount', '<', 'budget_amount')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->increment($column);
    }

    public static function isVideoSponsored(int $videoId): bool
    {
        $config = AdminConsoleRecord::payloadFor('video-boosting');
        if (! (bool) data_get($config, 'enabled', false)) return false;

        return static::query()->where('video_id', $videoId)->where('status', 'active')
            ->whereColumn('spent_amount', '<', 'budget_amount')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->exists();
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}