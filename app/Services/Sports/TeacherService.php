<?php

namespace App\Services\Sports;

use App\Models\Association;
use App\Models\MartialArt;
use App\Models\Person;
use App\Models\Rank;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

class TeacherService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(Association $association, array $data): TeacherProfile
    {
        return DB::transaction(function () use ($association, $data): TeacherProfile {
            $person = Person::query()->whereKey($data['person_id'])->whereHas('associations', fn ($query) => $query->whereKey($association->id))->firstOrFail();
            $martialArt = MartialArt::query()->whereKey($data['martial_art_id'])->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->firstOrFail();
            $rank = isset($data['rank_id']) ? Rank::query()->whereKey($data['rank_id'])->where('martial_art_id', $martialArt->id)->firstOrFail() : null;
            $profile = TeacherProfile::query()->firstOrCreate(['person_id' => $person->id], ['registration_number' => $data['registration_number'] ?? null, 'status' => 'active', 'notes' => $data['notes'] ?? null]);
            if (! $profile->wasRecentlyCreated) {
                $profile->update(['registration_number' => $data['registration_number'] ?? $profile->registration_number, 'notes' => $data['notes'] ?? $profile->notes]);
            }
            $profile->martialArts()->updateOrCreate(['martial_art_id' => $martialArt->id], ['rank_id' => $rank?->id, 'federation_registration' => $data['federation_registration'] ?? null, 'certification_expiration' => $data['certification_expiration'] ?? null, 'status' => 'active']);
            TeacherAssociationMembership::query()->firstOrCreate(['association_id' => $association->id, 'teacher_profile_id' => $profile->id], ['status' => 'active', 'source' => 'registration']);
            $this->audit->record('teacher.saved', $profile->wasRecentlyCreated ? 'created' : 'updated', $profile, null, ['status' => $profile->status], ['association_id' => $association->id]);

            return $profile;
        });
    }
}
