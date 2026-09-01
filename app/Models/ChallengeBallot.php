<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChallengeBallot extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }

    public function voter()
    {
        return $this->belongsTo(User::class, 'voter_id');
    }

    public function choices()
    {
        return $this->hasMany(ChallengeBallotChoice::class, 'ballot_id');
    }
}
