<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VideoUploaded
{
    use Dispatchable, SerializesModels;

    public function __construct(public Video $video)
    {
    }
}
