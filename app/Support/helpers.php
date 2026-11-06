<?php
declare(strict_types=1);

/**
 * Escapa texto para HTML. Usar em TODO valor vindo do banco nos templates.
 */
function e(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * URL versionada de um asset de /public (igual ao projeto de chamados): o
 * sufixo ?v={mtime} muda quando o arquivo muda e invalida o cache do navegador.
 */
function asset(string $caminho): string
{
    static $versoes = [];

    if (isset($versoes[$caminho])) {
        return $versoes[$caminho];
    }

    $arquivo = dirname(__DIR__, 2) . '/public' . $caminho;
    $mtime = is_file($arquivo) ? filemtime($arquivo) : false;

    return $versoes[$caminho] = $mtime === false ? $caminho : $caminho . '?v=' . $mtime;
}
