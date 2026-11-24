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
 * Imagens do material (até duas), guardadas no banco (MATERIAL_IMAGEM).
 * {pos} é 1 (principal) ou 2; sem /{pos}, vale a 1.
 *
 *   GET    /api/materiais/{cd}/imagem[/{pos}]?tam=mini|grande&v={hash}
 *   POST   /api/materiais/{cd}/imagem[/{pos}]   (multipart, campo "imagem")
 *   DELETE /api/materiais/{cd}/imagem[/{pos}]   (remover a 1 promove a 2)
 *   POST   /api/materiais/{cd}/imagem/inverter  (a 2 vira a principal)
 *
 * POST e DELETE respondem com o material atualizado (IMAGEM_HASH e
 * IMAGEM2_HASH novos), para a tela não precisar adivinhar as posições.
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

    /** POST — inclui ou troca uma imagem. Responde com o material atualizado. */
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

        $this->imagens->salvar($cd, $img, posicao: self::posicao($args));

        // Material já com os hashes novos (gerados pelo trigger).
        return Json::responder($response, $this->materiais->porCodigo($cd), 201);
    }

    /** DELETE — remove uma imagem. Responde com o material atualizado. */
    public function remover(Request $request, Response $response, array $args): Response
    {
        $cd = self::codigo($args);
        if ($cd === null) {
            return Json::erro($response, 'Código de material inválido.', 400);
        }
        if (!$this->imagens->remover($cd, self::posicao($args))) {
            return Json::erro($response, 'Material sem essa imagem.', 404);
        }

        return Json::responder($response, $this->materiais->porCodigo($cd));
    }

    /** POST — troca a imagem 1 com a 2. Responde com o material atualizado. */
    public function inverter(Request $request, Response $response, array $args): Response
    {
        $cd = self::codigo($args);
        if ($cd === null) {
            return Json::erro($response, 'Código de material inválido.', 400);
        }
        if (!$this->imagens->inverter($cd)) {
            return Json::erro($response, 'O material precisa ter as duas imagens.', 409);
        }

        return Json::responder($response, $this->materiais->porCodigo($cd));
    }

    public function mostrar(Request $request, Response $response, array $args): Response
    {
        $cd = self::codigo($args);
        $q = $request->getQueryParams();
        $tamanho = ($q['tam'] ?? 'mini') === 'grande' ? 'grande' : 'mini';

        $img = $cd === null ? null : $this->imagens->ler($cd, self::posicao($args), $tamanho);
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

    /** A rota só aceita 1 ou 2 ({pos:[12]}); sem o segmento, é a principal. */
    private static function posicao(array $args): int
    {
        return (int) ($args['pos'] ?? 1) === 2 ? 2 : 1;
    }

    /** @param array<string, string> $args */
    private static function codigo(array $args): ?string
    {
        $cd = trim((string) ($args['cd'] ?? ''));

        return ($cd === '' || strlen($cd) > 20) ? null : $cd;
    }
}
