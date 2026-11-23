<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ImagemRepository;
use App\Repositories\MaterialRepository;
use App\Services\ImagemService;
use App\Support\Json;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Imagem do material, guardada no banco (MATERIAL_IMAGEM).
 *
 *   GET    /api/materiais/{cd}/imagem?tam=mini|grande&v={hash}
 *   POST   /api/materiais/{cd}/imagem   (multipart, campo "imagem")
 *   DELETE /api/materiais/{cd}/imagem
 *
 * A tela envia foto da câmera ou arquivo. O ImagemService converte para WebP
 * (1024 px + miniatura de 160 px) antes de gravar.
 *
 * TODO(permissões): por enquanto qualquer usuário pode alterar a imagem.
 */
final class ImagemController
{
    public function __construct(
        private readonly ImagemRepository $imagens,
        private readonly MaterialRepository $materiais,
        private readonly ImagemService $processador,
    ) {
    }

    /** POST — inclui ou troca a imagem. Responde com o hash novo. */
    public function salvar(Request $request, Response $response, array $args): Response
    {
        $cd = self::codigo($args);
        if ($cd === null) {
            return Json::erro($response, 'Código de material inválido.', 400);
        }
        if ($this->materiais->porCodigo($cd) === null) {
            return Json::erro($response, 'Material não encontrado.', 404);
        }

        $arquivo = $request->getUploadedFiles()['imagem'] ?? null;
        if (!$arquivo instanceof UploadedFileInterface) {
            // Arquivo acima de post_max_size: o PHP descarta o corpo inteiro.
            return Json::erro($response, 'Nenhuma imagem recebida (o arquivo pode ser maior que 10 MB).', 400);
        }
        if ($arquivo->getError() !== UPLOAD_ERR_OK) {
            $grande = in_array($arquivo->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

            return Json::erro($response, $grande ? 'Imagem maior que 10 MB.' : 'Falha ao receber a imagem. Tente de novo.', $grande ? 413 : 400);
        }

        try {
            $img = $this->processador->processar((string) $arquivo->getStream());
        } catch (InvalidArgumentException $e) {
            return Json::erro($response, $e->getMessage(), 422);
        }

        $this->imagens->salvar($cd, $img);

        // Material já com o IMAGEM_HASH novo (gerado pelo trigger).
        return Json::responder($response, $this->materiais->porCodigo($cd), 201);
    }

    /** DELETE — remove a imagem do material. */
    public function remover(Request $request, Response $response, array $args): Response
    {
        $cd = self::codigo($args);
        if ($cd === null) {
            return Json::erro($response, 'Código de material inválido.', 400);
        }
        if (!$this->imagens->remover($cd)) {
            return Json::erro($response, 'Material sem imagem.', 404);
        }

        return $response->withStatus(204);
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
