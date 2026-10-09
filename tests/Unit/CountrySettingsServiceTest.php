<?php

namespace Tests\Unit;

use App\Services\CountrySettingsService;
use App\Http\Controllers\Api\V1\Admin\CountrySettingsController;
use App\Models\User;
use App\Services\AdminConsoleAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CountrySettingsServiceTest extends TestCase
{
    public function test_country_override_resolves_over_global_defaults_and_preserves_explicit_false_zero_and_empty_values(): void
    {
        $service = new CountrySettingsService();
        $resolved = $service->resolve([
            'availability' => ['available' => true, 'registrations' => true],
            'pricing' => ['minimum' => 5, 'maximum' => 100],
            'localization' => ['supportedLanguages' => ['en', 'fr']],
        ], [
            'availability' => ['registrations' => false],
            'pricing' => ['minimum' => 0],
            'localization' => ['supportedLanguages' => []],
        ]);

        $this->assertTrue($resolved['availability']['available']);
        $this->assertFalse($resolved['availability']['registrations']);
        $this->assertSame(0, $resolved['pricing']['minimum']);
        $this->assertSame(100, $resolved['pricing']['maximum']);
        $this->assertSame([], $resolved['localization']['supportedLanguages']);
    }
    public function test_enforcement_rejects_a_disabled_monetization_feature(): void
    {
        $defaults = [
            'availability' => ['available' => true, 'registrations' => false, 'monetization' => false],
            'localization' => [
                'defaultCurrency' => 'GHS', 'supportedCurrencies' => ['GHS'],
                'defaultLanguage' => 'en', 'supportedLanguages' => ['en'], 'timezones' => ['Africa/Accra'], 'defaultTimezone' => 'Africa/Accra',
            ],
            'monetizationFeatures' => ['subscriptions' => false],
        ];
        Cache::shouldReceive('remember')->zeroOrMoreTimes()->andReturn([
            'enforcementEnabled' => true, 'globalDefaults' => $defaults, 'countryOverrides' => [],
        ]);
        $user = new User();
        $user->country_code = 'GH';
        $user->verified = true;

        try {
            (new CountrySettingsService())->assertFeatureAllowed($user, 'subscriptions');
            $this->fail('Expected disabled country feature enforcement to reject the activity.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not enabled', $exception->getMessage());
        }
    }

    public function test_enforcement_applies_the_configured_minimum_registration_age(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
        $defaults = [
            'availability' => ['available' => true, 'registrations' => true, 'monetization' => false],
            'localization' => [
                'defaultCurrency' => 'GHS', 'supportedCurrencies' => ['GHS'],
                'defaultLanguage' => 'en', 'supportedLanguages' => ['en'], 'timezones' => ['Africa/Accra'], 'defaultTimezone' => 'Africa/Accra',
            ],
            'otp' => ['routes' => ['email']], 'age' => ['accountMinimum' => 18],
        ];
        Cache::shouldReceive('remember')->zeroOrMoreTimes()->andReturn([
            'enforcementEnabled' => true, 'globalDefaults' => $defaults, 'countryOverrides' => [],
        ]);

        try {
            (new CountrySettingsService())->assertRegistrationAllowed('GH', '2015-01-01');
            $this->fail('Expected the configured minimum age to reject registration.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dob', $exception->errors());
        }
    }

    public function test_existing_payout_obligation_is_not_blocked_when_new_country_activity_is_disabled(): void
    {
        Cache::shouldReceive('remember')->zeroOrMoreTimes()->andReturn([
            'enforcementEnabled' => true,
            'globalDefaults' => ['availability' => ['available' => false, 'monetization' => false]],
            'countryOverrides' => ['GH' => ['payouts' => ['minimum' => 10, 'maximum' => 100, 'currency' => 'GHS']]],
        ]);
        $user = new User();
        $user->country_code = 'GH';

        (new CountrySettingsService())->assertWithdrawalAllowed($user, 20, 'GHS');
        $this->assertTrue(true);
    }

    public function test_country_payout_verification_requirement_is_enforced(): void
    {
        Cache::shouldReceive('remember')->zeroOrMoreTimes()->andReturn([
            'enforcementEnabled' => true,
            'globalDefaults' => [],
            'countryOverrides' => ['GH' => ['payouts' => ['verificationRequired' => true]]],
        ]);
        $user = new User();
        $user->country_code = 'GH';
        $user->verified = false;

        $this->expectException(ValidationException::class);
        (new CountrySettingsService())->assertWithdrawalAllowed($user, 20, 'GHS');
    }

    public function test_country_settings_reject_non_ghs_payment_currency(): void
    {
        $controller = new CountrySettingsController(new AdminConsoleAccess(), new CountrySettingsService(), app(\App\Services\FeedService::class));
        $method = new \ReflectionMethod($controller, 'validateSettings');

        $this->expectException(ValidationException::class);
        $method->invoke($controller, ['payments' => ['currencies' => ['USD']]], 'settings', []);
    }

    public function test_country_settings_reject_ghs_money_with_more_than_two_decimal_places(): void
    {
        $controller = new CountrySettingsController(new AdminConsoleAccess(), new CountrySettingsService(), app(\App\Services\FeedService::class));
        $method = new \ReflectionMethod($controller, 'validateSettings');

        $this->expectException(ValidationException::class);
        $method->invoke($controller, ['pricing' => ['subscriptionMinimum' => 1.235]], 'settings', []);
    }

    public function test_future_boost_package_price_uses_global_price_until_effective_date(): void
    {
        $service = new CountrySettingsService();
        $settings = ['boosting' => ['packagePrices' => ['starter' => ['priceGhs' => 40, 'effectiveAt' => now()->addDay()->toDateString()]]]];
        $this->assertSame(25.0, $service->boostPackagePrice($settings, ['name' => 'Starter', 'priceGhs' => 25], 'GHS'));
    }

    public function test_effective_boost_package_price_uses_country_override(): void
    {
        $service = new CountrySettingsService();
        $settings = ['boosting' => ['packagePrices' => ['starter' => ['priceGhs' => 40, 'effectiveAt' => now()->subDay()->toDateString()]]]];
        $this->assertSame(40.0, $service->boostPackagePrice($settings, ['name' => 'Starter', 'priceGhs' => 25], 'GHS'));
    }

    public function test_zero_otp_limit_disables_country_registration_otp(): void
    {
        $settings = [
            'availability' => ['available' => true, 'registrations' => true, 'monetization' => false],
            'localization' => ['defaultCurrency' => 'GHS', 'supportedCurrencies' => ['GHS'], 'defaultLanguage' => 'en', 'supportedLanguages' => ['en'], 'timezones' => ['Africa/Accra'], 'defaultTimezone' => 'Africa/Accra'],
            'otp' => ['routes' => ['email'], 'perCountryLimits' => ['perHour' => 0]],
        ];
        Cache::shouldReceive('remember')->zeroOrMoreTimes()->andReturn([
            'enforcementEnabled' => true, 'globalDefaults' => $settings, 'countryOverrides' => [],
        ]);

        $this->expectException(ValidationException::class);
        (new CountrySettingsService())->registrationOtpRoutes('GH', ['email'], 'person@example.com');
    }

}
