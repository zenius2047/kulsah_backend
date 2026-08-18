<?php

namespace App\Models;

use App\Enums\ChallengeHostType;
use App\Enums\ChallengeJudgingStrategy;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Challenge extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id', 'status', 'created_by_user_id', 'published_at', 'finalized_at', 'cancelled_at'];

    protected function casts(): array
    {
        return [
            'host_type' => ChallengeHostType::class,
            'visibility' => ChallengeVisibility::class,
            'status' => ChallengeStatus::class,
            'judging_strategy' => ChallengeJudgingStrategy::class,
            'registration_starts_at' => 'datetime', 'registration_ends_at' => 'datetime',
            'submission_starts_at' => 'datetime', 'submission_ends_at' => 'datetime',
            'voting_starts_at' => 'datetime', 'voting_ends_at' => 'datetime',
            'judging_starts_at' => 'datetime', 'judging_ends_at' => 'datetime',
            'results_publish_at' => 'datetime', 'published_at' => 'datetime',
            'finalized_at' => 'datetime', 'cancelled_at' => 'datetime',
            'show_leaderboard' => 'boolean', 'voting_configuration' => 'array',
            'integrity_configuration' => 'array', 'metadata' => 'array',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function hostUser()
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function collaborators()
    {
        return $this->hasMany(ChallengeCollaborator::class);
    }

    public function sponsors()
    {
        return $this->hasMany(ChallengeSponsor::class);
    }

    public function rewardPools()
    {
        return $this->hasMany(ChallengeRewardPool::class);
    }

    public function prizes()
    {
        return $this->hasMany(ChallengePrize::class);
    }

    public function rules()
    {
        return $this->hasMany(ChallengeRule::class);
    }

    public function media()
    {
        return $this->hasMany(ChallengeMedia::class);
    }

    public function invites()
    {
        return $this->hasMany(ChallengeInvite::class);
    }

    public function entries()
    {
        return $this->hasMany(ChallengeEntry::class);
    }

    public function scoringComponents()
    {
        return $this->hasMany(ChallengeScoringComponent::class);
    }

    public function juryCriteria()
    {
        return $this->hasMany(ChallengeJuryCriterion::class);
    }

    public function juryMembers()
    {
        return $this->hasMany(ChallengeJuryMember::class);
    }

    public function ballots()
    {
        return $this->hasMany(ChallengeBallot::class);
    }

    public function stages()
    {
        return $this->hasMany(ChallengeJudgingStage::class)->orderBy('sequence');
    }

    public function integrityFlags()
    {
        return $this->hasMany(ChallengeIntegrityFlag::class);
    }

    public function winners()
    {
        return $this->hasMany(ChallengeWinner::class)->orderBy('rank');
    }

    public function selectionDecisions()
    {
        return $this->hasMany(ChallengeSelectionDecision::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(ChallengeAuditLog::class);
    }

    public function isAcceptingSubmissions(): bool
    {
        return $this->status === ChallengeStatus::Active
            && now()->betweenIncluded($this->submission_starts_at, $this->submission_ends_at);
    }

    public function isVotingOpen(): bool
    {
        return in_array($this->status, [ChallengeStatus::Active, ChallengeStatus::SubmissionsClosed], true)
            && $this->voting_starts_at && $this->voting_ends_at
            && now()->betweenIncluded($this->voting_starts_at, $this->voting_ends_at);
    }
}
