<?php
declare(strict_types=1);

/**
 * Manutenção das imagens dos materiais (MATERIAL_IMAGEM). A tela só exibe;
 * incluir, trocar e remover é feito por aqui (ou direto por SQL).
 *
 * Por aqui a imagem é otimizada antes de ir para o banco: WebP de até
 * 1024 px + miniatura de 160 px, rotação de foto de celular corrigida e
 * metadados (GPS etc.) descartados.
 *
 * Uso (o arquivo precisa estar dentro do projeto, ex.: var/):
 *   docker compose exec php php scripts/imagem-material.php importar 2000100039 var/foto.jpg
 *   docker compose exec php php scripts/imagem-material.php remover  2000100039
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

$uso = "Uso:\n  php scripts/imagem-material.php importar <CD_MATERIAL> <arquivo>\n"
     . "  php scripts/imagem-material.php remover  <CD_MATERIAL>\n";

[$comando, $cd, $arquivo] = [$argv[1] ?? '', trim($argv[2] ?? ''), $argv[3] ?? ''];
if (!in_array($comando, ['importar', 'remover'], true) || $cd === '' || ($comando === 'importar' && $arquivo === '')) {
    fwrite(STDERR, $uso);
    exit(2);
}

$dbConfig = require $raiz . '/config/database.php';
$db = OracleConnection::fromConfig($dbConfig);
$imagens = new ImagemRepository($db);

if ($comando === 'remover') {
    if (!$imagens->remover($cd)) {
        fwrite(STDERR, "Material {$cd} não tem imagem.\n");
        exit(1);
    }
    echo "Imagem de {$cd} removida.\n";
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

$imagens->salvar($cd, $img);
printf(
    "Imagem de %s (%s) salva: %d×%d, %.1f KB → %.1f KB no banco.\n",
    $cd,
    $material['DESCRICAO'],
    $img['largura'],
    $img['altura'],
    $img['bytes_original'] / 1024,
    (strlen($img['imagem']) + strlen($img['miniatura'])) / 1024
);
