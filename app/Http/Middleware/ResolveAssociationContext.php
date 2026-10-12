<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\AssociationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ResolveAssociationContext
{
    public function __construct(private readonly AssociationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $association = $request->user()?->association;

        if ($association !== null && $association->status !== 'active') {
            abort(403, 'A associação vinculada a esta conta não está ativa.');
        }

        $this->context->set($association);
        View::share('currentAssociation', $association);

        return $next($request);
    }
}
