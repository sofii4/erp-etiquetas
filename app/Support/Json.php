<?php
declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface;

final class Json
{
    public static function responder(ResponseInterface $response, mixed $dados, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(
            json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    public static function erro(ResponseInterface $response, string $mensagem, int $status): ResponseInterface
    {
        return self::responder($response, ['erro' => $mensagem], $status);
    }
}
