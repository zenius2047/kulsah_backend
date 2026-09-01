<?php

namespace App\Events;

use App\Models\CommunityPostView;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommunityPostViewed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CommunityPostView $view,
        public readonly bool $counted,
    ) {}
}
