<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnchorKulsanMembership extends Model
{
    protected $table = 'anchor_kulsan_memberships';

    protected $casts = ['qualification_snapshot' => 'array', 'selected_at' => 'datetime', 'recognized_at' => 'datetime', 'revoked_at' => 'datetime', 'restored_at' => 'datetime', 'featured' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
