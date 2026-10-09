<?php

namespace App\Services;

use App\Models\AdminConsoleRecord;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CountrySettingsService
{
    private const FEATURES = [
        'creator_subscription' => 'subscriptions', 'premium_video' => 'premiumContent',
        'virtual_gift' => 'virtualGifts', 'kulcoin_transaction' => 'kulcoinPurchases',
        'event_ticket' => 'paidEvents', 'content_boost' => 'videoBoosting',
        'live_battle' => 'liveBattle', 'creator_store' => 'creatorStores',
    ];

    public function supportedDisplayCurrencies(): array
    {
        return [
            ['code' => 'GHS', 'name' => 'Ghanaian cedi'],
            ['code' => 'USD', 'name' => 'US dollar'],
            ['code' => 'DZD', 'name' => 'Algerian dinar'],
            ['code' => 'AOA', 'name' => 'Angolan kwanza'],
            ['code' => 'XOF', 'name' => 'West African CFA franc'],
            ['code' => 'BWP', 'name' => 'Botswana pula'],
            ['code' => 'BIF', 'name' => 'Burundian franc'],
            ['code' => 'CVE', 'name' => 'Cape Verdean escudo'],
            ['code' => 'XAF', 'name' => 'Central African CFA franc'],
            ['code' => 'KMF', 'name' => 'Comorian franc'],
            ['code' => 'CDF', 'name' => 'Congolese franc'],
            ['code' => 'DJF', 'name' => 'Djiboutian franc'],
            ['code' => 'EGP', 'name' => 'Egyptian pound'],
            ['code' => 'ERN', 'name' => 'Eritrean nakfa'],
            ['code' => 'SZL', 'name' => 'Swazi lilangeni'],
            ['code' => 'ETB', 'name' => 'Ethiopian birr'],
            ['code' => 'GMD', 'name' => 'Gambian dalasi'],
            ['code' => 'GNF', 'name' => 'Guinean franc'],
            ['code' => 'KES', 'name' => 'Kenyan shilling'],
            ['code' => 'LSL', 'name' => 'Lesotho loti'],
            ['code' => 'LRD', 'name' => 'Liberian dollar'],
            ['code' => 'LYD', 'name' => 'Libyan dinar'],
            ['code' => 'MGA', 'name' => 'Malagasy ariary'],
            ['code' => 'MWK', 'name' => 'Malawian kwacha'],
            ['code' => 'MRU', 'name' => 'Mauritanian ouguiya'],
            ['code' => 'MUR', 'name' => 'Mauritian rupee'],
            ['code' => 'MAD', 'name' => 'Moroccan dirham'],
            ['code' => 'MZN', 'name' => 'Mozambican metical'],
            ['code' => 'NAD', 'name' => 'Namibian dollar'],
            ['code' => 'NGN', 'name' => 'Nigerian naira'],
            ['code' => 'RWF', 'name' => 'Rwandan franc'],
            ['code' => 'STN', 'name' => 'São Tomé and Príncipe dobra'],
            ['code' => 'SCR', 'name' => 'Seychellois rupee'],
            ['code' => 'SLE', 'name' => 'Sierra Leonean leone'],
            ['code' => 'SOS', 'name' => 'Somali shilling'],
            ['code' => 'ZAR', 'name' => 'South African rand'],
            ['code' => 'SSP', 'name' => 'South Sudanese pound'],
            ['code' => 'SDG', 'name' => 'Sudanese pound'],
            ['code' => 'TZS', 'name' => 'Tanzanian shilling'],
            ['code' => 'TND', 'name' => 'Tunisian dinar'],
            ['code' => 'UGX', 'name' => 'Ugandan shilling'],
            ['code' => 'ZMW', 'name' => 'Zambian kwacha'],
            ['code' => 'ZWG', 'name' => 'Zimbabwe Gold'],
        ];
    }

    public function catalog(): array
    {
        return collect(config('countries', []))->map(fn (array $country): array => [
            'name' => $country['name'], 'code' => strtoupper($country['code']),
            'catalogCurrency' => $country['currency'] ?? null, 'callingCode' => $country['dial_code'] ?? null,
        ])->sortBy('name')->values()->all();
    }

    public function record(): array
    {
        return Cache::remember('country-settings:record', 60, function (): array {
            return AdminConsoleRecord::payloadFor('country-settings');
        });
    }

    public function effective(string $countryCode): array
    {
        $record = $this->record();
        $global = is_array($record['globalDefaults'] ?? null) ? $record['globalDefaults'] : [];
        $override = is_array($record['countryOverrides'][strtoupper($countryCode)] ?? null) ? $record['countryOverrides'][strtoupper($countryCode)] : [];
        return $this->overlay($global, $override);
    }

    public function boostPackageKey(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
    }

    public function boostPackagePrice(array $countrySettings, array $package, string $currency): float
    {
        $key = $this->boostPackageKey((string) ($package['name'] ?? ''));
        $override = data_get($countrySettings, 'boosting.packagePrices.'.$key, []);
        $effectiveAt = data_get($override, 'effectiveAt');
        if ($effectiveAt && \Illuminate\Support\Carbon::parse($effectiveAt)->isFuture()) $override = [];
        $path = strtoupper($currency) === 'GHS' ? 'priceGhs' : 'priceKulcoin';
        return (float) data_get($override, $path, $package[$path] ?? 0);
    }

    public function resolve(array $global, array $override): array
    {
        return $this->overlay($global, $override);
    }

    public function snapshot(): array
    {
        $record = $this->record();
        $catalog = $this->catalog();
        $countries = array_map(function (array $country) use ($record): array {
            $effective = $this->effective($country['code']);
            $country['enabled'] = (bool) data_get($effective, 'availability.available', false);
            $country['registrationsEnabled'] = (bool) data_get($effective, 'availability.registrations', false);
            $country['monetizationEnabled'] = (bool) data_get($effective, 'availability.monetization', false);
            $country['overridden'] = ! empty($record['countryOverrides'][$country['code']] ?? []);
            $country['readiness'] = $this->readiness($effective, $country['code']);
            return $country;
        }, $catalog);
        return [
            'enforcementEnabled' => (bool) ($record['enforcementEnabled'] ?? false),
            'globalDefaults' => $record['globalDefaults'] ?? [],
            'countryOverrides' => $record['countryOverrides'] ?? [],
            'countries' => $countries,
            'currencyCatalog' => $this->supportedDisplayCurrencies(),
            'boostPackages' => array_map(fn (array $package): array => [
                'key' => $this->boostPackageKey((string) ($package['name'] ?? '')),
                'name' => $package['name'] ?? '',
                'priceGhs' => $package['priceGhs'] ?? null,
                'priceKulcoin' => $package['priceKulcoin'] ?? null,
            ], data_get(AdminConsoleRecord::payloadFor('video-boosting'), 'packages', [])),
            'integrations' => [
                'providers' => ['paystack' => ['available' => filled(config('paystack.secret_key')), 'channels' => config('paystack.channels', []), 'currency' => config('paystack.currency', 'GHS'), 'mobileMoneyProviders' => config('paystack.mobile_money_providers', [])]],
                'payouts' => ['manualWalletReview' => true, 'automatedProviders' => []],
                'otp' => [
                    'email' => filled(config('mail.mailers.'.config('mail.default').'.transport')) && config('mail.mailers.'.config('mail.default').'.transport') !== 'log',
                    'sms' => filled(env('MNOTIFY_SERVICE_API_KEY')) && filled(env('MNOTIFY_URL')),
                ],
            ],
        ];
    }

    public function readiness(array $effective, ?string $countryCode = null): array
    {
        $missing = [];
        if (data_get($effective, 'availability.available') === true) {
            foreach ([
                'localization.defaultCurrency' => 'Default display currency',
                'localization.supportedCurrencies' => 'Supported display currencies',
                'localization.defaultLanguage' => 'Default language',
                'localization.supportedLanguages' => 'Supported languages',
                'localization.timezones' => 'At least one timezone',
                'localization.defaultTimezone' => 'Default timezone',
            ] as $path => $label) {
                $value = data_get($effective, $path);
                if ($value === null || $value === '' || $value === []) $missing[] = $label;
            }
            if (data_get($effective, 'localization.defaultCurrency') !== 'GHS' || ! in_array('GHS', data_get($effective, 'localization.supportedCurrencies', []), true)) {
                $missing[] = 'GHS is the only configured system money currency';
            }
            $defaultTimezone = data_get($effective, 'localization.defaultTimezone');
            $timezones = data_get($effective, 'localization.timezones', []);
            if ($defaultTimezone && ! in_array($defaultTimezone, $timezones, true)) $missing[] = 'Default timezone must be in the supported timezone list';
        }
        $features = data_get($effective, 'monetizationFeatures', []);
        if (data_get($effective, 'availability.monetization') === true || collect($features)->contains(true)) {
            foreach ([
                'payments.providers' => 'Supported payment provider', 'payments.methods' => 'Supported payment method',
                'payments.currencies' => 'Supported payment currency', 'payouts.providers' => 'Payout provider or manual workflow',
                'payouts.destinations' => 'Payout destination', 'payouts.minimum' => 'Minimum withdrawal',
                'payouts.maximum' => 'Maximum withdrawal', 'payouts.schedule' => 'Payout schedule',
                'taxLegal.taxStatus' => 'Tax status (configured or not applicable)',
                'taxLegal.termsVersion' => 'Terms version', 'taxLegal.privacyVersion' => 'Privacy notice version',
                'taxLegal.consentVersion' => 'Consent version',
            ] as $path => $label) {
                $value = data_get($effective, $path);
                if ($value === null || $value === '' || $value === []) $missing[] = $label;
            }
            if (in_array('paystack', data_get($effective, 'payments.providers', []), true) && ! filled(config('paystack.secret_key'))) $missing[] = 'Paystack credentials in secure secrets';
            foreach (data_get($effective, 'payments.methods', []) as $method) {
                if (! in_array($method, config('paystack.channels', []), true)) $missing[] = "Payment method {$method} is not enabled in Paystack configuration";
            }
            if (in_array('GHS', data_get($effective, 'payments.currencies', []), true) && config('paystack.currency') !== 'GHS') $missing[] = 'Paystack GHS currency configuration';
            if (data_get($effective, 'taxLegal.taxStatus') === 'configured' && ! $this->hasEffectiveCountryTaxRule($countryCode)) {
                $missing[] = 'Approved, effective GHS tax rule for this country in Revenue Rules';
            }
            if (data_get($effective, 'monetizationFeatures.creatorStores') === true) $missing[] = 'Creator stores integration (not available)';
            if (data_get($effective, 'monetizationFeatures.subscriptions') === true) foreach (['pricing.subscriptionMinimum' => 'Subscription minimum price', 'pricing.subscriptionMaximum' => 'Subscription maximum price'] as $path => $label) if (data_get($effective, $path) === null) $missing[] = $label;
            if (data_get($effective, 'monetizationFeatures.paidEvents') === true) foreach (['pricing.eventTicketMinimum' => 'Event ticket minimum price', 'pricing.eventTicketMaximum' => 'Event ticket maximum price'] as $path => $label) if (data_get($effective, $path) === null) $missing[] = $label;
            if (data_get($effective, 'verification.monetizationRequired') === true
                || data_get($effective, 'verification.withdrawalRequired') === true
                || data_get($effective, 'payouts.verificationRequired') === true) {
                $kyc = data_get($effective, 'verification.kyc', []);
                foreach (['individual' => 'Individual accepted document types', 'business' => 'Business accepted document types'] as $category => $label) {
                    if (data_get($kyc, 'acceptedDocuments.'.$category, []) === []) $missing[] = $label;
                    if (data_get($kyc, 'requiredCaptures.'.$category, []) === []) $missing[] = ucfirst($category).' required document captures';
                }
            }
        }
        if (data_get($effective, 'availability.registrations') === true) {
            $routes = data_get($effective, 'otp.routes', []);
            if ($routes === []) $missing[] = 'OTP delivery routes';
            foreach ($routes as $route) {
                if ($route === 'email' && !(filled(config('mail.mailers.'.config('mail.default').'.transport')) && config('mail.mailers.'.config('mail.default').'.transport') !== 'log')) $missing[] = 'Configured email delivery';
                if ($route === 'sms' && !(filled(env('MNOTIFY_SERVICE_API_KEY')) && filled(env('MNOTIFY_URL')))) $missing[] = 'Configured SMS delivery';
            }
        }
        return ['ready' => $missing === [], 'missing' => array_values(array_unique($missing))];
    }

    private function hasEffectiveCountryTaxRule(?string $countryCode): bool
    {
        if (! $countryCode || ! DB::getSchemaBuilder()->hasTable('revenue_rule_versions')) return false;

        return DB::table('revenue_rules as rule')
            ->join('revenue_rule_versions as version', 'version.revenue_rule_id', '=', 'rule.id')
            ->join('revenue_rule_reviews as review', 'review.revenue_rule_version_id', '=', 'version.id')
            ->where('rule.source_key', 'tax')
            ->whereJsonContains('rule.scope->country', strtoupper($countryCode))
            ->where('version.currency', 'GHS')
            ->where('version.status', 'active')
            ->where('version.effective_at', '<=', now())
            ->where('review.decision', 'approved')
            ->whereNotExists(function ($newer) {
                $newer->selectRaw('1')->from('revenue_rule_versions as newer_version')
                    ->join('revenue_rule_reviews as newer_review', 'newer_review.revenue_rule_version_id', '=', 'newer_version.id')
                    ->whereColumn('newer_version.revenue_rule_id', 'rule.id')
                    ->whereColumn('newer_version.version', '>', 'version.version')
                    ->where('newer_version.effective_at', '<=', now())
                    ->where('newer_review.decision', 'approved');
            })
            ->exists();
    }

    public function enforcementEnabled(): bool
    {
        return (bool) ($this->record()['enforcementEnabled'] ?? false);
    }

    public function registrationOtpRoutes(string $countryCode, array $requestedRoutes, ?string $contact): array
    {
        $this->assertCountryAvailable($countryCode, 'registrations', 'New registrations are not available in this country.');
        return $this->otpRoutes($countryCode, $requestedRoutes, $contact);
    }

    public function otpRoutes(string $countryCode, array $requestedRoutes, ?string $contact): array
    {
        if (! $this->enforcementEnabled()) return $requestedRoutes;
        $effective = $this->effective($countryCode);
        $available = array_values(array_intersect($requestedRoutes, data_get($effective, 'otp.routes', [])));
        $available = array_values(array_filter($available, fn (string $route): bool => match ($route) {
            'email' => filled(config('mail.mailers.'.config('mail.default').'.transport')) && config('mail.mailers.'.config('mail.default').'.transport') !== 'log',
            'sms' => filled(env('MNOTIFY_SERVICE_API_KEY')) && filled(env('MNOTIFY_URL')),
            default => false,
        }));
        if ($contact === null || $available === []) throw ValidationException::withMessages(['otp' => 'A configured country OTP delivery route is required.']);
        $limits = data_get($effective, 'otp.perCountryLimits', []);
        $key = 'country-otp:'.strtoupper($countryCode).':'.hash('sha256', strtolower(trim($contact)));
        foreach (['perHour' => 3600, 'perDay' => 86400] as $field => $seconds) {
            $limit = data_get($limits, $field);
            if ($limit === null) continue;
            if ((int) $limit <= 0) throw ValidationException::withMessages(['otp' => 'The country OTP limit has been reached. Try again later.']);
            $rateKey = $key.':'.$field;
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rateKey, (int) $limit)) throw ValidationException::withMessages(['otp' => 'The country OTP limit has been reached. Try again later.']);
            \Illuminate\Support\Facades\RateLimiter::hit($rateKey, $seconds);
        }
        return $available;
    }

    public function assertPriceInRange(User $user, string $feature, float $amount, string $currency): void
    {
        if (! $this->enforcementEnabled()) return;
        $effective = $this->effective((string) $user->country_code);
        $paths = match ($feature) {
            'subscriptions' => ['pricing.subscriptionMinimum', 'pricing.subscriptionMaximum'],
            'paidEvents' => ['pricing.eventTicketMinimum', 'pricing.eventTicketMaximum'],
            default => [null, null],
        };
        if ($paths[0] === null) return;
        if (strtoupper($currency) !== 'GHS') throw ValidationException::withMessages(['currency' => 'Only the configured GHS payment currency is supported in this country.']);
        [$minimum, $maximum] = [data_get($effective, $paths[0]), data_get($effective, $paths[1])];
        if ($minimum === null || $maximum === null) throw ValidationException::withMessages(['price' => 'Configure country price limits before enabling this feature.']);
        if ($amount < (float) $minimum || $amount > (float) $maximum) throw ValidationException::withMessages(['price' => 'Price is outside the configured limits for your account country.']);
    }

    public function assertRegistrationAllowed(string $countryCode, ?string $dateOfBirth = null): void
    {
        $this->assertCountryAvailable($countryCode, 'registrations', 'New registrations are not available in this country.');
        if (! $this->enforcementEnabled()) return;

        $minimum = data_get($this->effective($countryCode), 'age.accountMinimum');
        if ($minimum === null) return;
        $age = $dateOfBirth ? \Illuminate\Support\Carbon::parse($dateOfBirth)->age : null;
        if ($age === null || $age < (int) $minimum) {
            throw ValidationException::withMessages(['dob' => 'Registration is unavailable because the configured minimum age is not met.']);
        }
    }

    public function assertFeatureAllowed(?User $user, string $feature): void
    {
        $code = strtoupper((string) $user?->country_code);
        $record = $this->record();
        if (! ($record['enforcementEnabled'] ?? false)) return;
        if ($code === '') throw ValidationException::withMessages(['country' => 'Set your account country before starting a monetization activity.']);
        $effective = $this->effective($code);
        $this->assertReadyCountry($code, $effective);
        if (data_get($effective, 'verification.monetizationRequired') === true && ! app(KycEligibilityService::class)->identityVerified($user)) throw ValidationException::withMessages(['verification' => 'Current identity verification is required before monetizing in your country.']);
        $minimumAge = data_get($effective, "age.featureMinimums.{$feature}", data_get($effective, 'age.monetizationMinimum', data_get($effective, 'age.accountMinimum')));
        if ($minimumAge !== null) {
            $age = $user->dob ? \Illuminate\Support\Carbon::parse($user->dob)->age : null;
            if ($age === null || $age < (int) $minimumAge) throw ValidationException::withMessages(['age' => 'This activity is unavailable because the configured minimum age is not met.']);
        }
        if (data_get($effective, 'availability.monetization') !== true || data_get($effective, "monetizationFeatures.{$feature}") !== true) {
            throw ValidationException::withMessages(['country' => 'This monetization feature is not enabled for your account country.']);
        }
    }

    public function assertWithdrawalAllowed(User $user, float $amount, string $currency): void
    {
        if (! $this->enforcementEnabled() || ! filled($user->country_code)) return;

        $effective = $this->effective((string) $user->country_code);
        $verificationRequired = data_get($effective, 'payouts.verificationRequired', data_get($effective, 'verification.withdrawalRequired', false));
        if ($verificationRequired && ! app(KycEligibilityService::class)->identityVerified($user)) {
            throw ValidationException::withMessages(['verification' => 'Current identity verification is required before this withdrawal can be approved.']);
        }
        if (data_get($effective, 'verification.kyc.payoutOwnershipRequired') === true && ! app(KycEligibilityService::class)->payoutOwnershipVerified($user)) {
            throw ValidationException::withMessages(['payout_account' => 'Payout account ownership has not been verified.']);
        }

        $currency = strtoupper($currency);
        $configuredCurrency = strtoupper((string) data_get($effective, 'payouts.currency', 'GHS'));
        if ($currency !== $configuredCurrency) return;
        $minimum = data_get($effective, 'payouts.minimum');
        $maximum = data_get($effective, 'payouts.maximum');
        if ($minimum !== null && $amount < (float) $minimum) throw ValidationException::withMessages(['amount' => 'This withdrawal is below the configured country minimum.']);
        if ($maximum !== null && $amount > (float) $maximum) throw ValidationException::withMessages(['amount' => 'This withdrawal exceeds the configured country maximum.']);
    }

    public function assertPaymentAllowed(?User $user, string $method, string $currency): void
    {
        $record = $this->record();
        if (! ($record['enforcementEnabled'] ?? false)) return;
        $code = strtoupper((string) $user?->country_code);
        $effective = $this->effective($code);
        $this->assertReadyCountry($code, $effective);
        if (! in_array($method, data_get($effective, 'payments.methods', []), true)) {
            throw ValidationException::withMessages(['method' => 'This payment method is not available in your account country.']);
        }
        if (! in_array(strtoupper($currency), data_get($effective, 'payments.currencies', []), true)) {
            throw ValidationException::withMessages(['currency' => 'This currency is not available in your account country.']);
        }
        if (strtolower((string) data_get($effective, 'payments.providers.0')) !== 'paystack') {
            throw ValidationException::withMessages(['provider' => 'No configured payment provider is available in your account country.']);
        }
    }

    public function assertCountryAvailable(string $countryCode, string $activity, string $message): void
    {
        $record = $this->record();
        if (! ($record['enforcementEnabled'] ?? false)) return;
        $effective = $this->effective(strtoupper($countryCode));
        $this->assertReadyCountry(strtoupper($countryCode), $effective);
        if (data_get($effective, "availability.{$activity}") !== true) throw ValidationException::withMessages(['country' => $message]);
    }

    public function clearCache(): void
    {
        Cache::forget('country-settings:record');
    }

    private function assertReadyCountry(string $code, array $effective): void
    {
        $catalogCodes = array_column($this->catalog(), 'code');
        if (! in_array($code, $catalogCodes, true)) throw ValidationException::withMessages(['country' => 'Choose a supported account country.']);
        if (data_get($effective, 'availability.available') !== true) throw ValidationException::withMessages(['country' => 'Kulsah is not available in your account country.']);
        $readiness = $this->readiness($effective, $code);
        if (! $readiness['ready']) throw ValidationException::withMessages(['country' => 'Country setup is incomplete: '.implode(', ', $readiness['missing']).'.']);
    }

    private function overlay(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && $this->isAssociative($value) && $this->isAssociative($base[$key])) {
                $base[$key] = $this->overlay($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private function isAssociative(array $value): bool
    {
        return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
    }
}
