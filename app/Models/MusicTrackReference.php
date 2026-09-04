<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MusicTrackReference extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'external_id',
        'normalized_id',
        'title_snapshot',
        'artist_snapshot',
        'artist_id_snapshot',
        'artist_username_snapshot',
        'artwork_snapshot',
        'duration_snapshot',
        'source_permalink',
        'source_url',
        'metadata',
        'usage_count',
        'first_used_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'artwork_snapshot' => 'array',
            'metadata' => 'array',
            'usage_count' => 'integer',
            'first_used_at' => 'datetime',
            'last_used_at' => 'datetime',
            'duration_snapshot' => 'integer',
        ];
    }
}
