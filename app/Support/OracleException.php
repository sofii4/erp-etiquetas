<?php
declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class OracleException extends RuntimeException
{
    /** Código ORA-xxxxx (ex.: 12541 = listener fora do ar). */
    public function oraCode(): int
    {
        return $this->getCode();
    }

    /** @param array{code?:int,message?:string}|false $erro retorno de oci_error() */
    public static function de(array|false $erro, string $sql = ''): self
    {
        $codigo = is_array($erro) ? (int) ($erro['code'] ?? 0) : 0;
        // A mensagem do Oracle traz uma linha "Help: https://..."; só a primeira interessa.
        $mensagem = is_array($erro) ? strtok((string) ($erro['message'] ?? ''), "\n") : 'Erro desconhecido no OCI8';

        // O SQL vai só para o log — nunca para a resposta HTTP.
        if ($sql !== '') {
            error_log(sprintf('[oracle] %s | SQL: %s', $mensagem, preg_replace('/\s+/', ' ', $sql)));
        }

        return new self((string) $mensagem, $codigo);
    }
}
