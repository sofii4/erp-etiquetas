<?php
declare(strict_types=1);

namespace App\Services;

use GdImage;
use InvalidArgumentException;

/**
 * Prepara a imagem de um material para ser gravada no banco.
 *
 * Seja qual for o arquivo enviado (foto de celular de 5 MB, PNG, etc.), o
 * banco recebe sempre duas versões em WebP:
 *  - imagem:    até 1024 px no maior lado — lightbox / detalhe;
 *  - miniatura: até 160 px — a coluna da tabela (exibida a 56–64 px, então
 *               fica nítida em telas de alta densidade).
 *
 * Também corrige a rotação de fotos de celular (EXIF), descarta metadados
 * (GPS, câmera...) e nunca amplia uma imagem pequena.
 */
final class ImagemService
{
    public const BYTES_MAX = 10 * 1024 * 1024;
    /** Evita estourar a memória do PHP com imagens enormes (~25 MP = 6000×4000). */
    private const PIXELS_MAX = 25_000_000;

    private const LADO_IMAGEM = 1024;
    private const LADO_MINIATURA = 160;
    private const QUALIDADE_IMAGEM = 80;
    private const QUALIDADE_MINIATURA = 72;

    private const TIPOS_ACEITOS = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    /**
     * @return array{imagem: string, miniatura: string, mime: string, largura: int, altura: int,
     *               bytes_original: int}
     */
    public function processar(string $conteudo): array
    {
        $bytes = strlen($conteudo);
        if ($bytes === 0) {
            throw new InvalidArgumentException('Arquivo vazio.');
        }
        if ($bytes > self::BYTES_MAX) {
            throw new InvalidArgumentException('Imagem maior que 10 MB.');
        }

        // Confere o tipo pelo conteúdo, não pela extensão nem pelo Content-Type.
        $info = @getimagesizefromstring($conteudo);
        if ($info === false || !in_array($info[2], self::TIPOS_ACEITOS, true)) {
            throw new InvalidArgumentException('Formato não suportado. Envie JPG, PNG, WebP ou GIF.');
        }
        if ($info[0] * $info[1] > self::PIXELS_MAX) {
            throw new InvalidArgumentException('Imagem com resolução grande demais (máximo 25 megapixels).');
        }

        $origem = @imagecreatefromstring($conteudo);
        if ($origem === false) {
            throw new InvalidArgumentException('Não foi possível ler a imagem. O arquivo pode estar corrompido.');
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $origem = $this->corrigirOrientacao($origem, $conteudo);
        }
        if (!imageistruecolor($origem)) {
            imagepalettetotruecolor($origem);
        }

        $grande = $this->reduzir($origem, self::LADO_IMAGEM);
        $mini = $this->reduzir($origem, self::LADO_MINIATURA);

        $imagem = $this->webp($grande, self::QUALIDADE_IMAGEM);
        $miniatura = $this->webp($mini, self::QUALIDADE_MINIATURA);

        return [
            'imagem'         => $imagem,
            'miniatura'      => $miniatura,
            'mime'           => 'image/webp',
            'largura'        => imagesx($grande),
            'altura'         => imagesy($grande),
            'bytes_original' => $bytes,
        ];
    }

    /** Cabe num quadrado de $lado px, mantendo a proporção. Não amplia. */
    private function reduzir(GdImage $origem, int $lado): GdImage
    {
        $w = imagesx($origem);
        $h = imagesy($origem);
        $escala = min(1, $lado / max($w, $h));
        $nw = max(1, (int) round($w * $escala));
        $nh = max(1, (int) round($h * $escala));

        $destino = imagecreatetruecolor($nw, $nh);
        // Mantém a transparência de PNG/WebP/GIF.
        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
        imagecopyresampled($destino, $origem, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $destino;
    }

    private function webp(GdImage $img, int $qualidade): string
    {
        ob_start();
        imagewebp($img, null, $qualidade);

        return (string) ob_get_clean();
    }

    /** Fotos de celular vêm "deitadas" com uma tag EXIF dizendo como girar. */
    private function corrigirOrientacao(GdImage $img, string $conteudo): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($conteudo));
        $orientacao = (int) ($exif['Orientation'] ?? 1);

        $girada = match ($orientacao) {
            3, 4 => imagerotate($img, 180, 0),
            5, 6 => imagerotate($img, -90, 0),
            7, 8 => imagerotate($img, 90, 0),
            default => $img,
        };
        if ($girada === false) {
            return $img;
        }
        if (in_array($orientacao, [2, 4, 5, 7], true)) {
            imageflip($girada, IMG_FLIP_HORIZONTAL);
        }

        return $girada;
    }
}
