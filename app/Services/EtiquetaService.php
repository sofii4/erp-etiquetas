<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\TemplateRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Monta a etiqueta de um material: o HTML vem de templates/etiqueta.php e o
 * dompdf transforma em PDF com o tamanho exato da etiqueta.
 */
final class EtiquetaService
{
    private const PONTOS_POR_MM = 72 / 25.4;

    public function __construct(
        private readonly QrCodeService $qrCode,
        private readonly string $template,
        private readonly float $larguraMm = 100.0,
        private readonly float $alturaMm = 50.0,
    ) {
    }

    /** @param array<string, string|null> $material linha do MaterialRepository */
    public function html(array $material): string
    {
        return TemplateRenderer::capturar($this->template, [
            'material'  => $material,
            'qrCode'    => $this->qrCode->dataUri((string) $material['CD_MATERIAL']),
            'larguraMm' => $this->larguraMm,
            'alturaMm'  => $this->alturaMm,
        ]);
    }

    /** @param array<string, string|null> $material */
    public function pdf(array $material): string
    {
        $opcoes = new Options();
        // Fonte com acentos, ², ° etc. já embutida no dompdf.
        $opcoes->setDefaultFont('DejaVu Sans');
        // Nada de buscar recursos externos: o QR vai embutido como data URI.
        $opcoes->setIsRemoteEnabled(false);
        $opcoes->setChroot([dirname($this->template)]);
        // O padrão do dompdf é "screen" — aí o @media screen da pré-visualização
        // (fundo cinza, padding) entrava no PDF e empurrava a etiqueta para fora.
        $opcoes->setDefaultMediaType('print');

        $dompdf = new Dompdf($opcoes);
        $dompdf->loadHtml($this->html($material), 'UTF-8');
        $dompdf->setPaper([
            0, 0,
            $this->larguraMm * self::PONTOS_POR_MM,
            $this->alturaMm * self::PONTOS_POR_MM,
        ]);
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
