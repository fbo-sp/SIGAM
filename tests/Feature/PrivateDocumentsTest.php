<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_uploads_and_downloads_private_pdf(): void
    {
        Storage::fake('local');
        [$association, $user, $person, $type] = $this->context(['document.manage', 'document.download']);

        $this->actingAs($user)->post(route('associations.documents.store', $association), [
            'person_id' => $person->id,
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->createWithContent('identidade.pdf', "%PDF-1.4\nSIGAM"),
            'expiration_date' => now()->addYear()->format('Y-m-d'),
        ])->assertSessionHasNoErrors();

        $document = Document::query()->firstOrFail();
        Storage::disk('local')->assertExists($document->storage_path);
        $this->get(route('associations.documents.download', [$association, $document]))->assertOk()->assertDownload('identidade.pdf');
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'document.downloaded', 'association_id' => $association->id]);
    }

    public function test_file_with_false_extension_is_rejected(): void
    {
        Storage::fake('local');
        [$association, $user, $person, $type] = $this->context(['document.manage']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        $this->actingAs($user)->post(route('associations.documents.store', $association), ['person_id' => $person->id, 'document_type_id' => $type->id, 'file' => UploadedFile::fake()->createWithContent('enganoso.pdf', $png), 'expiration_date' => now()->addYear()->format('Y-m-d')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_user_cannot_access_document_from_another_association(): void
    {
        Storage::fake('local');
        [$association, $user] = $this->context(['document.download']);
        [$other, , $person, $type] = $this->context([]);
        $document = $this->document($other, $person, $type, $user);
        Storage::disk('local')->put($document->storage_path, '%PDF-1.4');

        $this->actingAs($user)->get(route('associations.documents.download', [$association, $document]))->assertNotFound();
        $this->get('/storage/'.$document->storage_path)->assertNotFound();
    }

    public function test_delete_is_logical_and_audited_without_removing_file(): void
    {
        Storage::fake('local');
        [$association, $user, $person, $type] = $this->context(['document.manage']);
        $document = $this->document($association, $person, $type, $user);
        Storage::disk('local')->put($document->storage_path, '%PDF-1.4');

        $this->actingAs($user)->delete(route('associations.documents.destroy', [$association, $document]))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('documents', ['id' => $document->id, 'status' => 'inactive']);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'document.deleted', 'entity_id' => $document->id]);
    }

    private function context(array $codes): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação '.uniqid(), 'trade_name' => 'Associação']);
        $person = Person::query()->create(['full_name' => 'Pessoa '.uniqid()]);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => now()->format('Y-m-d'), 'status' => 'active']);
        $type = DocumentType::query()->create(['association_id' => $association->id, 'name' => 'Identidade', 'requires_expiration' => true]);
        $permissions = collect($codes)->map(fn ($code) => Permission::query()->create(['code' => $code, 'description' => $code]));
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Documentos', 'code' => 'docs_'.uniqid(), 'scope_type' => 'association']);
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return [$association, $user, $person, $type];
    }

    private function document(Association $association, Person $person, DocumentType $type, User $user): Document
    {
        return Document::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'document_type_id' => $type->id, 'original_name' => 'arquivo.pdf', 'stored_name' => fake()->uuid(), 'storage_path' => 'documents/'.$association->id.'/'.$person->id.'/'.fake()->uuid(), 'mime_type' => 'application/pdf', 'file_size' => 8, 'status' => 'active', 'uploaded_by' => $user->id]);
    }
}
