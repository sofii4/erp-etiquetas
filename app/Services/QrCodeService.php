<?php
declare(strict_types=1);

namespace App\Services;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Gera o QR Code de um material. O conteúdo é SÓ o CD_MATERIAL, sem prefixo
 * nem URL: é o que o leitor da Fase 2 vai devolver para buscar o material.
 */
final class QrCodeService
{
    public function __construct(
        private readonly int $tamanhoPx = 400,
    ) {
    }

    /** PNG em data URI, pronto para <img src="..."> (HTML e dompdf). */
    public function dataUri(string $conteudo): string
    {
        $qr = new QrCode(
            data: $conteudo,
            encoding: new Encoding('UTF-8'),
            // Medium (~15% de redundância): tolera etiqueta um pouco riscada
            // sem deixar o QR denso demais para impressora térmica.
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $this->tamanhoPx,
            // A margem branca ("quiet zone") é feita pelo layout da etiqueta.
            margin: 0,
        );

        return (new PngWriter())->write($qr)->getDataUri();
    }
}
