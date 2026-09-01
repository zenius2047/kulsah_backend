<?php

namespace App\Domain\Challenges\Actions;

use App\Models\Challenge;
use App\Models\ChallengeAuditLog;
use App\Models\ChallengeBallot;
use App\Models\ChallengeEntry;
use App\Models\User;
use App\Services\KulCoinService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CastChallengeBallot
{
    public function __construct(private readonly KulCoinService $kulCoinService) {}

    public function execute(Challenge $challenge, User $voter, array $data): ChallengeBallot
    {
        return DB::transaction(function () use ($challenge, $voter, $data): ChallengeBallot {
            $challenge = Challenge::query()->lockForUpdate()->findOrFail($challenge->id);
            if (! $challenge->isVotingOpen()) {
                throw ValidationException::withMessages(['challenge' => 'Voting is not open.']);
            }

            $choices = $data['choices'] ?? [];
            $config = $challenge->voting_configuration ?? [];
            $mode = $config['mode'] ?? 'single_choice';
            $maximum = (int) ($config['maximum_choices'] ?? ($mode === 'single_choice' ? 1 : 10));
            if (count($choices) < 1 || count($choices) > $maximum) {
                throw ValidationException::withMessages(['choices' => "Select between 1 and {$maximum} entries."]);
            }
            if (count(array_unique(array_column($choices, 'challenge_entry_id'))) !== count($choices)) {
                throw ValidationException::withMessages(['choices' => 'An entry may only appear once on a ballot.']);
            }
            if ($mode === 'ranked_choice') {
                $ranks = array_column($choices, 'rank');
                sort($ranks);
                if ($ranks !== range(1, count($choices))) {
                    throw ValidationException::withMessages(['choices' => 'Ranks must be unique and consecutive starting at 1.']);
                }
            }

            $entryIds = array_column($choices, 'challenge_entry_id');
            $entries = ChallengeEntry::where('challenge_id', $challenge->id)->where('status', 'active')
                ->whereHas('video', fn ($query) => $query->where('processing_status', 'ready')->whereNotNull('hls_url'))
                ->whereIn('id', $entryIds)->get()->keyBy('id');
            if ($entries->count() !== count($entryIds)) {
                throw ValidationException::withMessages(['choices' => 'Every choice must be an active entry in this challenge.']);
            }
            if (! ($config['allow_self_voting'] ?? false) && $entries->contains(fn ($entry) => (int) $entry->creator_id === (int) $voter->id)) {
                throw ValidationException::withMessages(['choices' => 'Self-voting is not allowed.']);
            }

            $ballot = ChallengeBallot::where('challenge_id', $challenge->id)->where('voter_id', $voter->id)->lockForUpdate()->first();
            if ($ballot && ! ($config['allow_vote_changes'] ?? false)) {
                throw ValidationException::withMessages(['challenge' => 'Your ballot cannot be changed.']);
            }

            $voteTransaction = $this->kulCoinService->castVote(
                user: $voter,
                data: [
                    'contest_type' => 'challenge',
                    'contest_id' => (string) $challenge->id,
                    'target_id' => count($entryIds) === 1 ? (int) $entryIds[0] : null,
                    'vote_count' => count($entryIds),
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                    'device_info' => $data['device_info'] ?? null,
                    'challenge_id' => (int) $challenge->id,
                    'challenge_title' => $challenge->title,
                ],
                actor: $voter
            );

            $ballot ??= ChallengeBallot::create(['challenge_id' => $challenge->id, 'voter_id' => $voter->id, 'status' => 'submitted', 'submitted_at' => now()]);
            $ballot->choices()->delete();
            foreach ($choices as $choice) {
                $ballot->choices()->create($choice);
            }
            $ballot->forceFill(['submitted_at' => now(), 'status' => 'submitted'])->save();
            ChallengeAuditLog::create(['challenge_id' => $challenge->id, 'actor_user_id' => $voter->id, 'action' => 'ballot.submitted', 'subject_type' => ChallengeBallot::class, 'subject_id' => $ballot->id, 'after' => ['choice_count' => count($choices), 'vote_coin_price' => (int) config('kulcoin.vote_coin_price', 10), 'vote_count' => count($choices), 'kulcoin_transaction_reference' => $voteTransaction->reference]]);

            return $ballot->load('choices.entry');
        });
    }
}
