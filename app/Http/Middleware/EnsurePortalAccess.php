<?php

namespace App\Http\Middleware;

use App\Services\Portal\PortalAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Libera o portal para o perfil Portal e, sem passo extra, para quem é aluno ou responsável vigente (PortalAccessService::canEnter). */
class EnsurePortalAccess
{
    public function __construct(private readonly PortalAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() && $this->access->canEnter($request->user()), 403);

        return $next($request);
    }
}
