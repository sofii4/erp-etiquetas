<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\MaterialRepository;
use App\Support\Json;
use App\Support\OracleException;
use App\Support\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MaterialController
{
    public function __construct(
        private readonly MaterialRepository $materiais,
        private readonly string $templates = '',
        private readonly string $appName = '',
    ) {
    }

    /** GET /materiais — a tela; os dados vêm depois pela API. */
    public function index(Request $request, Response $response): Response
    {
        try {
            $grupos = $this->materiais->listarGrupos();
        } catch (OracleException) {
            // A tela abre mesmo com o banco fora; a busca mostra o erro.
            $grupos = [];
        }

        return TemplateRenderer::render($response, $this->templates . '/materiais.php', [
            'appName' => $this->appName,
            'grupos'  => $grupos,
        ])->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** GET /api/materiais?codigo=&descricao=&grupo=&subgrupo=&pagina=&por_pagina= */
    public function listar(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();

        $filtros = [];
        foreach (['codigo' => 20, 'descricao' => 60, 'grupo' => 3, 'subgrupo' => 3] as $campo => $max) {
            $valor = trim((string) ($q[$campo] ?? ''));
            if (mb_strlen($valor) > $max) {
                return Json::erro($response, "Filtro '{$campo}' aceita no máximo {$max} caracteres.", 400);
            }
            $filtros[$campo] = $valor;
        }

        $resultado = $this->materiais->buscar(
            $filtros,
            (int) ($q['pagina'] ?? 1),
            (int) ($q['por_pagina'] ?? 20),
        );

        return Json::responder($response, $resultado);
    }

    /** GET /api/materiais/{cd} — também é o que o leitor de QR da Fase 2 vai chamar. */
    public function detalhe(Request $request, Response $response, array $args): Response
    {
        $cd = trim((string) $args['cd']);
        if ($cd === '' || strlen($cd) > 20) {
            return Json::erro($response, 'Código de material inválido.', 400);
        }

        $material = $this->materiais->porCodigo($cd);
        if ($material === null) {
            return Json::erro($response, 'Material não encontrado.', 404);
        }

        return Json::responder($response, $material);
    }

    /** GET /api/grupos */
    public function grupos(Request $request, Response $response): Response
    {
        return Json::responder($response, $this->materiais->listarGrupos());
    }

    /** GET /api/grupos/{grupo}/subgrupos */
    public function subgrupos(Request $request, Response $response, array $args): Response
    {
        $grupo = trim((string) $args['grupo']);
        if ($grupo === '' || strlen($grupo) > 3) {
            return Json::erro($response, 'Grupo inválido.', 400);
        }

        return Json::responder($response, $this->materiais->listarSubGrupos($grupo));
    }
}
