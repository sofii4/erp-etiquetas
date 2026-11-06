<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Fase 2 — movimentação de estoque por leitura de QR Code.
 *
 * Rota reservada para o front já saber onde postar. Quando for implementada,
 * a gravação NÃO deve ir direto nas tabelas do ERP sem conhecer triggers e
 * regras dele: preferir tabelas próprias em schema separado ou uma procedure
 * do ERP (ver pendências da integração).
 */
final class LeituraController
{
    /** POST /api/leituras  {cd_material, tipo: entrada|saida, quantidade} */
    public function registrar(Request $request, Response $response): Response
    {
        return Json::erro($response, 'Movimentação por QR Code será liberada na Fase 2.', 501);
    }
}
