<?php
declare(strict_types=1);

/**
 * Manutenção das imagens dos materiais (MATERIAL_IMAGEM) pela linha de
 * comando. A tela também inclui, troca e remove; aqui é para carga em lote
 * ou quando não houver acesso à tela (ou direto por SQL).
 *
 * Por aqui a imagem é otimizada antes de ir para o banco: WebP de até
 * 1024 px + miniatura de 160 px, rotação de foto de celular corrigida e
 * metadados (GPS etc.) descartados.
 *
 * Cada material tem até duas imagens: posição 1 (principal, padrão) e 2.
 * Importar na 2 sem ter a 1 grava como 1; remover a 1 promove a 2.
 *
 * Uso (o arquivo precisa estar dentro do projeto, ex.: var/):
 *   docker compose exec php php scripts/imagem-material.php importar 2000100039 var/foto.jpg
 *   docker compose exec php php scripts/imagem-material.php importar 2000100039 var/foto2.jpg 2
 *   docker compose exec php php scripts/imagem-material.php remover  2000100039
 *   docker compose exec php php scripts/imagem-material.php remover  2000100039 2
 */

use App\Repositories\ImagemRepository;
use App\Repositories\MaterialRepository;
use App\Services\ImagemService;
use App\Support\OracleConnection;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($raiz)->safeLoad();

$uso = "Uso:\n  php scripts/imagem-material.php importar <CD_MATERIAL> <arquivo> [1|2]\n"
     . "  php scripts/imagem-material.php remover  <CD_MATERIAL> [1|2]\n"
     . "  (posição: 1 = principal, o padrão; 2 = segunda imagem)\n";

[$comando, $cd] = [$argv[1] ?? '', trim($argv[2] ?? '')];
$arquivo = $comando === 'importar' ? ($argv[3] ?? '') : '';
$posArg = $argv[$comando === 'importar' ? 4 : 3] ?? '1';
if (!in_array($comando, ['importar', 'remover'], true) || $cd === '' || ($comando === 'importar' && $arquivo === '')
    || !in_array($posArg, ['1', '2'], true)) {
    fwrite(STDERR, $uso);
    exit(2);
}
$posicao = (int) $posArg;

$dbConfig = require $raiz . '/config/database.php';
$db = OracleConnection::fromConfig($dbConfig);
$imagens = new ImagemRepository($db);

if ($comando === 'remover') {
    if (!$imagens->remover($cd, $posicao)) {
        fwrite(STDERR, "Material {$cd} não tem a imagem {$posicao}.\n");
        exit(1);
    }
    echo "Imagem {$posicao} de {$cd} removida." . ($posicao === 1 ? ' Se havia a 2, ela virou a principal.' : '') . "\n";
    exit(0);
}

$material = (new MaterialRepository($db, $dbConfig['schema']))->porCodigo($cd);
if ($material === null) {
    fwrite(STDERR, "Material {$cd} não existe na ESMATERI.\n");
    exit(1);
}
if (!is_file($arquivo) || !is_readable($arquivo)) {
    fwrite(STDERR, "Arquivo não encontrado: {$arquivo}\n");
    exit(1);
}

try {
    $img = (new ImagemService())->processar((string) file_get_contents($arquivo));
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$posicao = $imagens->salvar($cd, $img, posicao: $posicao);
printf(
    "Imagem %d de %s (%s) salva: %d×%d, %.1f KB → %.1f KB no banco.\n",
    $posicao,
    $cd,
    $material['DESCRICAO'],
    $img['largura'],
    $img['altura'],
    $img['bytes_original'] / 1024,
    (strlen($img['imagem']) + strlen($img['miniatura'])) / 1024
);
