<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Http\Requests\People\PersonRequest;
use App\Models\Association;
use App\Models\Person;
use App\Services\People\PersonService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonController extends Controller
{
    public function index(Request $request, Association $association): View
    {
        $people = $this->query($request, $association)->paginate(20)->withQueryString();

        return view('people.index', compact('association', 'people'));
    }

    public function export(Request $request, Association $association): StreamedResponse
    {
        $fileName = 'pessoas-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($request, $association): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Nome', 'Nome social', 'E-mail', 'Telefone', 'Cidade', 'UF', 'Situação'], ';');
            $this->query($request, $association)->chunk(500, function ($people) use ($output): void {
                foreach ($people as $person) {
                    fputcsv($output, array_map($this->csvSafe(...), [
                        $person->full_name,
                        $person->social_name,
                        $person->email,
                        $person->mobile ?: $person->phone,
                        $person->city,
                        $person->state,
                        $person->status === 'active' ? 'Ativa' : 'Inativa',
                    ]), ';');
                }
            });
            fclose($output);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(Association $association): View
    {
        return view('people.form', ['association' => $association, 'person' => new Person(['status' => 'active', 'country' => 'BR'])]);
    }

    public function store(PersonRequest $request, Association $association, PersonService $service): RedirectResponse
    {
        $person = $service->create($association, $request->validated());

        return redirect()->route('associations.people.show', [$association, $person])->with('status', 'Pessoa cadastrada com sucesso.');
    }

    public function show(Association $association, Person $person): View
    {
        $this->ensureBelongsToAssociation($association, $person);

        return view('people.show', compact('association', 'person'));
    }

    public function edit(Request $request, Association $association, Person $person): View
    {
        $this->ensureBelongsToAssociation($association, $person);
        $returnTo = $this->returnPath($request->query('voltar'));

        return view('people.form', compact('association', 'person', 'returnTo'));
    }

    public function update(PersonRequest $request, Association $association, Person $person, PersonService $service): RedirectResponse
    {
        $this->ensureBelongsToAssociation($association, $person);
        $service->update($association, $person, $request->validated());
        $returnTo = $this->returnPath($request->input('voltar'));

        return ($returnTo ? redirect($returnTo) : redirect()->route('associations.people.show', [$association, $person]))
            ->with('status', 'Dados da pessoa atualizados.');
    }

    /** Aceita apenas caminho interno, para voltar à lista de origem sem permitir redirecionamento externo. */
    private function returnPath(mixed $path): ?string
    {
        return is_string($path) && strlen($path) <= 500 && preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $path) === 1 ? $path : null;
    }

    private function query(Request $request, Association $association): Builder
    {
        $query = Person::query()
            ->whereHas('associations', fn (Builder $builder) => $builder->whereKey($association->id))
            ->orderBy('full_name');

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $digits = preg_replace('/\D/', '', $term);
            $query->where(function (Builder $builder) use ($term, $digits): void {
                $builder->where('full_name', 'like', "%{$term}%")
                    ->orWhere('social_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
                if ($digits !== '') {
                    $builder->orWhere('cpf', $digits)->orWhere('phone', 'like', "%{$digits}%")->orWhere('mobile', 'like', "%{$digits}%");
                }
            });
        }

        if (in_array($request->query('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->query('status'));
        }

        return $query;
    }

    private function ensureBelongsToAssociation(Association $association, Person $person): void
    {
        abort_unless($person->associations()->whereKey($association->id)->exists(), 404);
    }

    private function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@]/', $value) ? "'{$value}" : $value;
    }
}
