<?php

namespace App\Http\Middleware;

use App\Models\Association;
use App\Models\Dojo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $associationId = $this->associationId($request);
        abort_unless($request->user()?->hasPermission($permission, $associationId), 403);

        return $next($request);
    }

    private function associationId(Request $request): ?int
    {
        $association = $request->route('association');
        if ($association instanceof Association) {
            return $association->getKey();
        }

        $dojo = $request->route('dojo');
        if ($dojo instanceof Dojo) {
            return $dojo->association_id;
        }

        return $request->user()?->association_id;
    }
}
