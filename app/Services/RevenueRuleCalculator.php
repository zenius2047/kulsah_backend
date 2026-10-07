<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevenueRuleCalculator
{
    public const SOURCES = [
        'creator_subscription', 'premium_video', 'event_ticket', 'live_battle', 'virtual_gift',
        'kulcoin_transaction', 'content_boost', 'creator_store', 'withdrawal',
        'payment_gateway', 'refund_reversal', 'tax',
    ];

    /** Apply the latest approved version per matching rule. Amounts use GHS or Kulcoin units. */
    public function calculate(string $source, float $gross, string $currency = 'GHS', array $context = [], ?array $draft = null): array
    {
        if (! in_array($source, self::SOURCES, true) || $gross < 0) {
            throw ValidationException::withMessages(['source' => 'The revenue source or gross amount is invalid.']);
        }

        $normalizedCurrency = strtolower($currency) === 'kulcoin' ? 'Kulcoin' : strtoupper($currency);

        $approved = DB::table('revenue_rule_versions as v')
            ->join('revenue_rule_reviews as review', 'review.revenue_rule_version_id', '=', 'v.id')
            ->join('revenue_rules as rule', 'rule.id', '=', 'v.revenue_rule_id')
            ->where('v.effective_at', '<=', now())
            ->whereIn('rule.source_key', [$source, 'payment_gateway', 'tax'])
            ->where('v.currency', $normalizedCurrency)
            ->where('review.decision', 'approved')
            ->select('v.*', 'review.created_at as reviewed_at', 'rule.source_key', 'rule.scope as rule_scope')
            ->orderBy('v.version')
            ->get()
            ->groupBy('revenue_rule_id')
            ->map(fn ($versions) => $versions->last())
            ->reject(fn ($version) => ! empty($draft['ruleId']) && $version->revenue_rule_id === $draft['ruleId'])
            ->filter(fn ($version) => in_array($version->status, ['active', 'scheduled'], true))
            ->filter(function ($version) use ($context): bool {
                $scope = json_decode($version->rule_scope ?: '{}', true) ?: [];
                foreach (['creatorId' => 'creator_id', 'organizerId' => 'organizer_id', 'country' => 'country'] as $key => $field) {
                    if (! empty($scope[$key])) {
                        $actual = (string) ($context[$field] ?? '');
                        $expected = (string) $scope[$key];
                        if ($field === 'country' ? strtoupper($actual) !== strtoupper($expected) : $actual !== $expected) {
                            return false;
                        }
                    }
                }

                return true;
            });

        $breakdown = [];
        foreach ($approved as $version) {
            $amount = $version->deduction_type === 'percentage' ? $gross * (float) $version->value / 100 : (float) $version->value;
            if ($version->minimum !== null) {
                $amount = max($amount, (float) $version->minimum);
            }
            if ($version->maximum !== null) {
                $amount = min($amount, (float) $version->maximum);
            }
            $amount = min(max(0, $amount), $gross);
            $breakdown[] = [
                'ruleId' => $version->revenue_rule_id, 'versionId' => $version->id, 'source' => $version->source_key,
                'type' => $version->deduction_type, 'value' => (float) $version->value,
                'amount' => round($amount, 4), 'currency' => $version->currency,
                'payer' => $version->payer, 'recipient' => $version->recipient, 'remainingRecipient' => $version->remaining_recipient,
            ];
        }

        if ($draft && in_array(($draft['source'] ?? null), [$source, 'payment_gateway', 'tax'], true) && strtoupper((string) ($draft['currency'] ?? '')) === strtoupper($normalizedCurrency)) {
            $scopeMatches = true;
            foreach (['creatorId' => 'creator_id', 'organizerId' => 'organizer_id', 'country' => 'country'] as $key => $field) {
                if (empty($draft['scope'][$key])) {
                    continue;
                }
                $actual = (string) ($context[$field] ?? '');
                $expected = (string) $draft['scope'][$key];
                if ($field === 'country' ? strtoupper($actual) !== strtoupper($expected) : $actual !== $expected) {
                    $scopeMatches = false;
                }
            }
            if ($scopeMatches) {
                $amount = ($draft['deductionType'] ?? '') === 'percentage' ? $gross * (float) $draft['value'] / 100 : (float) $draft['value'];
                if (($draft['minimum'] ?? null) !== null) {
                    $amount = max($amount, (float) $draft['minimum']);
                }
                if (($draft['maximum'] ?? null) !== null) {
                    $amount = min($amount, (float) $draft['maximum']);
                }
                $breakdown[] = ['ruleId' => $draft['ruleId'] ?? 'draft', 'versionId' => 'preview', 'source' => $draft['source'], 'type' => $draft['deductionType'],
                    'value' => (float) $draft['value'], 'amount' => round(min(max(0, $amount), $gross), 4), 'currency' => $normalizedCurrency,
                    'payer' => $draft['payer'], 'recipient' => $draft['recipient'], 'remainingRecipient' => $draft['remainingRecipient'], 'preview' => true];
            }
        }

        $deductedFromRecipient = array_sum(array_map(fn ($row) => in_array($row['payer'], ['creator', 'organizer'], true) ? $row['amount'] : 0, $breakdown));
        $customerSurcharge = array_sum(array_map(fn ($row) => $row['payer'] === 'customer' ? $row['amount'] : 0, $breakdown));
        $platformCost = array_sum(array_map(fn ($row) => $row['payer'] === 'platform' ? $row['amount'] : 0, $breakdown));
        $remainingRecipients = array_values(array_unique(array_column(array_filter(
            $breakdown,
            fn ($row) => ! in_array($row['source'], ['payment_gateway', 'tax'], true)
        ), 'remainingRecipient')));
        $defaultRecipient = match ($source) {
            'creator_subscription', 'premium_video', 'virtual_gift', 'creator_store' => 'creator',
            'event_ticket' => 'organizer',
            'kulcoin_transaction' => 'customer',
            'live_battle' => 'platform',
            default => null,
        };

        return [
            'source' => $source, 'gross' => round($gross, 4), 'currency' => $normalizedCurrency,
            'deductions' => $breakdown, 'recipientNet' => round(max(0, $gross - $deductedFromRecipient), 4),
            'customerSurcharge' => round($customerSurcharge, 4), 'platformCost' => round($platformCost, 4),
            'totalCharged' => round($gross + $customerSurcharge, 4),
            'remainingRecipient' => count($remainingRecipients) === 1 ? $remainingRecipients[0] : (count($remainingRecipients) === 0 ? $defaultRecipient : null),
            'remainingRecipients' => $remainingRecipients,
        ];
    }
}
