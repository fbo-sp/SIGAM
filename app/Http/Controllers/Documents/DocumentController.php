<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\DocumentTypeRequest;
use App\Http\Requests\Documents\DocumentUploadRequest;
use App\Models\Association;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Person;
use App\Models\Student;
use App\Services\AuditService;
use App\Services\Documents\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request, Association $association): View
    {
        $documents = Document::query()->where('association_id', $association->id)->with(['person', 'student', 'type', 'uploader'])->when($request->filled('status'), function ($query) use ($request): void {
            match ($request->string('status')->toString()) {
                'expired' => $query->whereDate('expiration_date', '<', today()),
                'expiring' => $query->whereBetween('expiration_date', [today(), today()->addDays(30)]),
                default => null,
            };
        })->orderByRaw('expiration_date IS NULL')->orderBy('expiration_date')->latest('id')->paginate(30)->withQueryString();
        $types = DocumentType::query()->where('status', 'active')->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->orderBy('name')->get();
        $people = Person::query()->whereHas('associations', fn ($query) => $query->where('associations.id', $association->id))->orderBy('full_name')->get();
        $students = Student::query()->where('association_id', $association->id)->with('person')->orderBy('registration_number')->get();

        return view('documents.index', compact('association', 'documents', 'types', 'people', 'students'));
    }

    public function storeType(DocumentTypeRequest $request, Association $association, DocumentService $service): RedirectResponse
    {
        $service->createType($association, $request->validated());

        return back()->with('status', 'Tipo de documento criado.');
    }

    public function store(DocumentUploadRequest $request, Association $association, DocumentService $service): RedirectResponse
    {
        $service->upload($association, $request->user(), $request->safe()->except('file'), $request->file('file'));

        return back()->with('status', 'Documento enviado com segurança.');
    }

    public function download(Association $association, Document $document, AuditService $audit): StreamedResponse
    {
        $this->ensureDocument($association, $document);
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404, 'Arquivo não encontrado.');
        $audit->record('document.downloaded', 'read', $document, null, null, ['document_type_id' => $document->document_type_id]);

        return Storage::disk('local')->download($document->storage_path, $document->original_name, ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Association $association, Document $document, DocumentService $service): RedirectResponse
    {
        $this->ensureDocument($association, $document);
        $service->delete($document);

        return back()->with('status', 'Documento removido do cadastro. O histórico de auditoria foi preservado.');
    }

    private function ensureDocument(Association $association, Document $document): void
    {
        abort_unless($document->association_id === $association->id, 404);
    }
}
