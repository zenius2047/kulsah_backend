<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChallengeEntry extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'current_score', 'current_rank', 'approved_at', 'rejected_at', 'disqualified_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'withdrawn_at' => 'datetime', 'disqualified_at' => 'datetime', 'current_score' => 'decimal:6'];
    }

    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function video()
    {
        return $this->belongsTo(Video::class);
    }

    public function eligibilitySnapshots()
    {
        return $this->hasMany(ChallengeEntryEligibilitySnapshot::class);
    }

    public function componentScores()
    {
        return $this->hasMany(ChallengeEntryScore::class);
    }

    public function scoreSnapshots()
    {
        return $this->hasMany(ChallengeScoreSnapshot::class);
    }

    public function juryScores()
    {
        return $this->hasMany(ChallengeJuryScore::class);
    }
}
