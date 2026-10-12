<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;

class ProductionReadiness
{
    /** Returns only booleans and stable check IDs, never configuration values. */
    public function checks(): array
    {
        $key = (string) config('app.key');
        $private = realpath(config('filesystems.disks.local.root'));
        $public = realpath(public_path());
        $checks = [
            'environment_production' => app()->isProduction(),
            'debug_disabled' => ! config('app.debug'),
            'https_url' => str_starts_with((string) config('app.url'), 'https://'),
            'application_key' => str_starts_with($key, 'base64:') && strlen(base64_decode(substr($key, 7), true) ?: '') === 32,
            'secure_session_cookie' => (bool) config('session.secure'),
            'http_only_cookie' => (bool) config('session.http_only'),
            'same_site_cookie' => in_array(config('session.same_site'), ['lax', 'strict'], true),
            'mysql_database' => config('database.default') === 'mysql',
            'non_root_database_user' => ! in_array(config('database.connections.mysql.username'), [null, '', 'root'], true),
            'private_storage' => $private && $public && ! str_starts_with(strtolower($private).DIRECTORY_SEPARATOR, strtolower($public).DIRECTORY_SEPARATOR) && ! config('filesystems.disks.local.serve'),
            'storage_writable' => is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')),
            'smtp_selected' => config('mail.default') === 'smtp',
        ];
        try {
            DB::connection()->getPdo();
            $checks['database_connection'] = true;
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $checks['migrations_current'] = array_diff(array_keys($files), $migrator->getRepository()->getRan()) === [];
        } catch (\Throwable) {
            $checks['database_connection'] = false;
            $checks['migrations_current'] = false;
        }

        return $checks;
    }
}
