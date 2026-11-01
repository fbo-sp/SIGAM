<?php

namespace App\Support\Pwa;

/**
 * Versão do service worker (ADR-017): muda quando algum arquivo guardado pelo app muda (cada atualização do sistema
 * descompacta os arquivos de novo). O navegador vê o endereço sw.js?v=... diferente, instala o novo e apaga o cache antigo.
 */
class PwaVersion
{
    public const FILES = ['sw.js', 'offline.html', 'manifest.json', 'css/sigam.css', 'css/modules.css', 'css/direct-registration.css', 'js/common.js', 'icons/icon-192.png'];

    private static ?string $version = null;

    public static function current(): string
    {
        return self::$version ??= substr(sha1(implode('|', array_map(
            fn (string $file) => $file.':'.(@filemtime(public_path($file)) ?: 0).':'.(@filesize(public_path($file)) ?: 0),
            self::FILES,
        ))), 0, 12);
    }
}
