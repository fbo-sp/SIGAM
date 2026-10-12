<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = ['association_id', 'person_id', 'student_id', 'teacher_profile_id', 'student_guardian_id', 'federation_membership_id', 'document_type_id', 'original_name', 'stored_name', 'storage_path', 'mime_type', 'file_size', 'issue_date', 'expiration_date', 'notes', 'status', 'uploaded_by'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'expiration_date' => 'date'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function studentGuardian(): BelongsTo
    {
        return $this->belongsTo(StudentGuardian::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function federationMembership(): BelongsTo
    {
        return $this->belongsTo(FederationMembership::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function expiryState(): string
    {
        if ($this->expiration_date === null) {
            return 'no_expiration';
        }
        if ($this->expiration_date->isPast()) {
            return 'expired';
        }
        if ($this->expiration_date->lte(today()->addDays(30))) {
            return 'expiring';
        }

        return 'valid';
    }
}
