<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\AdminConsoleDataChanged;
use App\Http\Controllers\Controller;
use App\Models\AdminConsoleAudit;
use App\Models\AdminConsoleRecord;
use App\Services\AdminConsoleAccess;
use App\Services\CountrySettingsService;
use App\Services\FeedService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CountrySettingsController extends Controller
{
    public function __construct(private readonly AdminConsoleAccess $access, private readonly CountrySettingsService $countries, private readonly FeedService $feeds) {}

    public function index(Request $request)
    {
        $this->access->authorize($request->user(), 'settings.view');
        return response()->json(['data' => $this->countries->snapshot()]);
    }

    public function save(Request $request)
    {
        $actor = $request->user();
        $this->access->authorize($actor, 'settings.edit');
        $before = AdminConsoleRecord::payloadFor('country-settings');
        $requestGlobalKyc = data_get($request->input('globalDefaults', []), 'verification', []);
        $savedGlobalKyc = data_get($before, 'globalDefaults.verification', []);
        $requestOverrides = $request->input('countryOverrides', []);
        $savedOverrides = data_get($before, 'countryOverrides', []);
        $hasCountryKycChanges = $requestGlobalKyc !== $savedGlobalKyc;
        foreach (array_unique(array_merge(array_keys($requestOverrides), array_keys($savedOverrides))) as $code) {
            if (data_get($requestOverrides, $code.'.verification', []) !== data_get($savedOverrides, $code.'.verification', [])) $hasCountryKycChanges = true;
        }
        if ($hasCountryKycChanges) $this->access->authorize($actor, 'kyc.config');
        $input = $request->validate([
            'enforcementEnabled' => ['required', 'boolean'],
            'globalDefaults' => ['present', 'array'],
            'countryOverrides' => ['present', 'array'],
            'countryOverrides.*' => ['array'],
        ]);
        $validCodes = array_column($this->countries->catalog(), 'code');
        $canonicalOverrides = [];
        foreach ($input['countryOverrides'] as $code => $override) {
            $normalizedCode = strtoupper($code);
            if (! in_array($normalizedCode, $validCodes, true)) throw ValidationException::withMessages(['countryOverrides' => "Unknown country code {$code}."]);
            $canonicalOverrides[$normalizedCode] = $override;
        }
        $input['countryOverrides'] = $canonicalOverrides;
        $boostPackageKeys = array_values(array_filter(array_column(data_get(AdminConsoleRecord::payloadFor('video-boosting'), 'packages', []), 'name')));
        $boostPackageKeys = array_map(fn ($name) => $this->countries->boostPackageKey((string) $name), $boostPackageKeys);
        $this->validateSettings($input['globalDefaults'], 'globalDefaults', $boostPackageKeys);
        foreach ($input['countryOverrides'] as $code => $override) $this->validateSettings($override, "countryOverrides.{$code}", $boostPackageKeys);
        foreach ($validCodes as $code) {
            $effective = $this->countries->resolve($input['globalDefaults'], $input['countryOverrides'][$code] ?? []);
            $this->validateSettings($effective, "resolved.{$code}", $boostPackageKeys);
            $readiness = $this->countries->readiness($effective, $code);
            if (data_get($effective, 'availability.available') === true && ! $readiness['ready']) {
                throw ValidationException::withMessages(["countryOverrides.{$code}" => 'Country cannot be enabled yet: '.implode(', ', $readiness['missing']).'.']);
            }
        }

        $record = DB::transaction(function () use ($input, $actor, $request) {
            $record = AdminConsoleRecord::query()->where('resource', 'country-settings')->lockForUpdate()->first();
            $before = $record?->payload ?? [];
            $payload = ['enforcementEnabled' => $input['enforcementEnabled'], 'globalDefaults' => $input['globalDefaults'], 'countryOverrides' => $input['countryOverrides']];
            if (! $record) $record = new AdminConsoleRecord(['resource' => 'country-settings', 'created_by' => $actor->id]);
            $record->payload = $payload;
            $record->save();
            AdminConsoleAudit::query()->create([
                'admin_id' => $actor->id, 'action' => 'update', 'entity' => 'country-settings', 'entity_id' => 'global-and-country-overrides',
                'previous_value' => $before, 'new_value' => $payload, 'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 2000),
            ]);
            return $payload;
        });
        $this->countries->clearCache();
        $this->feeds->invalidateFeedCaches();
        event(new AdminConsoleDataChanged('country-settings', 'update', (string) $actor->id));
        return response()->json(['data' => ['settings' => $record, 'snapshot' => $this->countries->snapshot()]]);
    }

    public function preview(Request $request, string $code)
    {
        $this->access->authorize($request->user(), 'settings.view');
        abort_unless(in_array(strtoupper($code), array_column($this->countries->catalog(), 'code'), true), 404);
        $effective = $this->countries->effective(strtoupper($code));
        return response()->json(['data' => ['code' => strtoupper($code), 'effective' => $effective, 'readiness' => $this->countries->readiness($effective, strtoupper($code))]]);
    }

    private function validateSettings(array $settings, string $prefix, array $boostPackageKeys): void
    {
        $validator = Validator::make($settings, [
            'availability.available' => ['sometimes', 'boolean'], 'availability.registrations' => ['sometimes', 'boolean'],
            'availability.monetization' => ['sometimes', 'boolean'],
            'localization.defaultCurrency' => ['sometimes', 'nullable', Rule::in(['GHS'])],
            'localization.supportedCurrencies' => ['sometimes', 'array'], 'localization.supportedCurrencies.*' => [Rule::in(array_column($this->countries->supportedDisplayCurrencies(), 'code'))],
            'localization.displayFormat' => ['sometimes', 'nullable', 'string', 'max:80'],
            'localization.defaultLanguage' => ['sometimes', 'nullable', 'string', 'max:20'],
            'localization.supportedLanguages' => ['sometimes', 'array'], 'localization.supportedLanguages.*' => ['string', 'max:20'],
            'localization.timezones' => ['sometimes', 'array'], 'localization.timezones.*' => ['timezone'],
            'localization.defaultTimezone' => ['sometimes', 'nullable', 'timezone'],
            'payments.providers' => ['sometimes', 'array'], 'payments.providers.*' => [Rule::in(['paystack'])],
            'payments.methods' => ['sometimes', 'array'], 'payments.methods.*' => [Rule::in(['card', 'mobile_money'])],
            'payments.currencies' => ['sometimes', 'array'], 'payments.currencies.*' => [Rule::in(['GHS'])],
            'payouts.providers' => ['sometimes', 'array'], 'payouts.providers.*' => [Rule::in(['manual_wallet_review'])],
            'payouts.destinations' => ['sometimes', 'array'], 'payouts.destinations.*' => ['string', 'max:60'],
            'payouts.minimum' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'payouts.maximum' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payouts.currency' => ['sometimes', 'nullable', Rule::in(['GHS'])], 'payouts.feeType' => ['sometimes', 'nullable', Rule::in(['none', 'fixed', 'percentage'])],
            'payouts.feeAmount' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'payouts.schedule' => ['sometimes', 'nullable', 'string', 'max:100'],
            'payouts.verificationRequired' => ['sometimes', 'boolean'],
            'pricing.subscriptionMinimum' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'pricing.subscriptionMaximum' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'pricing.eventTicketMinimum' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'pricing.eventTicketMaximum' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'boosting.packagePrices' => ['sometimes', 'array'],
            'boosting.packagePrices.*.priceGhs' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:1000000'],
            'boosting.packagePrices.*.priceKulcoin' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'boosting.packagePrices.*.effectiveAt' => ['sometimes', 'nullable', 'date'],
            'boosting.refundRules' => ['sometimes', 'array'],
            'boosting.refundRules.rejected' => ['sometimes', Rule::in(['full_unused', 'no_refund'])],
            'boosting.refundRules.cancelled' => ['sometimes', Rule::in(['full_unused', 'prorated_unused', 'no_refund'])],
            'boosting.refundRules.stopped' => ['sometimes', Rule::in(['full_unused', 'prorated_unused', 'no_refund'])],
            'boosting.minimumBudgetGhs' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'boosting.maximumBudgetGhs' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'boosting.allowedDurations' => ['sometimes', 'array'], 'boosting.allowedDurations.*' => ['integer', 'min:1', 'max:365'],
            'boosting.placements' => ['sometimes', 'array'], 'boosting.placements.*' => [Rule::in(['for_you', 'discover'])],
            'boosting.requireApproval' => ['sometimes', 'boolean'],
            'boosting.deliveryLimits.maxImpressionsPerViewer' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'boosting.deliveryLimits.cooldownHours' => ['sometimes', 'integer', 'min:1', 'max:8760'],
            'boosting.targeting.country' => ['sometimes', 'boolean'],
            'boosting.targeting.region' => ['sometimes', 'boolean'], 'boosting.targeting.interests' => ['sometimes', 'boolean'],
            'monetizationFeatures.*' => ['boolean'],
            'verification.identityDocumentTypes' => ['sometimes', 'array'], 'verification.identityDocumentTypes.*' => ['string', 'max:80'],
            'verification.monetizationRequired' => ['sometimes', 'boolean'], 'verification.withdrawalRequired' => ['sometimes', 'boolean'],
            'verification.kyc' => ['sometimes', 'array'],
            'verification.kyc.acceptedDocuments' => ['sometimes', 'array'],
            'verification.kyc.acceptedDocuments.individual' => ['sometimes', 'array'],
            'verification.kyc.acceptedDocuments.individual.*' => [Rule::in(['ghana_card', 'passport', 'national_id', 'drivers_license', 'residence_permit'])],
            'verification.kyc.acceptedDocuments.business' => ['sometimes', 'array'],
            'verification.kyc.acceptedDocuments.business.*' => [Rule::in(['business_registration', 'authorized_representative_id', 'proof_of_authorization', 'beneficial_ownership'])],
            'verification.kyc.requiredCaptures' => ['sometimes', 'array'],
            'verification.kyc.requiredCaptures.individual' => ['sometimes', 'array'],
            'verification.kyc.requiredCaptures.individual.*' => [Rule::in(['front', 'back', 'passport_photo_page'])],
            'verification.kyc.requiredCaptures.business' => ['sometimes', 'array'],
            'verification.kyc.requiredCaptures.business.*' => [Rule::in(['business_registration', 'authorized_representative_id', 'authorization', 'ownership'])],
            'verification.kyc.documentCaptures' => ['sometimes', 'array'],
            'verification.kyc.documentCaptures.individual' => ['sometimes', 'array'],
            'verification.kyc.documentCaptures.individual.*' => ['array'],
            'verification.kyc.documentCaptures.individual.*.*' => [Rule::in(['front', 'back', 'passport_photo_page'])],
            'verification.kyc.documentCaptures.business' => ['sometimes', 'array'],
            'verification.kyc.documentCaptures.business.*' => ['array'],
            'verification.kyc.documentCaptures.business.*.*' => [Rule::in(['business_registration', 'authorized_representative_id', 'authorization', 'ownership'])],
            'verification.kyc.selfieRequired' => ['sometimes', 'boolean'],
            'verification.kyc.documentExpiryRequired' => ['sometimes', 'boolean'],
            'verification.kyc.manualReviewRequired' => ['sometimes', 'boolean'],
            'verification.kyc.payoutOwnershipRequired' => ['sometimes', 'boolean'],
            'verification.kyc.retentionDays' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'age.accountMinimum' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'], 'age.monetizationMinimum' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'], 'age.featureMinimums' => ['sometimes', 'array'], 'age.featureMinimums.*' => ['nullable', 'integer', 'min:0', 'max:120'],
            'otp.routes' => ['sometimes', 'array'], 'otp.routes.*' => [Rule::in(['email', 'sms'])],
            'otp.perCountryLimits' => ['sometimes', 'array'], 'otp.perCountryLimits.perHour' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'otp.perCountryLimits.perDay' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'notifications.defaultLanguage' => ['sometimes', 'nullable', 'string', 'max:20'],
            'notifications.fallbackLanguage' => ['sometimes', 'nullable', 'string', 'max:20'],
            'support.contactEmail' => ['sometimes', 'nullable', 'email'], 'support.moderationQueue' => ['sometimes', 'nullable', 'string', 'max:100'],
            'taxLegal.taxStatus' => ['sometimes', 'nullable', Rule::in(['configured', 'not_applicable'])],
            'taxLegal.priceMode' => ['sometimes', 'nullable', Rule::in(['inclusive', 'exclusive'])],
            'taxLegal.invoiceInformation' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'taxLegal.termsVersion' => ['sometimes', 'nullable', 'string', 'max:100'], 'taxLegal.privacyVersion' => ['sometimes', 'nullable', 'string', 'max:100'],
            'taxLegal.consentVersion' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) throw ValidationException::withMessages(collect($validator->errors()->toArray())->mapWithKeys(fn ($messages, $key) => [$prefix.'.'.$key => $messages])->all());
        foreach (array_keys(data_get($settings, 'boosting.packagePrices', [])) as $packageKey) {
            if (! in_array($packageKey, $boostPackageKeys, true)) throw ValidationException::withMessages([$prefix.'.boosting.packagePrices' => "Unknown boost package key {$packageKey}. Configure the package in Video Boosting first."]);
        }
        if (data_get($settings, 'monetizationFeatures.creatorStores') === true) throw ValidationException::withMessages([$prefix.'.monetizationFeatures.creatorStores' => 'Creator stores are not available because no integration is implemented.']);
        $defaultTimezone = data_get($settings, 'localization.defaultTimezone');
        $timezones = data_get($settings, 'localization.timezones', []);
        if ($defaultTimezone && is_array($timezones) && $timezones !== [] && ! in_array($defaultTimezone, $timezones, true)) {
            throw ValidationException::withMessages([$prefix.'.localization.defaultTimezone' => 'Default timezone must be included in the supported timezone list.']);
        }
        foreach ([
            'pricing.subscriptionMinimum', 'pricing.subscriptionMaximum', 'pricing.eventTicketMinimum', 'pricing.eventTicketMaximum',
            'payouts.minimum', 'payouts.maximum', 'payouts.feeAmount', 'boosting.minimumBudgetGhs', 'boosting.maximumBudgetGhs',
        ] as $moneyPath) {
            $amount = data_get($settings, $moneyPath);
            if ($amount !== null && is_numeric($amount) && abs((float) $amount - round((float) $amount, 2)) > 0.000001) {
                throw ValidationException::withMessages([$prefix.'.'.$moneyPath => 'GHS monetary values may have no more than two decimal places.']);
            }
        }
        foreach (data_get($settings, 'boosting.packagePrices', []) as $packageKey => $prices) {
            $price = data_get($prices, 'priceGhs');
            if ($price !== null && abs((float) $price - round((float) $price, 2)) > 0.000001) {
                throw ValidationException::withMessages([$prefix.'.boosting.packagePrices.'.$packageKey.'.priceGhs' => 'GHS monetary values may have no more than two decimal places.']);
            }
        }
        foreach ([['pricing.subscriptionMinimum', 'pricing.subscriptionMaximum'], ['pricing.eventTicketMinimum', 'pricing.eventTicketMaximum'], ['boosting.minimumBudgetGhs', 'boosting.maximumBudgetGhs']] as [$minPath, $maxPath]) {
            $minPrice = data_get($settings, $minPath); $maxPrice = data_get($settings, $maxPath);
            if ($minPrice !== null && $maxPrice !== null && $maxPrice < $minPrice) throw ValidationException::withMessages([$prefix.'.'.$maxPath => 'Maximum price must be at least the minimum.']);
        }
        if (data_get($settings, 'payouts.feeType') === 'percentage' && (float) data_get($settings, 'payouts.feeAmount', 0) > 100) {
            throw ValidationException::withMessages([$prefix.'.payouts.feeAmount' => 'Percentage payout fees cannot exceed 100.']);
        }
        $minimum = data_get($settings, 'payouts.minimum');
        $maximum = data_get($settings, 'payouts.maximum');
        if ($minimum !== null && $maximum !== null && $maximum < $minimum) throw ValidationException::withMessages([$prefix.'.payouts.maximum' => 'Maximum withdrawal must be at least the minimum.']);
    }
}
