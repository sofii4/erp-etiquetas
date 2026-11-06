<?php

declare(strict_types=1);

$env = static fn (string $chave, string $padrao = ''): string
    => (string) ($_ENV[$chave] ?? getenv($chave) ?: $padrao);

return [
    'name'  => $env('APP_NAME', 'Etiquetas ERP'),
    'env'   => $env('APP_ENV', 'development'),
    'debug' => filter_var($env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOL),
    'url'   => $env('APP_URL', 'http://localhost:8190'),
];
