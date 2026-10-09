<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminConsoleAudit;
use App\Models\KycApplication;
use App\Models\KycApplicationDocument;
use App\Models\KycApplicationEvent;
use App\Models\KycApplicationNote;
use App\Models\User;
use App\Notifications\KycApplicationDecisionNotification;
use App\Services\AdminConsoleAccess;
use App\Services\CountrySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KycApplicationController extends Controller
{
    public function __construct(private readonly AdminConsoleAccess $access, private readonly CountrySettingsService $countries) {}

    private function actor(Request $request, string $permission): User
    {
        $actor = $request->user();
        $this->access->authorize($actor, $permission);
        abort_unless($actor->currentAccessToken() instanceof \Laravel\Sanctum\PersonalAccessToken && $actor->tokenCan('admin-console'), 403, 'A console session is required.');
        return $actor;
    }

    public function index(Request $request)
    {
        $actor = $this->actor($request, 'kyc.view');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:160'], 'country' => ['nullable', 'string', 'size:2'],
            'applicant_type' => ['nullable', Rule::in(['individual', 'business'])],
            'status' => ['nullable', Rule::in(['pending', 'in_review', 'verified', 'rejected', 'resubmission_required', 'expired'])],
            'reviewer' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $this->expireDueApplications();
        $base = KycApplication::query();
        $counts = (clone $base)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $query = $base->with(['user:id,name,username,country_code,country', 'reviewer:id,name,username', 'reviewedBy:id,name,username']);
        if (! empty($filters['country'])) $query->where('country_code', strtoupper($filters['country']));
        if (! empty($filters['applicant_type'])) $query->where('applicant_type', $filters['applicant_type']);
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['reviewer'])) $query->where('assigned_reviewer_id', $filters['reviewer']);
        if (! empty($filters['from'])) $query->whereDate('submitted_at', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('submitted_at', '<=', $filters['to']);
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(function ($q) use ($term) {
                $q->where('reference', 'like', '%'.$term.'%')
                    ->orWhere('user_id', ctype_digit($term) ? (int) $term : -1)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', '%'.$term.'%')->orWhere('username', 'like', '%'.ltrim($term, '@').'%'));
            });
        }
        $applications = $query->latest('submitted_at')->paginate($filters['per_page'] ?? 25);
        $data = $applications->getCollection()->map(fn (KycApplication $application) => $this->listItem($application));
        $reviewers = [];
        if (in_array('kyc.review', $this->access->permissions($this->access->role($actor)), true)) {
            $reviewers = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
                ->where('console_status', 'Active')->where('admin_console_disabled', false)
                ->orderBy('name')->get(['id', 'name', 'username'])->map(fn (User $user) => ['id' => (string) $user->id, 'name' => $user->name, 'username' => $user->username])->all();
        }
        return response()->json(['data' => $data, 'meta' => [
            'countries' => $this->countries->catalog(),
            'current_page' => $applications->currentPage(), 'last_page' => $applications->lastPage(), 'total' => $applications->total(),
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0), 'in_review' => (int) ($counts['in_review'] ?? 0),
                'verified' => (int) ($counts['verified'] ?? 0), 'rejected' => (int) ($counts['rejected'] ?? 0),
                'resubmission_required' => (int) ($counts['resubmission_required'] ?? 0), 'expired' => (int) ($counts['expired'] ?? 0),
            ], 'reviewers' => $reviewers,
        ]]);
    }

    public function show(Request $request, KycApplication $application)
    {
        $this->actor($request, 'kyc.view');
        $application->load(['user:id,name,username,country_code,country,dob,verified', 'reviewer:id,name,username', 'reviewedBy:id,name,username', 'documents' => fn ($q) => $q->orderBy('submission_version')->orderBy('id'), 'events.admin:id,name', 'notes.admin:id,name']);
        $data = $application->applicant_data ?? [];
        unset($data['document_number'], $data['full_document_number'], $data['biometric_data']);
        return response()->json(['data' => [
            ...$this->listItem($application),
            'legalName' => $data['legal_name'] ?? null,
            'publicCreatorName' => $data['public_creator_name'] ?? $application->user?->name,
            'publicBadgeActive' => (bool) $application->user?->verified,
            'dateOfBirth' => $data['date_of_birth'] ?? $application->user?->dob,
            'residenceCountry' => $data['residence_country'] ?? $application->country_code,
            'nationality' => $data['nationality'] ?? null,
            'documentIssuingCountry' => $data['document_issuing_country'] ?? null,
            'documentType' => $data['document_type'] ?? null,
            'maskedDocumentNumber' => $application->maskedDocumentNumber(),
            'documentExpiresAt' => $application->document_expires_at?->toDateString(),
            'business' => $application->applicant_type === 'business' ? $data['business'] ?? [] : null,
            'payoutAccountName' => $data['payout_account_name'] ?? null,
            // Keep automated results readable on old records, but never reinterpret them as manual.
            'historicalAutomatedResults' => ($application->provider !== null || $application->provider_reference !== null || $application->provider_status !== 'pending'
                || $application->document_authenticity_status !== 'pending' || $application->face_match_status !== 'pending'
                || $application->liveness_status !== 'pending') ? [
                'provider' => $application->provider, 'providerReference' => $application->provider_reference,
                'providerStatus' => $application->provider_status, 'documentAuthenticityStatus' => $application->document_authenticity_status,
                'faceMatchStatus' => $application->face_match_status, 'livenessStatus' => $application->liveness_status,
            ] : null,
            'reviewMethod' => $application->review_method,
            'manualReviewer' => $application->reviewedBy?->name,
            'reviewedAt' => $application->reviewed_at?->toIso8601String(),
            'reviewReason' => $application->review_reason,
            'payoutOwnershipStatus' => $application->payout_ownership_status,
            'payoutEligibility' => $this->payoutEligibility($application),
            'creatorMessage' => $application->creator_message,
            'documents' => $application->documents->map(fn (KycApplicationDocument $document) => [
                'id' => (string) $document->id, 'kind' => $document->kind, 'capture' => $document->capture,
                'mimeType' => $document->mime_type, 'sizeBytes' => (int) $document->size_bytes,
                'submissionVersion' => (int) $document->submission_version,
                'url' => '/kyc/applications/'.$application->id.'/documents/'.$document->id,
            ])->values(),
            'history' => $application->events->map(fn ($event) => [
                'event' => $event->event, 'from' => $event->from_status, 'to' => $event->to_status,
                'message' => $event->creator_message, 'admin' => $event->admin?->name,
                'at' => $event->created_at?->toIso8601String(),
            ])->values(),
            'internalNotes' => $this->access->permissions($this->access->role($request->user())) && in_array('kyc.review', $this->access->permissions($this->access->role($request->user())), true)
                ? $application->notes->map(fn ($note) => ['id' => $note->id, 'body' => $note->body, 'admin' => $note->admin?->name, 'at' => $note->created_at?->toIso8601String()])->values()
                : [],
        ]]);
    }

    public function document(Request $request, KycApplication $application, KycApplicationDocument $document)
    {
        $actor = $this->actor($request, 'kyc.documents.view');
        abort_unless($document->application_id === $application->id, 404);
        abort_unless(Storage::disk('local')->exists($document->private_path), 404, 'Document is no longer available.');
        AdminConsoleAudit::query()->create([
            'admin_id' => $actor->id, 'action' => 'kyc.document.viewed', 'entity' => 'kyc-applications',
            'entity_id' => (string) $application->id, 'previous_value' => null, 'new_value' => ['document_id' => (string) $document->id],
            'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 2000),
        ]);
        return response()->file(Storage::disk('local')->path($document->private_path), [
            'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Content-Disposition' => 'inline',
        ]);
    }

    public function action(Request $request, KycApplication $application)
    {
        $actor = $this->actor($request, 'kyc.review');
        $input = $request->validate([
            'action' => ['required', Rule::in(['assign', 'unassign', 'begin_review', 'approve', 'reject', 'request_resubmission', 'request_reverification', 'add_note'])],
            'reviewerId' => ['required_if:action,assign', 'nullable', 'integer', 'min:1'],
            'message' => ['required_if:action,reject,request_resubmission,request_reverification', 'nullable', 'string', 'min:4', 'max:2000'],
            'note' => ['required_if:action,add_note', 'nullable', 'string', 'min:2', 'max:5000'],
            'expectedVersion' => ['required', 'integer', 'min:0'],
        ]);
        if (in_array($input['action'], ['approve', 'reject', 'request_resubmission', 'request_reverification'], true)) {
            $this->access->authorize($actor, 'kyc.documents.view');
        }
        $notify = null;
        DB::transaction(function () use ($request, $actor, $application, $input, &$notify) {
            $locked = KycApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            if ($locked->review_version !== (int) $input['expectedVersion']) {
                throw ValidationException::withMessages(['application' => 'This application changed during review. Refresh and review the latest decision.']);
            }
            if (in_array($input['action'], ['assign', 'unassign'], true)) {
                $reviewer = $input['action'] === 'assign'
                    ? User::query()->whereKey($input['reviewerId'])->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->where('console_status', 'Active')->where('admin_console_disabled', false)->firstOrFail()
                    : null;
                $before = $locked->assigned_reviewer_id;
                $locked->assigned_reviewer_id = $reviewer?->id;
                $this->recordEvent($locked, $actor, $input['action'] === 'assign' ? 'assigned' : 'unassigned', $locked->status, $locked->status);
                $this->audit($request, $actor, $locked, 'kyc.'.$input['action'], ['reviewer_id' => $before], ['reviewer_id' => $reviewer?->id]);
                $locked->review_version++;
                $locked->save();
                return;
            }
            if ($input['action'] === 'add_note') {
                KycApplicationNote::query()->create(['application_id' => $locked->id, 'admin_id' => $actor->id, 'body' => $input['note']]);
                $this->audit($request, $actor, $locked, 'kyc.note.added', null, null);
                $locked->increment('review_version');
                return;
            }
            if ($locked->assigned_reviewer_id && $locked->assigned_reviewer_id !== $actor->id && ! in_array('admins.manage', $this->access->permissions($this->access->role($actor)), true)) {
                abort(403, 'This application is assigned to another reviewer.');
            }
            $from = $locked->status;
            $message = isset($input['message']) ? $this->sanitizeCreatorReason($locked, $input['message']) : null;
            switch ($input['action']) {
                case 'begin_review':
                    abort_unless($from === 'pending', 422, 'Only pending applications can enter review.');
                    $locked->status = 'in_review';
                    break;
                case 'approve':
                    abort_unless($from === 'in_review', 422, 'Only applications in review can be approved.');
                    $this->assertManualReviewReady($locked);
                    $locked->status = 'verified';
                    $message = 'Approved after manual review.';
                    $locked->creator_message = $message;
                    $this->recordManualDecision($locked, $actor, $message);
                    break;
                case 'reject':
                    abort_unless($from === 'in_review', 422, 'Only applications in review can be rejected.');
                    $locked->status = 'rejected';
                    $locked->creator_message = $message;
                    $this->recordManualDecision($locked, $actor, $message);
                    break;
                case 'request_resubmission':
                    abort_unless($from === 'in_review', 422, 'Only applications in review can request resubmission.');
                    $locked->status = 'resubmission_required';
                    $locked->creator_message = $message;
                    $this->recordManualDecision($locked, $actor, $message);
                    break;
                case 'request_reverification':
                    abort_unless(in_array($from, ['verified', 'expired'], true), 422, 'Reverification can only be requested for verified or expired applications.');
                    $locked->status = 'resubmission_required';
                    $locked->creator_message = $message;
                    $this->recordManualDecision($locked, $actor, $message);
                    break;
            }
            $locked->review_version++;
            $locked->save();
            $this->recordEvent($locked, $actor, $input['action'], $from, $locked->status, $message);
            $this->audit($request, $actor, $locked, 'kyc.'.$input['action'], ['status' => $from], ['status' => $locked->status]);
            if (in_array($input['action'], ['approve', 'reject', 'request_resubmission', 'request_reverification'], true)) {
                $notify = [$locked->user, $locked->reference, $locked->status, $message];
            }
        });
        if ($notify) $notify[0]?->notify(new KycApplicationDecisionNotification($notify[1], $notify[2], $notify[3]));
        return response()->json(['data' => $this->listItem($application->fresh(['user', 'reviewer', 'reviewedBy']))]);
    }

    public function submit(Request $request)
    {
        $user = $request->user();
        abort_unless($user->roles()->where('name', 'creator')->exists(), 403, 'Identity verification applications are for creator accounts.');
        $countryCode = strtoupper((string) $user->country_code);
        abort_unless(strlen($countryCode) === 2, 422, 'Set a valid country of residence before applying.');
        $type = $request->input('applicant_type', 'individual');
        abort_unless(in_array($type, ['individual', 'business'], true), 422);
        $effective = $this->countries->effective($countryCode);
        $acceptedTypes = data_get($effective, 'verification.kyc.acceptedDocuments.'.$type, []);
        $requestedDocumentType = (string) $request->input('document_type', '');
        $requiredCaptures = data_get($effective, 'verification.kyc.documentCaptures.'.$type.'.'.$requestedDocumentType,
            data_get($effective, 'verification.kyc.requiredCaptures.'.$type, []));
        abort_if($acceptedTypes === [] || $requiredCaptures === [], 422, 'KYC application requirements are not configured for this country and applicant type.');
        $rules = [
            'applicant_type' => ['required', Rule::in(['individual', 'business'])],
            'legal_name' => ['required', 'string', 'max:200'], 'public_creator_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'date_of_birth' => ['required', 'date', 'before:today'], 'nationality' => ['sometimes', 'nullable', 'string', 'size:2'],
            'document_issuing_country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'document_type' => ['required', Rule::in($acceptedTypes)], 'document_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'document_expiry' => [data_get($effective, 'verification.kyc.documentExpiryRequired') ? 'required' : 'nullable', 'nullable', 'date', 'after:today'],
            'business.registered_name' => ['required_if:applicant_type,business', 'nullable', 'string', 'max:200'],
            'business.registration_number' => ['required_if:applicant_type,business', 'nullable', 'string', 'max:100'],
            'business.registration_country' => ['required_if:applicant_type,business', 'nullable', 'string', 'size:2'],
            'business.authorized_representative_name' => ['required_if:applicant_type,business', 'nullable', 'string', 'max:200'],
        ];
        foreach (['front', 'back', 'passport_photo_page', 'selfie', 'proof_of_address', 'business_registration', 'authorization', 'ownership'] as $capture) {
            $required = in_array($capture, $requiredCaptures, true)
                || ($capture === 'selfie' && data_get($effective, 'verification.kyc.selfieRequired', true));
            $mimes = in_array($capture, ['front', 'back', 'passport_photo_page', 'selfie'], true)
                ? 'jpg,jpeg,png,webp'
                : 'jpg,jpeg,png,webp,pdf';
            $rules['documents.'.$capture] = [$required ? 'required' : 'nullable', 'file', 'mimes:'.$mimes, 'max:10240'];
        }
        $data = $request->validate($rules);
        if (!$request->hasFile('documents.front') && !$request->hasFile('documents.back') && !$request->hasFile('documents.passport_photo_page')) {
            throw ValidationException::withMessages(['documents.front' => 'At least one ID document image is required.']);
        }
        $minimumAge = (int) data_get($effective, 'age.monetizationMinimum', data_get($effective, 'age.accountMinimum', 0));
        if ($minimumAge > 0 && \Carbon\Carbon::parse($data['date_of_birth'])->age < $minimumAge) {
            throw ValidationException::withMessages(['date_of_birth' => 'The configured minimum age for monetization is not met.']);
        }
        $active = KycApplication::query()->where('user_id', $user->id)->whereIn('status', ['pending', 'in_review', 'resubmission_required'])->exists();
        abort_if($active, 409, 'An identity verification application is already active.');
        $payload = [
            'legal_name' => $data['legal_name'], 'public_creator_name' => $data['public_creator_name'] ?? null,
            'date_of_birth' => $data['date_of_birth'], 'residence_country' => $countryCode,
            'nationality' => isset($data['nationality']) ? strtoupper($data['nationality']) : null,
            'document_issuing_country' => isset($data['document_issuing_country']) ? strtoupper($data['document_issuing_country']) : $countryCode,
            'document_type' => $data['document_type'], 'document_number' => $data['document_number'] ?? null,
            'document_expiry' => $data['document_expiry'] ?? null,
            'business' => $data['business'] ?? null,
        ];
        $application = DB::transaction(function () use ($user, $countryCode, $type, $payload, $data, $request) {
            $application = KycApplication::query()->create([
                'reference' => 'KYC-'.strtoupper(Str::random(10)), 'user_id' => $user->id,
                'applicant_type' => $type, 'country_code' => $countryCode, 'status' => 'pending',
                'submitted_at' => now(), 'applicant_data' => $payload,
                'document_expires_at' => $payload['document_expiry'],
            ]);
            foreach ($request->file('documents', []) as $capture => $file) {
                if (!$file) continue;
                $path = $file->storeAs('kyc/'.$application->id, Str::uuid().'.'.$file->getClientOriginalExtension(), 'local');
                $application->documents()->create([
                    'kind' => $capture === 'business_registration' ? 'business_registration' : (str_contains($capture, 'address') ? 'proof_of_address' : ($capture === 'authorization' || $capture === 'ownership' ? 'business_supporting' : 'identity')),
                    'capture' => $capture, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize(), 'private_path' => $path,
                ]);
            }
            $application->events()->create(['event' => 'submitted', 'to_status' => 'pending']);
            return $application;
        });
        return response()->json(['data' => ['reference' => $application->reference, 'status' => $application->status, 'submittedAt' => $application->submitted_at->toIso8601String()]], 201);
    }

    public function myApplication(Request $request)
    {
        $application = KycApplication::query()->where('user_id', $request->user()->id)->latest('submitted_at')->first();
        return response()->json(['data' => $application ? ['reference' => $application->reference, 'status' => $application->status,
            'statusLabel' => $application->status === 'verified' && $application->review_method === 'manual' ? 'Approved — manually reviewed' : null,
            'reviewMethod' => $application->review_method,
            'creatorMessage' => $application->creator_message, 'submittedAt' => $application->submitted_at->toIso8601String()] : null]);
    }

    public function resubmit(Request $request, KycApplication $application)
    {
        abort_unless($application->user_id === $request->user()->id, 404);
        abort_unless($application->status === 'resubmission_required', 409, 'This application is not awaiting a resubmission.');
        $effective = $this->countries->effective($application->country_code);
        $requestedDocumentType = (string) $request->input('document_type', data_get($application->applicant_data, 'document_type', ''));
        $captures = data_get($effective, 'verification.kyc.documentCaptures.'.$application->applicant_type.'.'.$requestedDocumentType,
            data_get($effective, 'verification.kyc.requiredCaptures.'.$application->applicant_type, []));
        if (data_get($effective, 'verification.kyc.selfieRequired', true)) $captures[] = 'selfie';
        $acceptedTypes = data_get($effective, 'verification.kyc.acceptedDocuments.'.$application->applicant_type, []);
        $rules = [
            'legal_name' => ['sometimes', 'string', 'max:200'],
            'date_of_birth' => ['sometimes', 'date', 'before:today'],
            'document_type' => ['sometimes', Rule::in($acceptedTypes)],
            'document_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'document_expiry' => [data_get($effective, 'verification.kyc.documentExpiryRequired') ? 'required' : 'sometimes', 'nullable', 'date', 'after:today'],
        ];
        foreach (['front', 'back', 'passport_photo_page', 'selfie', 'proof_of_address', 'business_registration', 'authorization', 'ownership'] as $capture) {
            $mimes = in_array($capture, ['front', 'back', 'passport_photo_page', 'selfie'], true) ? 'jpg,jpeg,png,webp' : 'jpg,jpeg,png,webp,pdf';
            $rules['documents.'.$capture] = [in_array($capture, $captures, true) ? 'required' : 'nullable', 'file', 'mimes:'.$mimes, 'max:10240'];
        }
        $data = $request->validate($rules);
        if (!$request->hasFile('documents.front') && !$request->hasFile('documents.back') && !$request->hasFile('documents.passport_photo_page')) {
            throw ValidationException::withMessages(['documents.front' => 'Upload at least one ID document image with the resubmission.']);
        }
        DB::transaction(function () use ($request, $application, $data) {
            $locked = KycApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'resubmission_required', 409);
            $version = (int) $locked->documents()->max('submission_version') + 1;
            foreach ($request->file('documents', []) as $capture => $file) {
                if (!$file) continue;
                $path = $file->storeAs('kyc/'.$locked->id, Str::uuid().'.'.$file->getClientOriginalExtension(), 'local');
                $locked->documents()->create(['kind' => $capture === 'business_registration' ? 'business_registration' : (str_contains($capture, 'address') ? 'proof_of_address' : 'identity'),
                    'capture' => $capture, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize(), 'private_path' => $path, 'submission_version' => $version]);
            }
            $applicantData = $locked->applicant_data ?? [];
            foreach (['legal_name' => 'legal_name', 'date_of_birth' => 'date_of_birth', 'document_type' => 'document_type', 'document_number' => 'document_number'] as $key => $source) {
                if (array_key_exists($source, $data)) $applicantData[$key] = $data[$source];
            }
            if (array_key_exists('document_expiry', $data)) {
                $applicantData['document_expiry'] = $data['document_expiry'];
                $locked->document_expires_at = $data['document_expiry'];
            }
            $locked->applicant_data = $applicantData;
            $from = $locked->status;
            $locked->status = 'pending'; $locked->submitted_at = now(); $locked->creator_message = null;
            $locked->review_method = null; $locked->reviewed_by = null; $locked->reviewed_at = null; $locked->review_reason = null;
            $locked->review_version++; $locked->save();
            $locked->events()->create(['event' => 'resubmitted', 'from_status' => $from, 'to_status' => 'pending']);
        });
        return response()->json(['data' => ['reference' => $application->reference, 'status' => 'pending']]);
    }

    private function listItem(KycApplication $application): array
    {
        $data = $application->applicant_data ?? [];
        return [
            'id' => (string) $application->id, 'reference' => $application->reference, 'userId' => (string) $application->user_id,
            'creatorName' => $application->user?->name ?? '', 'creatorHandle' => $application->user?->username ?? '',
            'legalName' => $data['legal_name'] ?? '', 'country' => $application->country_code,
            'applicantType' => $application->applicant_type, 'documentType' => $data['document_type'] ?? '',
            'maskedDocumentNumber' => $application->maskedDocumentNumber(), 'status' => $application->status,
            'submittedAt' => $application->submitted_at?->toIso8601String(), 'reviewerId' => $application->assigned_reviewer_id ? (string) $application->assigned_reviewer_id : null,
            'reviewer' => $application->reviewer?->name, 'documentExpiresAt' => $application->document_expires_at?->toDateString(),
            'reviewMethod' => $application->review_method, 'manualReviewer' => $application->reviewedBy?->name,
            'reviewedAt' => $application->reviewed_at?->toIso8601String(), 'reviewReason' => $application->review_reason,
            'payoutEligibility' => $this->payoutEligibility($application), 'reviewVersion' => $application->review_version,
        ];
    }

    private function payoutEligibility(KycApplication $application): array
    {
        $policy = $this->countries->effective($application->country_code);
        $identityRequired = (bool) data_get($policy, 'verification.withdrawalRequired', data_get($policy, 'payouts.verificationRequired', false));
        $identityPassed = $application->status === 'verified'
            && app(\App\Services\KycEligibilityService::class)->identityVerified($application->user);
        $ownershipRequired = (bool) data_get($policy, 'verification.kyc.payoutOwnershipRequired', false);
        $ownershipPassed = $application->payout_ownership_status === 'passed';
        $reasons = [];
        if ($identityRequired && !$identityPassed) $reasons[] = 'Identity verification is not current.';
        if ($ownershipRequired && !$ownershipPassed) $reasons[] = 'Payout account ownership has not been verified.';
        return ['eligible' => $reasons === [], 'identityStatus' => $application->status, 'ownershipStatus' => $application->payout_ownership_status, 'holdReasons' => $reasons];
    }

    private function assertManualReviewReady(KycApplication $application): void
    {
        $policy = $this->countries->effective($application->country_code);
        $documentType = (string) data_get($application->applicant_data, 'document_type', '');
        $captures = data_get($policy, 'verification.kyc.documentCaptures.'.$application->applicant_type.'.'.$documentType,
            data_get($policy, 'verification.kyc.requiredCaptures.'.$application->applicant_type, []));
        if (data_get($policy, 'verification.kyc.selfieRequired', true)) $captures[] = 'selfie';
        $latestVersion = (int) $application->documents()->max('submission_version');
        $availableCaptures = $application->documents()->where('submission_version', $latestVersion)->pluck('capture')->all();
        abort_unless(count(array_intersect(['front', 'back', 'passport_photo_page'], $availableCaptures)) > 0, 422, 'The latest submission is missing an ID document image.');
        foreach (array_unique($captures) as $capture) {
            abort_unless(in_array($capture, $availableCaptures, true), 422, 'The latest submission is missing a required photo or document image.');
        }
        if (data_get($policy, 'verification.kyc.documentExpiryRequired') && (!$application->document_expires_at || $application->document_expires_at->isPast())) {
            abort(422, 'A valid, unexpired identity document is required.');
        }
    }

    private function recordManualDecision(KycApplication $application, User $actor, string $reason): void
    {
        $application->review_method = 'manual';
        $application->reviewed_by = $actor->id;
        $application->reviewed_at = now();
        $application->review_reason = $reason;
    }

    private function sanitizeCreatorReason(KycApplication $application, string $message): string
    {
        $documentNumber = (string) data_get($application->applicant_data, 'document_number', '');
        if ($documentNumber !== '') $message = str_ireplace($documentNumber, '[document number hidden]', $message);
        return (string) preg_replace('/(?<![A-Za-z0-9])\d{4,}(?![A-Za-z0-9])/', '[number hidden]', $message);
    }

    private function expireDueApplications(): void
    {
        KycApplication::query()->where('status', 'verified')->whereNotNull('document_expires_at')->whereDate('document_expires_at', '<', today())
            ->chunkById(100, function ($applications) {
                foreach ($applications as $application) {
                    DB::transaction(function () use ($application) {
                        $locked = KycApplication::query()->whereKey($application->id)->lockForUpdate()->first();
                        if (!$locked || $locked->status !== 'verified' || !$locked->document_expires_at?->isPast()) return;
                        $locked->status = 'expired'; $locked->review_version++; $locked->save();
                        $locked->events()->create(['event' => 'expired', 'from_status' => 'verified', 'to_status' => 'expired']);
                    });
                }
            });
    }

    private function recordEvent(KycApplication $application, User $actor, string $event, ?string $from, ?string $to, ?string $message = null): void
    {
        KycApplicationEvent::query()->create(['application_id' => $application->id, 'admin_id' => $actor->id, 'event' => $event, 'from_status' => $from, 'to_status' => $to, 'creator_message' => $message]);
    }

    private function audit(Request $request, User $actor, KycApplication $application, string $action, ?array $before, ?array $after): void
    {
        AdminConsoleAudit::query()->create(['admin_id' => $actor->id, 'action' => $action, 'entity' => 'kyc-applications',
            'entity_id' => (string) $application->id, 'previous_value' => $before, 'new_value' => $after,
            'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 2000)]);
    }
}
