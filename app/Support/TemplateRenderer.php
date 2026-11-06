<?php
declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface;

class TemplateRenderer
{
    public static function render(ResponseInterface $response, string $templatePath, array $data = []): ResponseInterface
    {
        $response->getBody()->write(self::capturar($templatePath, $data));

        return $response;
    }

    /**
     * Renderiza o template e devolve o HTML como string (usado também para
     * gerar o PDF da etiqueta).
     */
    public static function capturar(string $templatePath, array $data = []): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            include $templatePath;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
