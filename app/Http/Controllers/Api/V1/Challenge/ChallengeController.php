<?php

namespace App\Http\Controllers\Api\V1\Challenge;

use App\Domain\Challenges\Actions\AcceptChallengeInvite;
use App\Domain\Challenges\Actions\CastChallengeBallot;
use App\Domain\Challenges\Actions\CreateChallenge;
use App\Domain\Challenges\Actions\FinalizeChallengeResults;
use App\Domain\Challenges\Actions\InviteChallengeParticipant;
use App\Domain\Challenges\Actions\InviteJuryMember;
use App\Domain\Challenges\Actions\ProcessChallengeRewards;
use App\Domain\Challenges\Actions\ResolveIntegrityFlag;
use App\Domain\Challenges\Actions\SelectChallengeWinner;
use App\Domain\Challenges\Actions\SubmitChallengeEntry;
use App\Domain\Challenges\Actions\SubmitJuryScore;
use App\Domain\Challenges\Actions\UpdateChallenge;
use App\Domain\Challenges\Actions\WithdrawChallengeEntry;
use App\Domain\Challenges\Services\ChallengeLeaderboardService;
use App\Domain\Challenges\Services\ChallengeLifecycleService;
use App\Enums\ChallengeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Challenge\CastChallengeBallotRequest;
use App\Http\Requests\Api\V1\Challenge\StoreChallengeRequest;
use App\Http\Requests\Api\V1\Challenge\SubmitChallengeEntryRequest;
use App\Http\Requests\Api\V1\Challenge\SubmitJuryScoreRequest;
use App\Http\Requests\Api\V1\Challenge\UpdateChallengeRequest;
use App\Http\Resources\ChallengeEntryResource;
use App\Http\Resources\ChallengeListResource;
use App\Http\Resources\ChallengeResource;
use App\Models\Challenge;
use App\Models\ChallengeEntry;
use App\Models\ChallengeIntegrityFlag;
use App\Models\ChallengeInvite;
use App\Models\ChallengeRewardAllocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChallengeController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate(['status' => ['nullable', Rule::enum(ChallengeStatus::class)], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = Challenge::query()
            ->whereNotIn('status', [ChallengeStatus::Draft, ChallengeStatus::PendingReview, ChallengeStatus::Rejected])
            ->with(['creator:id,name,username,avatar', 'prizes', 'media.video'])
            ->withCount('entries')
            ->latest();
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return ChallengeListResource::collection($query->paginate($validated['per_page'] ?? 20));
    }

    public function store(StoreChallengeRequest $request, CreateChallenge $action)
    {
        return ChallengeResource::make($action->execute($request->user(), $request->validated(), ChallengeStatus::Approved))->response()->setStatusCode(201);
    }

    public function draft(StoreChallengeRequest $request, CreateChallenge $action)
    {
        return ChallengeResource::make($action->execute($request->user(), $request->validated(), ChallengeStatus::Draft))->response()->setStatusCode(201);
    }

    public function show(Request $request, Challenge $challenge)
    {
        $this->authorize('view', $challenge);

        $challenge->load([
            'prizes',
            'media.video',
            'entries' => fn ($query) => $query->with(['creator:id,name,username,avatar', 'video'])->orderByDesc('current_score')->latest('submitted_at'),
            'scoringComponents',
            'juryCriteria',
        ]);

        return ChallengeResource::make($challenge);
    }

    public function update(UpdateChallengeRequest $request, Challenge $challenge, UpdateChallenge $action)
    {
        $this->authorize('update', $challenge);

        return ChallengeResource::make($action->execute($challenge, $request->user(), $request->validated()));
    }

    public function transition(Request $request, Challenge $challenge, ChallengeLifecycleService $lifecycle)
    {
        $this->authorize('manage', $challenge);
        $validated = $request->validate(['status' => ['required', Rule::enum(ChallengeStatus::class)], 'reason' => ['nullable', 'string', 'max:2000']]);

        return ChallengeResource::make($lifecycle->transition($challenge, ChallengeStatus::from($validated['status']), $request->user(), ['reason' => $validated['reason'] ?? null]));
    }

    public function submitEntry(SubmitChallengeEntryRequest $request, Challenge $challenge, SubmitChallengeEntry $action)
    {
        return ChallengeEntryResource::make($action->execute($challenge, $request->user(), $request->validated()))->response()->setStatusCode(201);
    }

    public function ballot(CastChallengeBallotRequest $request, Challenge $challenge, CastChallengeBallot $action)
    {
        return response()->json(['data' => $action->execute($challenge, $request->user(), $request->validated('choices'))]);
    }

    public function juryScore(SubmitJuryScoreRequest $request, Challenge $challenge, ChallengeEntry $entry, SubmitJuryScore $action)
    {
        abort_unless((int) $entry->challenge_id === (int) $challenge->id, 404);

        return response()->json(['data' => $action->execute($challenge, $entry, $request->user(), $request->validated('scores'))]);
    }

    public function leaderboard(Request $request, Challenge $challenge, ChallengeLeaderboardService $leaderboard)
    {
        abort_if(! $challenge->show_leaderboard || $challenge->leaderboard_mode === 'hidden', 403, 'The leaderboard is hidden.');

        return ChallengeEntryResource::collection($leaderboard->get($challenge, (int) $request->integer('per_page', 25)));
    }

    public function finalize(Request $request, Challenge $challenge, FinalizeChallengeResults $action)
    {
        $this->authorize('moderate', $challenge);

        return ChallengeResource::make($action->execute($challenge, $request->user()));
    }

    public function inviteParticipant(Request $request, Challenge $challenge, InviteChallengeParticipant $action)
    {
        $this->authorize('manage', $challenge);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id'], 'expires_at' => ['nullable', 'date', 'after:now']]);

        return response()->json(['data' => $action->execute($challenge, User::findOrFail($data['user_id']), $request->user(), isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null)], 201);
    }

    public function acceptInvite(Request $request, Challenge $challenge, ChallengeInvite $invite, AcceptChallengeInvite $action)
    {
        abort_unless((int) $invite->challenge_id === (int) $challenge->id, 404);

        return response()->json(['data' => $action->execute($invite, $request->user())]);
    }

    public function inviteJury(Request $request, Challenge $challenge, InviteJuryMember $action)
    {
        $this->authorize('manage', $challenge);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id'], 'role' => ['required', Rule::in(['judge', 'head_judge', 'observer'])], 'weight_bps' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return response()->json(['data' => $action->execute($challenge, User::findOrFail($data['user_id']), $request->user(), $data['role'], $data['weight_bps'] ?? 10000)], 201);
    }

    public function withdraw(Request $request, Challenge $challenge, ChallengeEntry $entry, WithdrawChallengeEntry $action)
    {
        abort_unless((int) $entry->challenge_id === (int) $challenge->id, 404);

        return ChallengeEntryResource::make($action->execute($entry, $request->user()));
    }

    public function resolveIntegrity(Request $request, Challenge $challenge, ChallengeIntegrityFlag $integrityFlag, ResolveIntegrityFlag $action)
    {
        $this->authorize('moderate', $challenge);
        abort_unless((int) $integrityFlag->challenge_id === (int) $challenge->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['resolved_valid', 'resolved_invalid', 'dismissed'])], 'resolution' => ['required', 'string', 'max:5000']]);

        return response()->json(['data' => $action->execute($integrityFlag, $request->user(), $data['status'], $data['resolution'])]);
    }

    public function processReward(Request $request, Challenge $challenge, ChallengeRewardAllocation $allocation, ProcessChallengeRewards $action)
    {
        $this->authorize('finance', $challenge);
        abort_unless((int) $allocation->challenge_id === (int) $challenge->id, 404);

        return response()->json(['data' => $action->execute($allocation, $request->user())]);
    }

    public function selectWinner(Request $request, Challenge $challenge, ChallengeEntry $entry, SelectChallengeWinner $action)
    {
        $this->authorize('moderate', $challenge);
        $data = $request->validate(['rank' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:5000']]);

        return response()->json(['data' => $action->execute($challenge, $entry, $request->user(), $data['rank'], $data['reason'])], 201);
    }
}

