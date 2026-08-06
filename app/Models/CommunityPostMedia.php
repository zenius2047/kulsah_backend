<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunityPostMedia extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_post_id',
        'media_type',
        'disk',
        'source_key',
        'source_url',
        'original_name',
        'mime_type',
        'sort_order',
        'cloudinary_public_id',
        'cloudinary_asset_id',
        'cloudinary_url',
        'cloudinary_stream_url',
        'cloudinary_thumbnail_url',
        'metadata',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function post()
    {
        return $this->belongsTo(CommunityPost::class, 'community_post_id');
    }
}
