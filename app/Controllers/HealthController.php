<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Json;
use App\Support\OracleConnection;
use App\Support\OracleException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController
{
    public function __construct(
        private readonly OracleConnection $db,
    ) {
    }

    /** GET /health — PHP + Oracle respondendo. */
    public function verificar(Request $request, Response $response): Response
    {
        try {
            $this->db->queryOne('SELECT 1 AS OK FROM DUAL');
        } catch (OracleException $e) {
            return Json::responder($response, [
                'status' => 'erro',
                'oracle' => 'ORA-' . str_pad((string) $e->oraCode(), 5, '0', STR_PAD_LEFT),
            ], 503);
        }

        return Json::responder($response, ['status' => 'ok', 'oracle' => 'ok']);
    }
}
