<?php

namespace App\Services\People;

use App\Models\Association;
use App\Models\Person;
use App\Services\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Foto da pessoa para identificação: recortada em quadrado e regravada em JPEG nos tamanhos de lista e de ficha.
 * A regravação descarta metadados como a localização. Os arquivos ficam no disco privado e só são servidos por link assinado.
 */
class PersonPhotoService
{
    public const SIZES = ['thumb' => 96, 'medium' => 320];

    /** Regra de validação do campo "photo" nos formulários de cadastro. */
    public const RULES = ['nullable', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp'];

    private const MAX_PIXELS = 30_000_000;

    public function __construct(private readonly AuditService $audit) {}

    public static function supported(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg') && function_exists('imagecopyresampled');
    }

    public static function file(string $base, string $size): string
    {
        return "{$base}-{$size}.jpg";
    }

    /**
     * Converte a foto antes de qualquer gravação, para que um arquivo inválido não deixe o cadastro pela metade.
     *
     * @return array<string, string> conteúdo JPEG por tamanho
     */
    public function prepare(?UploadedFile $file): ?array
    {
        if ($file === null) {
            return null;
        }
        if (! self::supported()) {
            throw ValidationException::withMessages(['photo' => 'Este servidor ainda não consegue ajustar fotos (extensão GD do PHP ausente). Salve sem a foto e avise o suporte.']);
        }
        $path = $file->getRealPath();
        $info = $path ? @getimagesize($path) : false;
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['photo' => 'Envie uma foto em JPG, PNG ou WEBP.']);
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['photo' => 'A foto tem resolução muito alta. Envie uma versão menor.']);
        }
        $source = @imagecreatefromstring((string) file_get_contents($path));
        if ($source === false) {
            throw ValidationException::withMessages(['photo' => 'Não foi possível ler esta foto. Tente outra imagem.']);
        }
        $source = $this->orient($source, $path, $info[2]);
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        // Em retratos o rosto costuma ficar na parte de cima: o recorte sobe um pouco em vez de pegar o centro exato.
        $x = intdiv($width - $side, 2);
        $y = intdiv($height - $side, 4);

        $rendered = [];
        foreach (self::SIZES as $size => $pixels) {
            $target = imagecreatetruecolor($pixels, $pixels);
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            imagecopyresampled($target, $source, 0, 0, $x, $y, $pixels, $pixels, $side, $side);
            ob_start();
            imagejpeg($target, null, 85);
            $rendered[$size] = (string) ob_get_clean();
            imagedestroy($target);
        }
        imagedestroy($source);

        return $rendered;
    }

    /** Grava a foto já preparada (ou remove a atual) e audita a troca. Os arquivos antigos saem só depois do commit. */
    public function apply(Association $association, Person $person, ?array $rendered, bool $remove = false): void
    {
        if ($rendered === null && ! $remove) {
            return;
        }
        $base = null;
        if ($rendered !== null) {
            $base = "photos/{$person->id}/".Str::uuid();
            foreach ($rendered as $size => $content) {
                Storage::disk('local')->put(self::file($base, $size), $content);
            }
        }

        try {
            $old = DB::transaction(function () use ($association, $person, $base): ?string {
                $locked = Person::query()->lockForUpdate()->findOrFail($person->id);
                $old = $locked->photo_path;
                if ($old === null && $base === null) {
                    return null;
                }
                $locked->forceFill(['photo_path' => $base])->save();
                $this->audit->record($base ? 'person.photo_updated' : 'person.photo_removed', 'updated', $locked,
                    ['has_photo' => $old !== null], ['has_photo' => $base !== null], ['association_id' => $association->id]);

                return $old;
            });
        } catch (Throwable $exception) {
            $this->delete($base);

            throw $exception;
        }
        $person->setAttribute('photo_path', $base)->syncOriginalAttribute('photo_path');
        $this->delete($old);
    }

    private function delete(?string $base): void
    {
        if ($base !== null) {
            Storage::disk('local')->delete(array_map(fn (string $size) => self::file($base, $size), array_keys(self::SIZES)));
        }
    }

    /** Fotos de celular vêm deitadas com a rotação só na marcação EXIF; aplica a rotação antes de recortar. */
    private function orient(\GdImage $image, string $path, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) (@exif_read_data($path)['Orientation'] ?? 1)] ?? 0;
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }
}
