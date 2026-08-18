<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeScoringComponent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'configuration' => 'array', 'point_value' => 'decimal:4'];
    }

    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }
}
