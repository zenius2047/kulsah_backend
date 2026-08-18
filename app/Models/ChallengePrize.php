<?php

namespace App\Models;

use App\Enums\ChallengeRewardType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChallengePrize extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reward_type' => ChallengeRewardType::class, 'amount' => 'decimal:4', 'metadata' => 'array'];
    }

    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }
}
