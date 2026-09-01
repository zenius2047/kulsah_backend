<?php

namespace App\Enums;

enum ChallengeStatus: string
{
    case Draft = 'draft';
    case AwaitingParticipants = 'awaiting_participants';
    case PendingReview = 'pending_review';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case SubmissionsClosed = 'submissions_closed';
    case VotingClosed = 'voting_closed';
    case Judging = 'judging';
    case IntegrityReview = 'integrity_review';
    case ResultsPending = 'results_pending';
    case Finalized = 'finalized';
    case RewardsProcessing = 'rewards_processing';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Voided = 'voided';
    case Archived = 'archived';
}