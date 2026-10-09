<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Models\AdminConsoleRecord;
use App\Models\SignalReport;
use App\Models\Video;
use App\Models\VideoBoostCampaign;
use App\Services\KulCoinService;
use App\Services\PaymentService;
use App\Services\VideoBoostRefundService;
use App\Services\CountrySettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VideoBoostController extends Controller
{
    public function __construct(private readonly KulCoinService $kulcoins, private readonly PaymentService $payments, private readonly VideoBoostRefundService $refunds, private readonly CountrySettingsService $countrySettings) {}

    public function store(Request $request)
    {
        $creator = $request->user();
        $this->countrySettings->assertFeatureAllowed($creator, 'videoBoosting');
        abort_unless($creator->hasRole('creator'), 403);
        $config = AdminConsoleRecord::payloadFor('video-boosting');
        abort_unless((bool) data_get($config, 'enabled', false), 422, 'Video boosting is currently disabled.');
        if (data_get($config, 'creatorEligibility.verifiedOnly', true)) {
            abort_unless((bool) $creator->verified, 403, 'Only verified creators can boost videos.');
        }
        if (data_get($config, 'creatorEligibility.noActiveRestrictions', true)) {
            abort_unless(($creator->console_status ?? 'Active') === 'Active', 403, 'Your account has an active restriction.');
        }

        $data = $request->validate([
            'video_id' => ['required', 'integer', 'exists:videos,id'],
            'package_name' => ['required', 'string', 'max:80'],
            'payment_method' => ['required', Rule::in(data_get($config, 'paymentMethods', ['kulcoin', 'cash']))],
            'payment_channel' => ['required_if:payment_method,cash', Rule::in(['card', 'mobile_money'])],
            'provider' => ['required_if:payment_channel,mobile_money', Rule::in(config('paystack.mobile_money_providers', []))],
            'phone' => ['required_if:payment_channel,mobile_money', 'string', 'regex:/^\+?[0-9]{9,15}$/'],
            'placements' => ['required', 'array', 'min:1'],
            'placements.*' => ['required', Rule::in(data_get($config, 'placements', []))],
            'targeting' => ['sometimes', 'array'],
            'targeting.countries' => ['sometimes', 'array', 'max:100'],
            'targeting.countries.*' => ['string', 'max:100'],
            'targeting.regions' => ['sometimes', 'array', 'max:100'],
            'targeting.regions.*' => ['string', 'max:100'],
            'targeting.interests' => ['sometimes', 'array', 'max:100'],
            'targeting.interests.*' => ['string', 'max:100'],
        ]);
        $video = Video::query()->where('user_id', $creator->id)->findOrFail($data['video_id']);
        if (data_get($config, 'videoEligibility.publishedOnly', true)) {
            abort_unless($video->status === 'ready' && $video->visibility === 'public', 422, 'Only published public videos can be boosted.');
        }
        if (data_get($config, 'videoEligibility.excludeUnderReview', true)) {
            abort_unless(! in_array($video->status, ['under_review', 'pending_review'], true), 422, 'Videos under review cannot be boosted.');
        }
        if (data_get($config, 'videoEligibility.excludeReported', true)) {
            $hasOpenReport = SignalReport::query()->where('reportable_type', $video::class)->where('reportable_id', $video->id)
                ->whereIn('status', ['pending', 'open', 'under_review'])->exists();
            abort_unless(! $hasOpenReport, 422, 'Videos with unresolved reports cannot be boosted.');
        }

        $package = collect(data_get($config, 'packages', []))->first(fn ($item) => ($item['name'] ?? null) === $data['package_name'] && (bool) ($item['active'] ?? false));
        $countrySettings = $this->countrySettings->effective((string) $creator->country_code);
        $countryBoosting = data_get($countrySettings, 'boosting', []);
        abort_unless($package, 422, 'The selected boost package is unavailable.');
        $currency = $data['payment_method'] === 'kulcoin' ? 'Kulcoin' : 'GHS';
        $budget = $this->countrySettings->boostPackagePrice($countrySettings, $package, $currency);
        abort_if($budget <= 0, 422, 'This package is not available for the selected payment method.');
        $minimum = (float) data_get($config, $currency === 'GHS' ? 'budgetLimits.minimumGhs' : 'budgetLimits.minimumKulcoin', 0);
        $maximum = (float) data_get($config, $currency === 'GHS' ? 'budgetLimits.maximumGhs' : 'budgetLimits.maximumKulcoin', PHP_FLOAT_MAX);
        if ($currency === 'GHS') {
            $minimum = (float) data_get($countryBoosting, 'minimumBudgetGhs', $minimum);
            $maximum = (float) data_get($countryBoosting, 'maximumBudgetGhs', $maximum);
        }
        abort_if($budget < $minimum || $budget > $maximum, 422, 'This package price is outside the configured campaign budget limits for your country.');
        $durationDays = (int) ($package['durationDays'] ?? 1);
        $allowedDurations = data_get($countryBoosting, 'allowedDurations');
        abort_if(is_array($allowedDurations) && ! in_array($durationDays, array_map('intval', $allowedDurations), true), 422, 'This boost duration is unavailable in your country.');
        $allowedPlacements = data_get($countryBoosting, 'placements', data_get($config, 'placements', []));
        abort_if(array_diff($data['placements'], $allowedPlacements) !== [], 422, 'A selected placement is unavailable in your country.');
        $requireApproval = (bool) data_get($countryBoosting, 'requireApproval', data_get($config, 'requireApproval', true));

        $availableTargeting = data_get($config, 'targeting', []);
        foreach (['country' => 'country', 'region' => 'region', 'interests' => 'interests'] as $overrideKey => $targetKey) {
            $availableTargeting[$targetKey] = (bool) data_get($availableTargeting, $targetKey, false) && (bool) data_get($countryBoosting, 'targeting.'.$overrideKey, true);
        }
        $targeting = $data['targeting'] ?? [];
        foreach (['countries' => 'country', 'regions' => 'region', 'interests' => 'interests'] as $key => $enabledKey) {
            if (! (bool) data_get($availableTargeting, $enabledKey, false)) $targeting[$key] = [];
        }
        foreach (['countries', 'regions', 'interests'] as $field) $targeting[$field] = array_values(array_unique(array_map('strtolower', $targeting[$field] ?? [])));
        $targeting['durationDays'] = (int) ($package['durationDays'] ?? 1);
        $targeting['estimatedReach'] = (int) ($package['estimatedReach'] ?? 1);
        $targeting['placements'] = $data['placements'];
        $targeting['requireApproval'] = $requireApproval;
        $targeting['refundRules'] = data_get($countryBoosting, 'refundRules', data_get($config, 'refundRules', []));
        $targeting['packageSnapshot'] = [
            'name' => $package['name'], 'price' => $budget, 'currency' => $currency,
            'durationDays' => (int) ($package['durationDays'] ?? 1),
            'estimatedReach' => (int) ($package['estimatedReach'] ?? 1),
        ];

        $campaign = DB::transaction(function () use ($creator, $video, $package, $budget, $currency, $data, $targeting, $config, $requireApproval): VideoBoostCampaign {
            $campaign = VideoBoostCampaign::query()->create([
                'creator_id' => $creator->id, 'video_id' => $video->id,
                'package_name' => $package['name'], 'budget_amount' => $budget, 'currency' => $currency,
                'estimated_reach' => max(1, (int) ($package['estimatedReach'] ?? 1)), 'targeting' => $targeting,
                'payment_method' => $data['payment_method'],
                'status' => $data['payment_method'] === 'cash' ? 'pending_payment' : ($requireApproval ? 'pending' : 'active'),
                'starts_at' => $data['payment_method'] === 'kulcoin' && ! $requireApproval ? now() : null,
                'ends_at' => $data['payment_method'] === 'kulcoin' && ! $requireApproval ? now()->addDays(max(1, (int) ($package['durationDays'] ?? 1))) : null,
            ]);
            if ($data['payment_method'] === 'kulcoin') $this->kulcoins->chargeVideoBoost($creator, $campaign, (int) $budget);
            return $campaign;
        });

        if ($data['payment_method'] === 'cash') {
            try {
                $payment = $this->payments->initialize($creator, [
                    'purpose' => 'video_boost', 'method' => $data['payment_channel'],
                    'boost_campaign_id' => $campaign->id,
                    'provider' => $data['provider'] ?? null, 'phone' => $data['phone'] ?? null,
                    'idempotency_key' => 'video-boost-campaign:'.$campaign->id,
                ]);
            } catch (\Throwable $exception) {
                $campaign->forceFill(['status' => 'payment_failed'])->save();
                throw $exception;
            }
            $campaign->forceFill(['payment_reference' => $payment->reference])->save();
            return response()->json(['data' => [
                'campaign' => $this->serialize($campaign->fresh()),
                'payment' => ['id' => $payment->id, 'reference' => $payment->reference, 'status' => $payment->status,
                    'authorization_url' => $payment->provider_response['authorization_url'] ?? null,
                    'access_code' => $payment->provider_response['access_code'] ?? null],
            ]], 201);
        }

        return response()->json(['data' => ['campaign' => $this->serialize($campaign->fresh()), 'payment' => null]], 201);
    }

    public function cancel(Request $request, VideoBoostCampaign $campaign)
    {
        abort_unless((int) $campaign->creator_id === (int) $request->user()->id, 403);
        abort_unless(in_array($campaign->status, ['pending', 'active', 'paused', 'pending_payment', 'payment_failed'], true), 422, 'This campaign cannot be cancelled.');
        if ($campaign->status === 'pending_payment') {
            $paymentStatus = $campaign->payment_reference ? \App\Models\Payment::where('reference', $campaign->payment_reference)->value('status') : null;
            abort_unless(! $paymentStatus || in_array($paymentStatus, ['failed', 'cancelled'], true), 422, 'Finish or wait for the pending payment before cancelling.');
        }
        DB::transaction(function () use ($campaign): void {
            $campaign->forceFill(['status' => 'cancelled'])->save();
        });
        $refund = $this->refunds->refundUnused($campaign, 'cancelled', $request->user());
        return response()->json(['data' => ['campaign' => $this->serialize($campaign->fresh()), 'refunded' => $refund]]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasRole('creator'), 403);
        return response()->json(['data' => VideoBoostCampaign::query()->where('creator_id', $request->user()->id)->latest()->get()->map(fn ($campaign) => $this->serialize($campaign))]);
    }

    private function serialize(VideoBoostCampaign $campaign): array
    {
        $status = $campaign->status;
        if ($status === 'active' && (($campaign->ends_at && $campaign->ends_at->isPast()) || (float) $campaign->spent_amount >= (float) $campaign->budget_amount)) $status = 'completed';
        return ['id' => (string) $campaign->id, 'videoId' => (string) $campaign->video_id, 'package' => $campaign->package_name,
            'status' => $status, 'currency' => $campaign->currency, 'budget' => (float) $campaign->budget_amount,
            'spent' => (float) $campaign->spent_amount, 'remaining' => max(0, (float) $campaign->budget_amount - (float) $campaign->spent_amount),
            'impressions' => (int) $campaign->impressions_count, 'views' => (int) $campaign->views_count,
            'engagements' => (int) $campaign->engagements_count, 'rejectionReason' => $campaign->rejection_reason,
            'pauseReason' => $campaign->pause_reason, 'startsAt' => $campaign->starts_at?->toIso8601String(),
            'endsAt' => $campaign->ends_at?->toIso8601String()];
    }
}
