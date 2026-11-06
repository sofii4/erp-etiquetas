<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\MaterialRepository;
use App\Services\EtiquetaService;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class EtiquetaController
{
    public function __construct(
        private readonly MaterialRepository $materiais,
        private readonly EtiquetaService $etiquetas,
    ) {
    }

    /**
     * GET /etiquetas/{cd}              → PDF inline (o navegador abre e imprime)
     * GET /etiquetas/{cd}?formato=html → pré-visualização em HTML
     */
    public function mostrar(Request $request, Response $response, array $args): Response
    {
        $cd = trim((string) $args['cd']);
        $material = ($cd === '' || strlen($cd) > 20) ? null : $this->materiais->porCodigo($cd);

        if ($material === null) {
            return Json::erro($response, 'Material não encontrado.', 404);
        }

        if (($request->getQueryParams()['formato'] ?? '') === 'html') {
            $response->getBody()->write($this->etiquetas->html($material));

            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        $arquivo = 'etiqueta-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $cd) . '.pdf';
        $response->getBody()->write($this->etiquetas->pdf($material));

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $arquivo . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
