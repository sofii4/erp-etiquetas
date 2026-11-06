<?php
declare(strict_types=1);

/**
 * Configuração do Oracle, lida do .env.
 *
 * DB_SCHEMA é o dono da ESMATERI ("db_tst" no ERP). A aplicação conecta com
 * outro usuário (DB_USER, só leitura) e sempre qualifica a tabela com o schema.
 */
$env = static fn (string $chave, string $padrao = ''): string
    => (string) ($_ENV[$chave] ?? getenv($chave) ?: $padrao);

return [
    'host'    => $env('DB_HOST', 'oracle'),
    'port'    => $env('DB_PORT', '1521'),
    'service' => $env('DB_SERVICE', 'XEPDB1'),
    'user'    => $env('DB_USER'),
    'pass'    => $env('DB_PASS'),
    'schema'  => $env('DB_SCHEMA', 'db_tst'),
    'charset' => 'AL32UTF8',
];
