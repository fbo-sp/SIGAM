<?php

namespace App\Services\People;

use App\Models\Association;
use App\Models\Person;
use App\Services\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonService
{
    public function __construct(private readonly AuditService $audit, private readonly PersonPhotoService $photos) {}

    public function create(Association $association, array $data, ?UploadedFile $photo = null): Person
    {
        $rendered = $this->photos->prepare($photo);
        $data = Arr::except($data, ['photo', 'remove_photo']);
        if ($association->status !== 'active') {
            throw ValidationException::withMessages(['association' => 'Ative a associação antes de vincular uma nova pessoa.']);
        }

        $person = DB::transaction(function () use ($association, $data): Person {
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
        $this->photos->apply($association, $person, $rendered);

        return $person;
    }

    /** A foto é convertida antes de gravar os dados, para que um arquivo inválido não salve o restante pela metade. */
    public function update(Association $association, Person $person, array $data, ?UploadedFile $photo = null, bool $removePhoto = false): Person
    {
        $rendered = $this->photos->prepare($photo);
        $data = Arr::except($data, ['photo', 'remove_photo']);
        DB::transaction(function () use ($association, $person, $data): Person {
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
        $this->photos->apply($association, $person, $rendered, $removePhoto);

        return $person;
    }
}
