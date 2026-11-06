<?php
declare(strict_types=1);

use App\Controllers\EtiquetaController;
use App\Controllers\HealthController;
use App\Controllers\LeituraController;
use App\Controllers\MaterialController;
use App\Repositories\MaterialRepository;
use App\Services\EtiquetaService;
use App\Services\QrCodeService;
use App\Support\OracleConnection;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$raiz = dirname(__DIR__);

// No Docker as variáveis chegam pelo ambiente (env_file no compose); o .env só
// é lido aqui quando a app roda fora do container.
Dotenv\Dotenv::createImmutable($raiz)->safeLoad();
$env = static fn (string $chave, string $padrao = ''): string
    => (string) ($_ENV[$chave] ?? getenv($chave) ?: $padrao);

date_default_timezone_set($env('TZ', 'America/Sao_Paulo'));

$config   = require $raiz . '/config/app.php';
$dbConfig = require $raiz . '/config/database.php';

// ── Dependências ──────────────────────────────
// Sem container de DI: o grafo é pequeno e fica explícito aqui. A conexão com
// o Oracle só abre na primeira consulta.
$db         = OracleConnection::fromConfig($dbConfig);
$materiais  = new MaterialRepository($db, $dbConfig['schema']);
$etiquetas  = new EtiquetaService(
    new QrCodeService(),
    $raiz . '/templates/etiqueta.php',
    (float) $env('ETIQUETA_LARGURA_MM', '100'),
    (float) $env('ETIQUETA_ALTURA_MM', '50'),
);

$health    = new HealthController($db);
$material  = new MaterialController($materiais, $raiz . '/templates', $config['name']);
$etiqueta  = new EtiquetaController($materiais, $etiquetas);
$leitura   = new LeituraController();

// ── App ───────────────────────────────────────
$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware($config['debug'], true, true);

$app->get('/health', [$health, 'verificar']);

// Tela
$app->get('/', fn ($req, $res) => $res->withHeader('Location', '/materiais')->withStatus(302));
$app->get('/materiais', [$material, 'index']);

// API — o front (passo 8) e, na Fase 2, o leitor de QR usam estas rotas.
$app->get('/api/materiais', [$material, 'listar']);
$app->get('/api/materiais/{cd}', [$material, 'detalhe']);
$app->get('/api/grupos', [$material, 'grupos']);
$app->get('/api/grupos/{grupo}/subgrupos', [$material, 'subgrupos']);

// Etiqueta: PDF inline, ou ?formato=html para pré-visualizar.
$app->get('/etiquetas/{cd}', [$etiqueta, 'mostrar']);

// Fase 2: movimentação por QR Code (por enquanto responde 501).
$app->post('/api/leituras', [$leitura, 'registrar']);

$app->run();
