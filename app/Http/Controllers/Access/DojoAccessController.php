<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\AccessAdministration;
use Illuminate\Http\Request;

class DojoAccessController extends Controller
{
    public function __construct(private readonly AccessAdministration $service) {}

    public function index(Request $request, Association $association)
    {
        $this->service->authorize($request->user(), $association);
        $users = User::query()->where('association_id', $association->id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email']);
        $dojos = Dojo::query()->where('association_id', $association->id)->where('status', 'active')->orderBy('name')->get();
        $roles = Role::query()->whereIn('scope_type', ['dojo', 'teacher'])->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('association_id')->orWhere('association_id', $association->id))->get();
        $grants = DojoRoleAssignment::query()->where('association_id', $association->id)->with(['user', 'role', 'dojo'])->latest()->paginate(25);

        return view('operation.access', compact('association', 'users', 'dojos', 'roles', 'grants'));
    }

    public function store(Request $request, Association $association)
    {
        $this->service->authorize($request->user(), $association);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'dojo_id' => ['required', 'integer'], 'role_id' => ['required', 'integer'], 'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date']]);
        $this->service->grant($request->user(), $association, $data);

        return back()->with('status', 'Acesso registrado para o dojo e período escolhidos.');
    }

    public function revoke(Request $request, Association $association, DojoRoleAssignment $grant)
    {
        $this->service->revoke($request->user(), $association, $grant);

        return back()->with('status', 'Concessão revogada. Outras concessões válidas não são alteradas.');
    }
}
