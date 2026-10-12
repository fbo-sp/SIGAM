<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\StoreUserAccountRequest;
use App\Http\Requests\Access\UpdateUserRolesRequest;
use App\Models\Association;
use App\Models\User;
use App\Services\Access\UserAccountService;
use App\Support\Access\AssociationRoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly UserAccountService $service) {}

    public function index(Request $request, Association $association): View
    {
        $users = $this->service->list($request->user(), $association, $request->only(['q', 'status']));

        return view('users.index', ['association' => $association, 'users' => $users, 'definitions' => AssociationRoleCatalog::definitions()]);
    }

    public function create(Request $request, Association $association): View
    {
        $this->service->authorize($request->user(), $association);
        $selected = $request->filled('person_id') ? $this->service->findEligiblePerson($request->user(), $association, (int) $request->query('person_id')) : null;
        $people = $selected ? collect() : $this->service->eligiblePeople($request->user(), $association, $request->query('q'));
        $preset = in_array($request->query('perfil'), AssociationRoleCatalog::codes(), true) ? [$request->query('perfil')] : [];

        return view('users.create', [
            'association' => $association,
            'selected' => $selected,
            'people' => $people,
            'preset' => $preset,
            'definitions' => AssociationRoleCatalog::definitions(),
            'grantable' => $this->service->grantableCodes($request->user(), $association),
        ]);
    }

    public function store(StoreUserAccountRequest $request, Association $association): RedirectResponse
    {
        ['user' => $user, 'sent' => $sent] = $this->service->create($request->user(), $association, $request->validated());

        return redirect()->route('associations.users.show', [$association, $user])->with('status', $sent
            ? 'Conta criada. Enviamos o convite para '.$user->email.' definir a senha.'
            : 'Conta criada, mas o e-mail de convite não pôde ser enviado. Confira o e-mail e use "Reenviar convite".');
    }

    public function show(Request $request, Association $association, User $user): View
    {
        $this->service->authorize($request->user(), $association);
        abort_unless($user->association_id === $association->id, 404);
        $user->load('person');

        return view('users.show', [
            'association' => $association,
            'account' => $user,
            'current' => $this->service->catalogRoles($user, $association)->pluck('code')->all(),
            'definitions' => AssociationRoleCatalog::definitions(),
            'grantable' => $this->service->grantableCodes($request->user(), $association),
            'systemAccount' => $this->service->isSystemAccount($user),
            'awaitsActivation' => $this->service->awaitsActivation($user),
            'isSelf' => $request->user()->id === $user->id,
        ]);
    }

    public function updateRoles(UpdateUserRolesRequest $request, Association $association, User $user): RedirectResponse
    {
        $this->service->updateRoles($request->user(), $association, $user, $request->validated('roles') ?? []);

        return back()->with('status', 'Perfis atualizados.');
    }

    public function deactivate(Request $request, Association $association, User $user): RedirectResponse
    {
        $this->service->deactivate($request->user(), $association, $user);

        return back()->with('status', 'Conta inativada. As sessões abertas foram encerradas.');
    }

    public function reactivate(Request $request, Association $association, User $user): RedirectResponse
    {
        $account = $this->service->reactivate($request->user(), $association, $user);

        return back()->with('status', $account->status === 'pending'
            ? 'Conta reativada como pendente. Reenvie o convite para a pessoa definir a senha.'
            : 'Conta reativada.');
    }

    public function resendInvitation(Request $request, Association $association, User $user): RedirectResponse
    {
        $sent = $this->service->resendActivation($request->user(), $association, $user);

        return back()->with('status', $sent ? 'Convite reenviado. O link anterior deixou de valer.' : 'Não foi possível enviar o e-mail agora. Tente novamente mais tarde.');
    }
}
