<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class PrivateDocumentFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        $allowed = ['pdf' => ['pdf'], 'jpeg' => ['jpg', 'jpeg'], 'png' => ['png']];
        $extension = mb_strtolower($value->getClientOriginalExtension());
        $handle = fopen($value->getRealPath(), 'rb');
        $signature = $handle ? fread($handle, 8) : '';
        if ($handle) {
            fclose($handle);
        }
        $format = match (true) {
            str_starts_with($signature, '%PDF-') => 'pdf',
            str_starts_with($signature, "\xFF\xD8\xFF") => 'jpeg',
            $signature === "\x89PNG\r\n\x1A\n" => 'png',
            default => null,
        };

        if (! in_array($extension, $allowed[$format] ?? [], true)) {
            $fail('O conteúdo do arquivo não corresponde à extensão informada.');
        }
    }
}
