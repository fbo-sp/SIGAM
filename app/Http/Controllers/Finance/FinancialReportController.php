<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\Dojo;
use App\Services\Finance\FinancialReportService;
use App\Support\Export\CsvWriter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialReportController extends Controller
{
    public function index(Request $request, Association $association, FinancialReportService $service): View
    {
        $filters = $this->filters($request, $association);
        $report = $service->build($association, $filters['from'], $filters['to'], $filters['dojo_id']);
        $dojos = Dojo::query()->where('association_id', $association->id)->orderBy('name')->get();

        return view('finance.reports.index', compact('association', 'report', 'filters', 'dojos'));
    }

    public function export(Request $request, Association $association, FinancialReportService $service): StreamedResponse
    {
        $filters = $this->filters($request, $association);
        $report = $service->build($association, $filters['from'], $filters['to'], $filters['dojo_id']);
        $filename = "relatorio-financeiro-{$filters['from']}-a-{$filters['to']}.csv";

        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            CsvWriter::row($output, ['Tipo', 'Data', 'Aluno', 'Dojo', 'Descrição', 'Valor', 'Situação'], ';');
            foreach ($report['charges'] as $charge) {
                CsvWriter::row($output, ['Cobrança', $charge->due_date->format('d/m/Y'), $charge->student?->person?->full_name ?? '', $charge->dojo?->name ?? '', $charge->description, $charge->final_amount, $charge->status], ';');
            }
            foreach ($report['payments'] as $payment) {
                CsvWriter::row($output, ['Pagamento', $payment->payment_date->format('d/m/Y'), $payment->charge->student?->person?->full_name ?? '', $payment->charge->dojo?->name ?? '', $payment->charge->description, $payment->amount, 'válido'], ';');
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request, Association $association): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'dojo_id' => ['nullable', 'integer', Rule::exists('dojos', 'id')->where('association_id', $association->id)],
        ]);

        return [
            'from' => $validated['from'] ?? now()->startOfMonth()->format('Y-m-d'),
            'to' => $validated['to'] ?? now()->endOfMonth()->format('Y-m-d'),
            'dojo_id' => isset($validated['dojo_id']) ? (int) $validated['dojo_id'] : null,
        ];
    }
}
