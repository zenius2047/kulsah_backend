<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\{AdminConsoleAccess, AnchorKulsanService};
use Illuminate\Http\Request;

class AnchorKulsanController extends Controller
{
    public function __construct(private readonly AdminConsoleAccess $access, private readonly AnchorKulsanService $anchors) {}

    private function actor(Request $request, string $permission): User
    {
        $user = $request->user();
        $this->access->role($user);
        abort_unless($user->currentAccessToken() instanceof \Laravel\Sanctum\PersonalAccessToken && $user->tokenCan('admin-console'), 403, 'A console session is required.');
        $this->access->authorize($user, $permission);
        return $user;
    }

    public function index(Request $request)
    {
        $this->actor($request, 'anchors.view');
        $members = $this->anchors->members();
        return response()->json(['data' => ['summary' => $this->anchors->summary($members), 'members' => $members, 'configuration' => $this->anchors->currentConfiguration(), 'audit' => $this->anchors->audit(), 'benefits' => $this->anchors->benefits()]]);
    }

    public function search(Request $request)
    {
        $this->actor($request, 'anchors.manage');
        $data = $request->validate(['q' => 'required|string|min:2|max:100']);
        return response()->json(['data' => $this->anchors->search($data['q'])]);
    }

    public function saveConfiguration(Request $request)
    {
        $actor = $this->actor($request, 'anchors.configure');
        $data = $request->validate([
            'qualificationRule' => 'required|in:ALL,ANY', 'countExistingActivity' => 'required|boolean', 'active' => 'required|accepted',
            'applyToExistingCandidates' => 'sometimes|boolean',
            'milestones' => 'required|array|min:1|max:5', 'milestones.*.type' => 'required|distinct|in:followers,videos,views,active_days,referrals',
            'milestones.*.enabled' => 'required|boolean', 'milestones.*.target' => 'required|integer|min:0|max:1000000000000',
        ]);
        return response()->json(['data' => $this->anchors->saveConfiguration($actor, $data)]);
    }

    public function addCandidate(Request $request)
    {
        $actor = $this->actor($request, 'anchors.manage');
        $data = $request->validate(['userId' => 'required|integer|exists:users,id']);
        return response()->json(['data' => $this->anchors->addCandidate($actor, $data['userId'])], 201);
    }

    public function createBenefit(Request $request)
    {
        $actor = $this->actor($request, 'anchors.benefits.manage');
        $data = $request->validate([
            'userId' => 'required|integer|exists:users,id',
            'type' => 'required|in:cash,promotion,equipment,event,other',
            'title' => 'required|string|max:120', 'description' => 'required|string|min:5|max:2000',
            'amountGhs' => 'required_if:type,cash|numeric|min:0.01|max:10000000|decimal:2',
        ]);
        return response()->json(['data' => $this->anchors->createBenefit($actor, $data)], 201);
    }

    public function benefitAction(Request $request, int $benefit, string $action)
    {
        abort_unless(in_array($action, ['approve', 'reject', 'fulfill', 'grant'], true), 404);
        $actor = $this->actor($request, 'anchors.benefits.manage');
        if ($action === 'grant') $this->access->authorize($actor, 'finance.adjust');
        $data = $request->validate(['reason' => $action === 'reject' ? 'required|string|min:5|max:1000' : 'nullable|string|max:1000']);
        return response()->json(['data' => $this->anchors->benefitAction($actor, $benefit, $action, $data['reason'] ?? null, app(\App\Services\WalletService::class))]);
    }

    public function act(Request $request, int $user, string $action)
    {
        $permission = in_array($action, ['revoke', 'restore'], true) ? 'anchors.badges.manage' : 'anchors.manage';
        $actor = $this->actor($request, $permission);
        abort_unless(in_array($action, ['remove', 'deactivate', 'activate', 'revoke', 'restore', 'feature', 'unfeature'], true), 404);
        $data = $request->validate(['reason' => in_array($action, ['remove', 'deactivate', 'activate', 'revoke', 'restore'], true) ? 'required|string|min:5|max:1000' : 'nullable|string|max:1000']);
        return response()->json(['data' => $this->anchors->act($actor, $user, $action, $data['reason'] ?? null)]);
    }
}
