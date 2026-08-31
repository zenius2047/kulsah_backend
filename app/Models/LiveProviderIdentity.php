<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveProviderIdentity extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_uid'];

    protected function casts(): array
    {
        return [
            'provider_uid' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

