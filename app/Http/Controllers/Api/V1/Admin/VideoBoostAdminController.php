<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\AdminConsoleDataChanged;
use App\Http\Controllers\Controller;
use App\Models\AdminConsoleAudit;
use App\Models\AdminConsoleRecord;
use App\Models\VideoBoostCampaign;
use App\Services\AdminConsoleAccess;
use App\Services\VideoBoostRefundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VideoBoostAdminController extends Controller
{
    public function __construct(private readonly AdminConsoleAccess $access, private readonly VideoBoostRefundService $refunds) {}

    private function defaults(): array
    {
        return [
            'enabled' => false,
            'creatorEligibility' => ['verifiedOnly' => true, 'noActiveRestrictions' => true],
            'videoEligibility' => ['publishedOnly' => true, 'excludeUnderReview' => true, 'excludeReported' => true],
            'packages' => [],
            'paymentMethods' => ['kulcoin', 'cash'],
            'budgetLimits' => ['minimumGhs' => 10, 'maximumGhs' => 10000, 'minimumKulcoin' => 100, 'maximumKulcoin' => 100000],
            'placements' => ['for_you', 'discover'],
            'targeting' => ['country' => true, 'region' => false, 'interests' => false],
            'deliveryLimits' => ['maxImpressionsPerViewer' => 3, 'cooldownHours' => 24],
            'requireApproval' => true,
            'refundRules' => ['rejected' => 'full_unused', 'cancelled' => 'prorated_unused', 'stopped' => 'prorated_unused'],
        ];
    }

    public function config(Request $request)
    {
        $actor = $request->user();
        $this->access->authorize($actor, 'finance.view');
        $record = AdminConsoleRecord::where('resource', 'video-boosting')->first();
        $rolePermissions = $this->access->permissions($this->access->role($actor));

        return response()->json(['data' => [
            'configuration' => array_replace_recursive($this->defaults(), $record?->payload ?? []),
            'canManage' => in_array('finance.rules.manage', $rolePermissions, true),
        ]]);
    }

    public function saveConfig(Request $request)
    {
        $actor = $request->user();
        $this->access->authorize($actor, 'finance.rules.manage');
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'creatorEligibility' => ['required', 'array'],
            'creatorEligibility.verifiedOnly' => ['required', 'boolean'],
            'creatorEligibility.noActiveRestrictions' => ['required', 'boolean'],
            'videoEligibility' => ['required', 'array'],
            'videoEligibility.publishedOnly' => ['required', 'boolean'],
            'videoEligibility.excludeUnderReview' => ['required', 'boolean'],
            'videoEligibility.excludeReported' => ['required', 'boolean'],
            'packages' => ['present', 'array', 'max:20'],
            'packages.*.name' => ['required', 'string', 'max:80'],
            'packages.*.priceGhs' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
            'packages.*.priceKulcoin' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'packages.*.durationDays' => ['required', 'integer', 'min:1', 'max:365'],
            'packages.*.estimatedReach' => ['required', 'integer', 'min:1', 'max:100000000'],
            'packages.*.active' => ['required', 'boolean'],
            'paymentMethods' => ['required', 'array', 'min:1'],
            'paymentMethods.*' => ['required', Rule::in(['kulcoin', 'cash'])],
            'budgetLimits' => ['required', 'array'],
            'budgetLimits.minimumGhs' => ['required', 'numeric', 'min:0.01'],
            'budgetLimits.maximumGhs' => ['required', 'numeric', 'gte:budgetLimits.minimumGhs'],
            'budgetLimits.minimumKulcoin' => ['required', 'integer', 'min:1'],
            'budgetLimits.maximumKulcoin' => ['required', 'integer', 'gte:budgetLimits.minimumKulcoin'],
            'placements' => ['required', 'array', 'min:1'],
            'placements.*' => ['required', Rule::in(['for_you', 'discover'])],
            'targeting' => ['required', 'array'],
            'targeting.country' => ['required', 'boolean'],
            'targeting.region' => ['required', 'boolean'],
            'targeting.interests' => ['required', 'boolean'],
            'deliveryLimits' => ['required', 'array'],
            'deliveryLimits.maxImpressionsPerViewer' => ['required', 'integer', 'min:1', 'max:100'],
            'deliveryLimits.cooldownHours' => ['required', 'integer', 'min:1', 'max:8760'],
            'requireApproval' => ['required', 'boolean'],
            'refundRules' => ['required', 'array'],
            'refundRules.rejected' => ['required', Rule::in(['full_unused', 'no_refund'])],
            'refundRules.cancelled' => ['required', Rule::in(['full_unused', 'prorated_unused', 'no_refund'])],
            'refundRules.stopped' => ['required', Rule::in(['full_unused', 'prorated_unused', 'no_refund'])],
        ]);
        foreach ($data['packages'] as $index => $package) {
            abort_if(empty($package['priceGhs']) && empty($package['priceKulcoin']), 422, "Package ".($index + 1)." needs a GH₵ or Kulcoin price.");
        }

        $saved = DB::transaction(function () use ($data, $actor, $request) {
            $record = AdminConsoleRecord::where('resource', 'video-boosting')->lockForUpdate()->first();
            $before = $record?->payload ?? $this->defaults();
            if (! $record) {
                $record = new AdminConsoleRecord(['resource' => 'video-boosting', 'created_by' => $actor->id]);
            }
            $record->payload = $data;
            $record->save();
            AdminConsoleAudit::create([
                'admin_id' => $actor->id, 'action' => 'update', 'entity' => 'video-boosting',
                'entity_id' => (string) $record->id, 'previous_value' => $before, 'new_value' => $data,
                'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 2000),
            ]);
            return $data;
        });

        return response()->json(['data' => $saved]);
    }

    public function campaigns(Request $request)
    {
        $this->access->authorize($request->user(), 'content.view');
        $campaigns = VideoBoostCampaign::with(['creator:id,name', 'video:id,title'])
            ->latest()->paginate(min(200, max(1, $request->integer('per_page', 100))));
        $items = $campaigns->getCollection()->map(fn (VideoBoostCampaign $campaign) => [
            'id' => (string) $campaign->id,
            'creator' => $campaign->creator?->name ?? 'Deleted creator',
            'video' => $campaign->video?->title ?? 'Deleted video',
            'package' => $campaign->package_name,
            'budget' => (float) $campaign->budget_amount,
            'currency' => $campaign->currency,
            'spent' => (float) $campaign->spent_amount,
            'refunded' => (float) $campaign->refunded_amount,
            'paymentMethod' => $campaign->payment_method,
            'targeting' => $campaign->targeting ?? [],
            'remaining' => max(0, (float) $campaign->budget_amount - (float) $campaign->spent_amount),
            'impressions' => (int) $campaign->impressions_count,
            'views' => (int) $campaign->views_count,
            'engagements' => (int) $campaign->engagements_count,
            'status' => $campaign->status === 'active' && (($campaign->ends_at && $campaign->ends_at->isPast()) || (float) $campaign->spent_amount >= (float) $campaign->budget_amount) ? 'completed' : $campaign->status,
            'rejectionReason' => $campaign->rejection_reason,
            'pauseReason' => $campaign->pause_reason,
            'startsAt' => $campaign->starts_at?->toIso8601String(),
            'endsAt' => $campaign->ends_at?->toIso8601String(),
            'createdAt' => $campaign->created_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $items, 'meta' => [
            'current_page' => $campaigns->currentPage(), 'last_page' => $campaigns->lastPage(), 'total' => $campaigns->total(),
        ]]);
    }

    public function myCampaigns(Request $request)
    {
        abort_unless($request->user()->hasRole('creator'), 403);
        $campaigns = VideoBoostCampaign::query()->where('creator_id', $request->user()->id)->latest()->get()->map(fn (VideoBoostCampaign $campaign) => [
            'id' => (string) $campaign->id, 'videoId' => (string) $campaign->video_id,
            'status' => $campaign->status === 'active' && (($campaign->ends_at && $campaign->ends_at->isPast()) || (float) $campaign->spent_amount >= (float) $campaign->budget_amount) ? 'completed' : $campaign->status, 'rejectionReason' => $campaign->rejection_reason,
            'pauseReason' => $campaign->pause_reason, 'budget' => (float) $campaign->budget_amount,
            'currency' => $campaign->currency, 'spent' => (float) $campaign->spent_amount,
            'remaining' => max(0, (float) $campaign->budget_amount - (float) $campaign->spent_amount),
            'impressions' => (int) $campaign->impressions_count, 'views' => (int) $campaign->views_count,
            'engagements' => (int) $campaign->engagements_count,
            'startsAt' => $campaign->starts_at?->toIso8601String(), 'endsAt' => $campaign->ends_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $campaigns]);
    }
    public function act(Request $request, VideoBoostCampaign $campaign, string $action)
    {
        abort_unless(in_array($action, ['approve', 'reject', 'pause', 'stop'], true), 404);
        $actor = $request->user();
        $permission = in_array($action, ['approve', 'reject'], true) ? 'content.approve' : 'content.edit';
        $this->access->authorize($actor, $permission);
        $data = $request->validate(['reason' => [$action === 'reject' || $action === 'pause' ? 'required' : 'nullable', 'string', 'min:5', 'max:1000']]);

        DB::transaction(function () use ($campaign, $action, $data, $actor, $request): void {
            $campaign = VideoBoostCampaign::lockForUpdate()->findOrFail($campaign->id);
            $before = $campaign->only(['status', 'rejection_reason', 'pause_reason']);
            if ($action === 'approve') {
                abort_unless($campaign->status === 'pending', 422, 'Only pending campaigns can be approved.');
                $campaign->status = 'active';
                $campaign->starts_at ??= now();
                $campaign->ends_at ??= $campaign->starts_at->copy()->addDays(max(1, (int) data_get($campaign->targeting ?? [], 'durationDays', 1)));
            } elseif ($action === 'reject') {
                abort_unless($campaign->status === 'pending', 422, 'Only pending campaigns can be rejected.');
                $campaign->status = 'rejected';
                $campaign->rejection_reason = $data['reason'];
            } elseif ($action === 'pause') {
                abort_unless($campaign->status === 'active', 422, 'Only active campaigns can be paused.');
                $campaign->status = 'paused';
                $campaign->pause_reason = $data['reason'];
            } else {
                abort_unless(in_array($campaign->status, ['pending', 'active', 'paused'], true), 422, 'This campaign cannot be stopped.');
                $campaign->status = 'stopped';
            }
            $campaign->save();
            AdminConsoleAudit::create([
                'admin_id' => $actor->id, 'action' => $action, 'entity' => 'video-boost-campaign',
                'entity_id' => (string) $campaign->id, 'previous_value' => $before,
                'new_value' => $campaign->only(['status', 'rejection_reason', 'pause_reason']),
                'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 2000),
            ]);
            if ($action === 'reject') $this->refunds->refundUnused($campaign, 'rejected', $actor);
            if ($action === 'stop') $this->refunds->refundUnused($campaign, 'stopped', $actor);
            event(new AdminConsoleDataChanged('video-boost-campaigns', $action, (string) $actor->id));
        });

        return response()->json(['data' => ['id' => (string) $campaign->id, 'status' => $campaign->fresh()->status]]);
    }
}