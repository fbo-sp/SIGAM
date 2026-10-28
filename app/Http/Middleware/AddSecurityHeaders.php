<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() || $request->routeIs('login') || $request->routeIs('teacher-invitations.*') || $request->routeIs('password.*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if ($request->routeIs('teacher-invitations.*') || $request->routeIs('password.*')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }
        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; frame-src https://www.youtube-nocookie.com https://player.vimeo.com; img-src 'self' data:; object-src 'none'; script-src 'self'; style-src 'self'"
        );

        return $response;
    }
}
