<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KycApplication extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'applicant_data' => 'encrypted:array',
            'provider_result' => 'encrypted:array',
            'submitted_at' => 'datetime',
            'document_expires_at' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'assigned_reviewer_id'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function documents(): HasMany { return $this->hasMany(KycApplicationDocument::class, 'application_id'); }
    public function notes(): HasMany { return $this->hasMany(KycApplicationNote::class, 'application_id'); }
    public function events(): HasMany { return $this->hasMany(KycApplicationEvent::class, 'application_id'); }

    public function maskedDocumentNumber(): ?string
    {
        $number = (string) data_get($this->applicant_data, 'document_number', '');
        return $number === '' ? null : '???? '.substr($number, -4);
    }
}
