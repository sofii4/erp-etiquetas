<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ImagemRepository;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Imagem do material, guardada no banco (MATERIAL_IMAGEM).
 *
 *   GET /api/materiais/{cd}/imagem?tam=mini|grande&v={hash}
 *
 * Só leitura: a tela não altera imagens. Incluir, trocar ou remover é feito
 * direto no banco (ver scripts/imagem-material.php e o README).
 */
final class ImagemController
{
    public function __construct(private readonly ImagemRepository $imagens)
    {
    }

    public function mostrar(Request $request, Response $response, array $args): Response
    {
        $cd = self::codigo($args);
        $q = $request->getQueryParams();
        $tamanho = ($q['tam'] ?? 'mini') === 'grande' ? 'grande' : 'mini';

        $img = $cd === null ? null : $this->imagens->ler($cd, $tamanho);
        if ($img === null) {
            return Json::erro($response, 'Material sem imagem.', 404);
        }

        $etag = '"' . $img['HASH'] . '-' . $tamanho . '"';
        // Com ?v= igual ao hash atual, a URL muda sempre que a imagem muda:
        // pode ficar em cache "para sempre". Sem ?v=, revalida pelo ETag.
        $cache = ($q['v'] ?? '') === $img['HASH']
            ? 'public, max-age=31536000, immutable'
            : 'no-cache';

        $response = $response
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $cache)
            ->withHeader('X-Content-Type-Options', 'nosniff');

        if (trim($request->getHeaderLine('If-None-Match')) === $etag) {
            return $response->withStatus(304);
        }

        $response->getBody()->write($img['CONTEUDO']);

        return $response
            ->withHeader('Content-Type', self::tipo($img['CONTEUDO']))
            ->withHeader('Content-Length', (string) strlen($img['CONTEUDO']));
    }

    /**
     * Tipo pelo conteúdo, não pela coluna MIME: a imagem pode ter sido
     * incluída à mão por SQL. Só tipos de imagem saem daqui.
     */
    private static function tipo(string $conteudo): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($conteudo) ?: '';

        return in_array($mime, ['image/webp', 'image/jpeg', 'image/png', 'image/gif'], true)
            ? $mime
            : 'application/octet-stream';
    }

    /** @param array<string, string> $args */
    private static function codigo(array $args): ?string
    {
        $cd = trim((string) ($args['cd'] ?? ''));

        return ($cd === '' || strlen($cd) > 20) ? null : $cd;
    }
}
