<?php

namespace App\Services;

use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class LiveEligibilityService
{
    public function __construct(private readonly LiveAuthorizationService $authorization)
    {
    }

    public function assertCreator(User $user): void
    {
        $this->authorization->assertCreatorEligible($user);
    }

    public function assertViewer(User $viewer, LiveSession $live): void
    {
        $this->authorization->assertViewerCanJoin($viewer, $live);
    }
}

