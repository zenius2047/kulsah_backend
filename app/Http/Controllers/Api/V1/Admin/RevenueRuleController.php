<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\AdminConsoleDataChanged;
use App\Http\Controllers\Controller;
use App\Services\AdminConsoleAccess;
use App\Services\RevenueRuleCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RevenueRuleController extends Controller
{
    public function __construct(private AdminConsoleAccess $access, private RevenueRuleCalculator $calculator) {}

    private function actor(Request $request)
    {
        $user = $request->user();
        $this->access->authorize($user, 'finance.view');

        return $user;
    }

    public function index(Request $request)
    {
        $actor = $this->actor($request);
        $rules = DB::table('revenue_rules')->orderByDesc('created_at')->get()->map(function ($rule) {
            $versions = DB::table('revenue_rule_versions')->where('revenue_rule_id', $rule->id)->orderByDesc('version')->get();
            $latest = $versions->first();
            if ($latest) {
                $latest->created_by_name = DB::table('users')->where('id', $latest->created_by)->value('name') ?? 'Deleted administrator';
                $latest->modified_by_name = DB::table('users')->where('id', $latest->last_modified_by)->value('name') ?? 'Deleted administrator';
            }
            $active = DB::table('revenue_rule_versions as v')->join('revenue_rule_reviews as review', 'review.revenue_rule_version_id', '=', 'v.id')
                ->where('v.revenue_rule_id', $rule->id)->where('review.decision', 'approved')->where('v.effective_at', '<=', now())->orderByDesc('v.version')->first();
            if ($active && $active->status === 'inactive') {
                $active = null;
            }
            $review = $latest ? DB::table('revenue_rule_reviews')->where('revenue_rule_version_id', $latest->id)->latest('created_at')->first() : null;
            $latest->approval_status = $review?->decision ?? 'pending';
            $latest->approved_by = $review?->reviewed_by;
            $latest->reviewed_by_name = $review ? (DB::table('users')->where('id', $review->reviewed_by)->value('name') ?? 'Deleted administrator') : null;
            $latest->review_reason = $review?->reason;

            return ['id' => $rule->id, 'source' => $rule->source_key, 'scope' => json_decode($rule->scope ?: '{}', true) ?: [],
                'version' => $latest, 'activeVersion' => $active, 'versionCount' => $versions->count()];
        });

        $approverRoles = collect(AdminConsoleAccess::ROLES)
            ->filter(fn (string $role) => in_array('finance.rules.approve', $this->access->permissions($role), true))
            ->values();

        return response()->json(['data' => ['rules' => $rules, 'sources' => RevenueRuleCalculator::SOURCES,
            'liveSources' => ['creator_subscription', 'event_ticket', 'live_battle', 'virtual_gift', 'kulcoin_transaction', 'content_boost'],
            'approverRoles' => $approverRoles,
            'canManage' => in_array('finance.rules.manage', $this->access->permissions($this->access->role($actor)), true),
            'canApprove' => in_array('finance.rules.approve', $this->access->permissions($this->access->role($actor)), true)]]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        $this->access->authorize($actor, 'finance.rules.manage');
        $data = $request->validate([
            'ruleId' => ['nullable', 'uuid', Rule::exists('revenue_rules', 'id')],
            'source' => ['required', Rule::in(RevenueRuleCalculator::SOURCES)],
            'scope' => 'nullable|array', 'scope.creatorId' => 'nullable|integer|exists:users,id',
            'scope.organizerId' => 'nullable|integer|exists:users,id', 'scope.country' => 'nullable|string|size:2',
            'deductionType' => 'required|in:percentage,fixed', 'value' => 'required|numeric|min:0|max:100000000',
            'currency' => 'required|in:GHS,Kulcoin', 'payer' => 'required|in:customer,creator,organizer,platform',
            'recipient' => 'required|in:platform,creator,organizer,gateway,tax_authority',
            'remainingRecipient' => 'required|in:creator,organizer,platform,customer',
            'minimum' => 'nullable|numeric|min:0', 'maximum' => 'nullable|numeric|min:0|gte:minimum',
            'effectiveAt' => 'required|date', 'status' => 'required|in:active,inactive,scheduled',
            'description' => 'required|string|min:5|max:2000',
        ]);
        abort_if($data['deductionType'] === 'percentage' && $data['value'] > 100, 422, 'Percentage deductions cannot exceed 100%.');
        abort_if($data['currency'] === 'Kulcoin' && $data['deductionType'] === 'fixed' && $data['value'] != floor($data['value']), 422, 'Fixed Kulcoin deductions must be whole coins.');
        abort_if($data['status'] === 'scheduled' && now()->gte($data['effectiveAt']), 422, 'Scheduled rules need a future effective date.');

        $result = DB::transaction(function () use ($data, $actor, $request) {
            $rule = isset($data['ruleId']) ? DB::table('revenue_rules')->where('id', $data['ruleId'])->lockForUpdate()->first() : null;
            if ($rule) {
                abort_unless($rule->source_key === $data['source'], 422, 'A rule version cannot change its revenue source.');
                $currentScope = json_decode($rule->scope ?: '{}', true) ?: [];
                $submittedScope = array_filter($data['scope'] ?? [], fn ($value) => $value !== null && $value !== '');
                ksort($currentScope);
                ksort($submittedScope);
                abort_if($currentScope !== $submittedScope, 422, 'Rule scope cannot change between versions. Create a separate rule for a new scope.');
                $latest = DB::table('revenue_rule_versions')->where('revenue_rule_id', $rule->id)->orderByDesc('version')->first();
                abort_if($latest && ! DB::table('revenue_rule_reviews')->where('revenue_rule_version_id', $latest->id)->exists(), 409, 'Wait for the current version to be approved or rejected before submitting another.');
            } else {
                $ruleId = (string) Str::uuid();
                DB::table('revenue_rules')->insert(['id' => $ruleId, 'source_key' => $data['source'], 'scope' => json_encode($data['scope'] ?? []), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                $rule = (object) ['id' => $ruleId];
            }
            $versionNumber = (int) DB::table('revenue_rule_versions')->where('revenue_rule_id', $rule->id)->max('version') + 1;
            $versionId = (string) Str::uuid();
            DB::table('revenue_rule_versions')->insert([
                'id' => $versionId, 'revenue_rule_id' => $rule->id, 'version' => $versionNumber,
                'deduction_type' => $data['deductionType'], 'value' => $data['value'], 'currency' => $data['currency'],
                'payer' => $data['payer'], 'recipient' => $data['recipient'], 'remaining_recipient' => $data['remainingRecipient'], 'minimum' => $data['minimum'] ?? null,
                'maximum' => $data['maximum'] ?? null, 'effective_at' => $data['effectiveAt'], 'status' => $data['status'],
                'description' => $data['description'], 'created_by' => $actor->id, 'last_modified_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($rule->id, $versionId, $actor->id, 'submitted', $request, $data);

            return ['ruleId' => $rule->id, 'versionId' => $versionId, 'version' => $versionNumber, 'approvalStatus' => 'pending'];
        });
        DB::afterCommit(fn () => $this->broadcastChange('submitted', (string) $actor->id));

        return response()->json(['data' => $result], 201);
    }

    public function preview(Request $request)
    {
        $actor = $this->actor($request);
        $request->validate(['source' => ['required', Rule::in(RevenueRuleCalculator::SOURCES)], 'gross' => 'required|numeric|min:0', 'currency' => 'required|in:GHS,Kulcoin',
            'context' => 'nullable|array', 'context.creator_id' => 'nullable|integer', 'context.organizer_id' => 'nullable|integer', 'context.country' => 'nullable|string|size:2',
            'draft' => 'required|array', 'draft.ruleId' => ['nullable', 'uuid', Rule::exists('revenue_rules', 'id')], 'draft.source' => ['required', Rule::in(RevenueRuleCalculator::SOURCES)], 'draft.deductionType' => 'required|in:percentage,fixed',
            'draft.value' => 'required|numeric|min:0|max:100000000', 'draft.currency' => 'required|in:GHS,Kulcoin', 'draft.payer' => 'required|in:customer,creator,organizer,platform',
            'draft.recipient' => 'required|in:platform,creator,organizer,gateway,tax_authority', 'draft.remainingRecipient' => 'required|in:creator,organizer,platform,customer', 'draft.minimum' => 'nullable|numeric|min:0',
            'draft.maximum' => 'nullable|numeric|min:0|gte:draft.minimum', 'draft.scope' => 'nullable|array']);
        $draft = $request->input('draft');
        abort_if($draft['deductionType'] === 'percentage' && $draft['value'] > 100, 422, 'Percentage deductions cannot exceed 100%.');

        return response()->json(['data' => $this->calculator->calculate((string) $request->string('source'), (float) $request->input('gross'), (string) $request->string('currency'), $request->input('context', []), $draft)]);
    }

    public function approve(Request $request, string $rule)
    {
        $actor = $request->user();
        $this->access->authorize($actor, 'finance.rules.approve');
        $request->validate(['versionId' => 'required|uuid|exists:revenue_rule_versions,id', 'reason' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($request, $actor, $rule) {
            $version = DB::table('revenue_rule_versions')->where('id', $request->input('versionId'))->where('revenue_rule_id', $rule)->lockForUpdate()->first();
            abort_unless($version, 404);
            abort_if((int) $version->created_by === (int) $actor->id, 403, 'A different finance administrator must approve this rule.');
            abort_if(DB::table('revenue_rule_reviews')->where('revenue_rule_version_id', $version->id)->exists(), 409, 'This version has already been reviewed. Submit a new version to change it.');
            DB::table('revenue_rule_reviews')->insert(['revenue_rule_version_id' => $version->id, 'reviewed_by' => $actor->id, 'decision' => 'approved', 'reason' => $request->input('reason'), 'created_at' => now()]);
            $this->audit($rule, $version->id, $actor->id, 'approved', $request, ['reason' => $request->input('reason')]);
        });
        DB::afterCommit(fn () => $this->broadcastChange('approved', (string) $actor->id));

        return response()->json(['message' => 'Revenue rule version approved.']);
    }

    public function reject(Request $request, string $rule)
    {
        $actor = $request->user();
        $this->access->authorize($actor, 'finance.rules.approve');
        $data = $request->validate(['versionId' => 'required|uuid|exists:revenue_rule_versions,id', 'reason' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($data, $actor, $request, $rule) {
            $version = DB::table('revenue_rule_versions')->where('id', $data['versionId'])->where('revenue_rule_id', $rule)->lockForUpdate()->first();
            abort_unless($version, 404);
            abort_if((int) $version->created_by === (int) $actor->id, 403, 'A different finance administrator must review this rule.');
            abort_if(DB::table('revenue_rule_reviews')->where('revenue_rule_version_id', $version->id)->exists(), 409, 'This version has already been reviewed.');
            DB::table('revenue_rule_reviews')->insert(['revenue_rule_version_id' => $version->id, 'reviewed_by' => $actor->id, 'decision' => 'rejected', 'reason' => $data['reason'], 'created_at' => now()]);
            $this->audit($rule, $version->id, $actor->id, 'rejected', $request, ['reason' => $data['reason']]);
        });
        DB::afterCommit(fn () => $this->broadcastChange('rejected', (string) $actor->id));

        return response()->json(['message' => 'Revenue rule version rejected.']);
    }

    public function history(Request $request, string $rule)
    {
        $actor = $this->actor($request);
        $permissions = $this->access->permissions($this->access->role($actor));
        abort_unless(in_array('audit.view', $permissions, true) || in_array('finance.rules.manage', $permissions, true) || in_array('finance.rules.approve', $permissions, true), 403);
        abort_unless(DB::table('revenue_rules')->where('id', $rule)->exists(), 404);
        $versions = DB::table('revenue_rule_versions as v')
            ->leftJoin('revenue_rule_reviews as review', 'review.revenue_rule_version_id', '=', 'v.id')
            ->leftJoin('users as submitter', 'submitter.id', '=', 'v.created_by')
            ->leftJoin('users as editor', 'editor.id', '=', 'v.last_modified_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'review.reviewed_by')
            ->where('v.revenue_rule_id', $rule)
            ->select('v.*', 'review.decision as approval_status', 'review.reviewed_by as approved_by', 'review.reason as review_reason',
                'submitter.name as created_by_name', 'editor.name as modified_by_name', 'reviewer.name as reviewed_by_name')
            ->orderByDesc('v.version')->get();
        $audits = DB::table('revenue_rule_audits as audit')
            ->leftJoin('users as actor', 'actor.id', '=', 'audit.actor_id')
            ->where('audit.revenue_rule_id', $rule)
            ->select('audit.*', 'actor.name as actor_name')
            ->orderByDesc('audit.created_at')->get();

        return response()->json(['data' => ['versions' => $versions, 'audits' => $audits]]);
    }

    private function audit(string $rule, ?string $version, int $actor, string $action, Request $request, array $snapshot): void
    {
        DB::table('revenue_rule_audits')->insert(['revenue_rule_id' => $rule, 'revenue_rule_version_id' => $version, 'actor_id' => $actor,
            'action' => $action, 'snapshot' => json_encode($snapshot), 'ip' => $request->ip(), 'created_at' => now()]);
    }

    private function broadcastChange(string $action, string $actorId): void
    {
        try {
            event(new AdminConsoleDataChanged('revenue-rules', $action, $actorId));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
