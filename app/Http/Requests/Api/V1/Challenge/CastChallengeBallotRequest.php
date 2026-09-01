<?php

namespace App\Http\Requests\Api\V1\Challenge;

use App\Models\Challenge;
use App\Models\KulCoinWallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CastChallengeBallotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'choices' => ['required', 'array', 'min:1', 'max:100'],
            'choices.*.challenge_entry_id' => ['required', 'integer'],
            'choices.*.rank' => ['nullable', 'integer', 'min:1'],
            'choices.*.points' => ['nullable', 'numeric', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $challenge = $this->route('challenge');

            if (! $challenge instanceof Challenge) {
                return;
            }

            if (! $this->challengeRequiresKulCoin($challenge)) {
                return;
            }

            $choiceCount = count((array) $this->input('choices', []));
            $votePrice = max(1, (int) config('kulcoin.vote_coin_price', 10));
            $requiredCoins = $choiceCount * $votePrice;

            $wallet = KulCoinWallet::query()->firstWhere('user_id', $this->user()->id);
            $availableCoins = (int) ($wallet?->available_balance_kc ?? 0) + (int) ($wallet?->bonus_balance_kc ?? 0);

            if ($availableCoins < $requiredCoins) {
                $validator->errors()->add(
                    'choices',
                    "You need {$requiredCoins} KC to vote, but your wallet only has {$availableCoins} KC."
                );
            }
        });
    }

    private function challengeRequiresKulCoin(Challenge $challenge): bool
    {
        return (bool) $challenge->isVotingOpen();
    }
}
