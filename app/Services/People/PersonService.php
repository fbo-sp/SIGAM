<?php

namespace App\Services\People;

use App\Models\Association;
use App\Models\Person;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(Association $association, array $data): Person
    {
        if ($association->status !== 'active') {
            throw ValidationException::withMessages(['association' => 'Ative a associação antes de vincular uma nova pessoa.']);
        }

        return DB::transaction(function () use ($association, $data): Person {
            $person = Person::query()->create($data);
            $association->people()->attach($person->id, [
                'role_type' => 'registered_person',
                'start_date' => now()->toDateString(),
                'status' => 'active',
            ]);
            $this->audit->record('person.created', 'created', $person, null, [
                'full_name' => $person->full_name,
                'status' => $person->status,
            ], ['association_id' => $association->id]);

            return $person;
        });
    }

    public function update(Association $association, Person $person, array $data): Person
    {
        return DB::transaction(function () use ($association, $person, $data): Person {
            $changedFields = array_keys(array_filter(
                $data,
                fn (mixed $value, string $key): bool => $person->getAttribute($key) != $value,
                ARRAY_FILTER_USE_BOTH,
            ));
            $oldStatus = $person->status;
            $person->update($data);
            $this->audit->record('person.updated', 'updated', $person, ['status' => $oldStatus], ['status' => $person->status], [
                'association_id' => $association->id,
                'changed_fields' => $changedFields,
            ]);

            return $person;
        });
    }
}
