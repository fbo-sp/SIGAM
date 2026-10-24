<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Services\People\PersonPhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serve a foto pelo link assinado gerado nas telas que já mostram a pessoa. Exige login e, para usuário
 * vinculado a uma associação, que a pessoa pertença a ela. Foto trocada invalida os links antigos.
 */
class PersonPhotoController extends Controller
{
    public function show(Request $request, Person $person, string $size): BinaryFileResponse
    {
        abort_unless(isset(PersonPhotoService::SIZES[$size]) && $person->photo_path, 404);
        abort_unless(hash_equals(substr(sha1($person->photo_path), 0, 12), (string) $request->query('v')), 404);
        $user = $request->user();
        abort_unless($user->association_id === null || $person->associations()->whereKey($user->association_id)->exists(), 404);
        $file = PersonPhotoService::file($person->photo_path, $size);
        abort_unless(Storage::disk('local')->exists($file), 404);

        return response()->file(Storage::disk('local')->path($file), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=21600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
