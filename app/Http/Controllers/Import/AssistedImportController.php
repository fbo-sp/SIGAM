<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Http\Requests\Import\ImportProcessRequest;
use App\Http\Requests\Import\ImportUploadRequest;
use App\Models\Association;
use App\Models\Dojo;
use App\Models\ImportBatch;
use App\Services\Import\AssistedImportService;
use App\Support\Export\CsvWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistedImportController extends Controller
{
    public function index(Request $request, Association $association, AssistedImportService $service): View
    {
        $dojos = $service->availableDojos($association, $request->user());
        abort_if($dojos->isEmpty(), 403);
        $batches = $service->batches($association, $request->user())->with(['dojo', 'creator'])->latest()->paginate(20);

        return view('imports.index', compact('association', 'dojos', 'batches'));
    }

    public function store(ImportUploadRequest $request, Association $association, AssistedImportService $service): RedirectResponse
    {
        $dojo = Dojo::query()->findOrFail($request->integer('dojo_id'));
        $result = $service->create($association, $dojo, $request->user(), $request->string('import_type')->toString(), $request->file('file'));

        return redirect()->route('associations.imports.show', [$association, $result['batch']])
            ->with('status', $result['replayed'] ? 'Este arquivo já havia sido enviado. Abrimos o lote existente.' : 'Arquivo analisado. Revise o resultado antes de importar.');
    }

    public function show(Request $request, Association $association, ImportBatch $batch, AssistedImportService $service): View
    {
        $service->authorizeBatch($association, $batch, $request->user());
        $batch->load(['dojo', 'creator']);
        $rows = $batch->rows()->orderBy('row_number')->paginate(50);
        $counts = $batch->rows()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $duplicates = $batch->rows()->where('status', 'duplicate')->orderBy('row_number')->limit(5000)->get();

        return view('imports.show', compact('association', 'batch', 'rows', 'counts', 'duplicates'));
    }

    public function process(ImportProcessRequest $request, Association $association, ImportBatch $batch, AssistedImportService $service): RedirectResponse
    {
        $result = $service->process($association, $batch, $request->user(), $request->boolean('confirm_reviews'), (array) $request->validated('acoes', []));
        $message = $result['completed']
            ? "Importação concluída. {$result['processed']} linha(s) processada(s) nesta etapa."
            : "{$result['processed']} linha(s) processada(s). Ainda faltam {$result['remaining']}; clique em continuar.";

        return back()->with('status', $message);
    }

    public function errors(Request $request, Association $association, ImportBatch $batch, AssistedImportService $service): StreamedResponse
    {
        $service->authorizeBatch($association, $batch, $request->user());

        return Response::streamDownload(function () use ($batch): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            CsvWriter::row($stream, ['linha', 'situacao', 'mensagem']);
            $batch->rows()->where(fn ($query) => $query->whereIn('status', ['invalid', 'failed'])->orWhere('initial_status', 'review'))
                ->orderBy('id')->chunkById(200, function ($rows) use ($stream): void {
                    foreach ($rows as $row) {
                        $label = match (true) {
                            $row->status === 'invalid' => 'Inválida',
                            $row->status === 'failed' => 'Falhou',
                            default => 'Aviso revisado',
                        };
                        CsvWriter::row($stream, [$row->row_number, $label, $row->message]);
                    }
                });
            fclose($stream);
        }, "erros-importacao-{$batch->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function template(Request $request, string $type, string $format): BinaryFileResponse
    {
        validator(compact('type', 'format'), [
            'type' => ['required', Rule::in(['students', 'attendance', 'guardians', 'complete'])],
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
        ])->validate();
        abort_if($format === 'xlsx' && $type !== 'complete', 404);
        abort_if($format === 'csv' && $type === 'complete', 404);
        $file = $format === 'xlsx' ? 'modelo_importacao_judoma.xlsx' : "modelo_importacao_{$type}.csv";
        $path = resource_path("imports/{$file}");
        abort_unless(is_file($path), 404);

        return response()->download($path, $file);
    }
}
