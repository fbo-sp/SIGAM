<?php

namespace App\Http\Controllers\Progression;

use App\Http\Controllers\Controller;
use App\Models\TechniqueItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ficha da técnica (imagem ou PDF) do disco privado. Exige login e, para usuário vinculado a uma associação,
 * que o item seja dela: a ficha é material de estudo de toda a associação, como o catálogo.
 */
class TechniqueSheetController extends Controller
{
    public function __invoke(Request $request, TechniqueItem $item): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($item->sheet_path && $item->status === 'active', 404);
        abort_unless($user->association_id === null || $user->association_id === $item->association_id, 404);
        abort_unless(Storage::disk('local')->exists($item->sheet_path), 404);
        $extension = pathinfo($item->sheet_path, PATHINFO_EXTENSION);

        return response()->file(Storage::disk('local')->path($item->sheet_path), [
            'Content-Type' => $item->sheet_mime,
            'Content-Disposition' => 'inline; filename="'.str($item->name)->slug().'.'.$extension.'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
