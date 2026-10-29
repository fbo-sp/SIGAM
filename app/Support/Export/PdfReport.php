<?php

namespace App\Support\Export;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

/**
 * PDF dos relatórios (Dompdf, só PHP): folha A4 a partir de uma view Blade, com rodapé "Gerado em ... por ..." e
 * "Página N de M" em todas as páginas. Sem acesso a arquivos ou endereços externos e sem PHP embutido.
 */
class PdfReport
{
    /** Extensões que o Dompdf usa; o comando de produção informa quando faltam. */
    public static function supported(): bool
    {
        return extension_loaded('dom') && extension_loaded('mbstring');
    }

    public function download(string $view, array $data, string $filename, string $footer, bool $landscape = false): Response
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false)->setIsPhpEnabled(false)->setIsJavascriptEnabled(false)
            ->setDefaultFont('DejaVu Sans')->setChroot([resource_path('views/reports/pdf')])->setTempDir(sys_get_temp_dir());
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view($view, $data)->render(), 'UTF-8');
        $pdf->setPaper('A4', $landscape ? 'landscape' : 'portrait');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $bottom = $canvas->get_height() - 24;
        $canvas->page_text(34, $bottom, $footer, $font, 7, [0.47, 0.47, 0.47]);
        $canvas->page_text($canvas->get_width() - 100, $bottom, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7, [0.47, 0.47, 0.47]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
