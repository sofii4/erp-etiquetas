<?php
declare(strict_types=1);

/**
 * Gera imagens ILUSTRATIVAS para os materiais que ainda não têm imagem e
 * grava no banco como imagem 1/principal (MATERIAL_IMAGEM, FICTICIA = 'S'),
 * passando pelo mesmo ImagemService do upload (WebP + miniatura). A
 * imagem 2 nunca é mexida.
 *
 * O export do cliente não traz fotos: as ilustrações servem para testar o
 * layout e a performance da tabela. Cada uma leva o selo "IMAGEM
 * ILUSTRATIVA" e é desenhada a partir de palavras da DESCRICAO (parafuso,
 * porca, tubo, perfil, chapa, motorredutor, elevador...).
 *
 * Uso (dentro do container):
 *   docker compose exec php php scripts/gerar-imagens-ficticias.php
 *   docker compose exec php php scripts/gerar-imagens-ficticias.php --refazer
 *
 *   --refazer      regera também as que já são fictícias. Imagens enviadas
 *                  por usuários (FICTICIA = 'N') NUNCA são sobrescritas.
 *   --previa=DIR   só salva os PNGs em DIR (não grava no banco).
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

$dbConfig = require $raiz . '/config/database.php';
$db = OracleConnection::fromConfig($dbConfig);
$materiais = new MaterialRepository($db, $dbConfig['schema']);
$imagens = new ImagemRepository($db);
$servico = new ImagemService();
$refazer = in_array('--refazer', $argv, true);
$previa = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--previa=')) {
        $previa = rtrim(substr($arg, 9), '/');
    }
}

$existentes = $imagens->existentes();
$desenhista = new Ilustrador($raiz . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');

$geradas = 0;
$puladas = 0;
$bytes = 0;
for ($pagina = 1; ; $pagina++) {
    $lote = $materiais->buscar([], $pagina, MaterialRepository::POR_PAGINA_MAX)['dados'];
    if ($lote === []) {
        break;
    }
    foreach ($lote as $m) {
        $cd = (string) $m['CD_MATERIAL'];
        $ficticia = $existentes[$cd] ?? null;
        if ($ficticia === false || ($ficticia === true && !$refazer)) {
            $puladas++;
            continue;
        }

        $png = $desenhista->png((string) $m['DESCRICAO'], $cd);
        if ($previa !== null) {
            file_put_contents("{$previa}/{$cd}-{$desenhista->ultimaFamilia}.png", $png);
            $geradas++;
            continue;
        }
        $img = $servico->processar($png);
        $imagens->salvar($cd, $img, ficticia: true);

        $total = strlen($img['imagem']) + strlen($img['miniatura']);
        $bytes += $total;
        $geradas++;
        printf("  %-12s %-9s %6.1f KB  %s\n", $cd, $desenhista->ultimaFamilia, $total / 1024, $m['DESCRICAO']);
    }
}

printf(
    "\nGeradas: %d (%.1f KB no banco, média %.1f KB)  ·  Puladas (já tinham imagem): %d\n",
    $geradas,
    $bytes / 1024,
    $geradas ? $bytes / 1024 / $geradas : 0,
    $puladas
);

/**
 * Desenhos simples em GD, em 2× (1600 px) e reduzidos para 800 px para
 * suavizar as bordas (o GD não tem antialias em polígonos).
 */
final class Ilustrador
{
    private const S = 1600; // lado do desenho (2×)
    private const SAIDA = 800;

    public string $ultimaFamilia = '';

    /** @var GdImage */
    private $im;

    public function __construct(private readonly string $fonte)
    {
    }

    public function png(string $descricao, string $cd): string
    {
        $d = mb_strtoupper($descricao);
        // Variação estável por material (o mesmo código gera o mesmo desenho).
        mt_srand(crc32($cd));

        $metal = match (true) {
            str_contains($d, 'PRETO') => [58, 60, 66],
            str_contains($d, 'INOX') => [196, 202, 210],
            str_contains($d, 'GALV'), str_contains($d, 'ALUZINC'), str_contains($d, 'ZINC') => [178, 190, 200],
            default => [150, 156, 166],
        };

        $familias = [
            'parafuso'  => '/PARAF/',
            'porca'     => '/PORCA/',
            'arruela'   => '/ARRUELA/',
            'bucha'     => '/BUCHA/',
            'corrente'  => '/CORRENTE DE ELO/',
            'motor'     => '/MOTOR/',
            'led'       => '/LED|PROJETOR|REFLETOR/',
            'mancal'    => '/MANCAL/',
            'telha'     => '/TELHA/',
            'perfil'    => '/PERFIL/',
            'tubo'      => '/TUBO|ACO REDONDO|BARRA/',
            'silo'      => '/SILO/',
            'funil'     => '/REDUCAO|REDUÇÃO|FUNIL|MOEGA/',
            'rosca'     => '/ROSCA|TRANSPORTADOR|REDLER/',
            'elevador'  => '/ELEVADOR|CORPO|CAIXA/',
            'chapa'     => '/(^|\s)CH[.\s]|CHAPA|^\d{2,3}-[\d-]+/',
        ];
        $familia = 'peca';
        foreach ($familias as $nome => $regex) {
            if (preg_match($regex, $d)) {
                $familia = $nome;
                break;
            }
        }
        $this->ultimaFamilia = $familia;

        $this->im = imagecreatetruecolor(self::S, self::S);
        $this->fundo();

        $tinta = [[44, 96, 160], [46, 125, 90], [196, 120, 30], [70, 80, 96]][mt_rand(0, 3)];
        match ($familia) {
            'parafuso' => $this->parafuso($metal),
            'porca'    => $this->porca($metal, str_contains($d, 'TRAV')),
            'arruela'  => $this->arruela($metal),
            'bucha'    => $this->bucha(str_contains($d, 'POLIET') ? [236, 236, 228] : $metal),
            'corrente' => $this->corrente($metal),
            'motor'    => $this->motor([62, 110, 88]),
            'led'      => $this->led(),
            'mancal'   => $this->mancal([70, 78, 92], $metal),
            'telha'    => $this->telha($metal),
            'perfil'   => $this->perfil($metal),
            'tubo'     => $this->tubo($metal, str_contains($d, 'TUBO')),
            'silo'     => $this->silo($metal),
            'rosca'    => $this->transportador($tinta, $metal),
            'elevador' => $this->elevador($tinta, $metal),
            'chapa'    => $this->chapa($metal),
            'funil'    => $this->funil($metal),
            default    => $this->peca($tinta),
        };

        $this->selo();

        $saida = imagecreatetruecolor(self::SAIDA, self::SAIDA);
        imagecopyresampled($saida, $this->im, 0, 0, 0, 0, self::SAIDA, self::SAIDA, self::S, self::S);
        ob_start();
        imagepng($saida, null, 6);

        return (string) ob_get_clean();
    }

    // ── Cenário ─────────────────────────────────

    private function fundo(): void
    {
        for ($y = 0; $y < self::S; $y++) {
            $t = $y / self::S;
            imageline($this->im, 0, $y, self::S, $y, $this->cor([246 - 20 * $t, 248 - 18 * $t, 251 - 14 * $t]));
        }
    }

    private function sombra(int $cx, int $cy, int $w, int $h): void
    {
        for ($i = 0; $i < 24; $i++) {
            $f = 1 - $i / 24;
            imagefilledellipse($this->im, $cx, $cy, (int) ($w * (0.4 + 0.6 * $f)), (int) ($h * (0.4 + 0.6 * $f)),
                imagecolorallocatealpha($this->im, 20, 30, 45, 122));
        }
    }

    private function selo(): void
    {
        $txt = 'IMAGEM ILUSTRATIVA';
        $box = imagettfbbox(30, 0, $this->fonte, $txt);
        $w = $box[2] - $box[0];
        $x = self::S - $w - 70;
        $y = self::S - 60;
        imagefilledrectangle($this->im, $x - 24, $y - 52, $x + $w + 24, $y + 18, imagecolorallocatealpha($this->im, 255, 255, 255, 40));
        imagettftext($this->im, 30, 0, $x, $y, $this->cor([120, 128, 140]), $this->fonte, $txt);
    }

    // ── Primitivas ──────────────────────────────

    /** @param array{0:float,1:float,2:float} $rgb */
    private function cor(array $rgb, float $f = 1.0, int $alpha = 0): int
    {
        [$r, $g, $b] = array_map(static fn ($c) => (int) max(0, min(255, $c * $f)), $rgb);

        return imagecolorallocatealpha($this->im, $r, $g, $b, $alpha);
    }

    /** Brilho de um cilindro na posição t (0..1 ao longo do diâmetro). */
    private static function luz(float $t): float
    {
        return 0.55 + 0.45 * sin(M_PI * $t) + 0.35 * exp(-(($t - 0.3) ** 2) / 0.012);
    }

    /** Cilindro deitado (eixo horizontal). */
    private function cilH(int $x1, int $y1, int $x2, int $y2, array $rgb): void
    {
        for ($y = $y1; $y <= $y2; $y++) {
            imageline($this->im, $x1, $y, $x2, $y, $this->cor($rgb, self::luz(($y - $y1) / max(1, $y2 - $y1))));
        }
    }

    /** Cilindro em pé (eixo vertical). */
    private function cilV(int $x1, int $y1, int $x2, int $y2, array $rgb): void
    {
        for ($x = $x1; $x <= $x2; $x++) {
            imageline($this->im, $x, $y1, $x, $y2, $this->cor($rgb, self::luz(($x - $x1) / max(1, $x2 - $x1))));
        }
    }

    /** @param list<int> $pts */
    private function poli(array $pts, array $rgb, float $f = 1.0): void
    {
        imagefilledpolygon($this->im, $pts, $this->cor($rgb, $f));
    }

    /** Caixa em perspectiva oblíqua: frente + topo + lado direito. */
    private function caixa(int $x, int $y, int $w, int $h, int $dx, int $dy, array $rgb): void
    {
        $this->poli([$x, $y, $x + $dx, $y - $dy, $x + $w + $dx, $y - $dy, $x + $w, $y], $rgb, 1.18);
        $this->poli([$x + $w, $y, $x + $w + $dx, $y - $dy, $x + $w + $dx, $y + $h - $dy, $x + $w, $y + $h], $rgb, 0.72);
        imagefilledrectangle($this->im, $x, $y, $x + $w, $y + $h, $this->cor($rgb, 0.95));
    }

    // ── Famílias ────────────────────────────────

    private function parafuso(array $metal): void
    {
        $this->sombra(830, 1110, 1200, 130);
        // cabeça sextavada (vista lateral: 3 faces)
        $hx = 330; $hy = 560; $hw = 230; $hh = 480;
        $faces = [[0, 0.24, 1.25], [0.24, 0.76, 0.95], [0.76, 1, 0.62]];
        foreach ($faces as [$a, $b, $f]) {
            imagefilledrectangle($this->im, $hx, (int) ($hy + $a * $hh), $hx + $hw, (int) ($hy + $b * $hh), $this->cor($metal, $f));
        }
        // corpo + rosca
        $y1 = 690; $y2 = 910;
        $this->cilH($hx + $hw, $y1, 1330, $y2, $metal);
        for ($x = 760; $x < 1320; $x += 34) {
            imagesetthickness($this->im, 9);
            imageline($this->im, $x, $y1, $x + 24, $y2, $this->cor($metal, 0.55));
            imagesetthickness($this->im, 4);
            imageline($this->im, $x + 12, $y1, $x + 36, $y2, $this->cor($metal, 1.3));
        }
        imagesetthickness($this->im, 1);
        $this->poli([1330, $y1, 1370, $y1 + 40, 1370, $y2 - 40, 1330, $y2], $metal, 0.8);

    }

    private function hexagono(int $cx, int $cy, int $r, float $rot = 0): array
    {
        $p = [];
        for ($i = 0; $i < 6; $i++) {
            $a = $rot + M_PI / 3 * $i;
            $p[] = (int) ($cx + $r * cos($a));
            $p[] = (int) ($cy + $r * sin($a));
        }

        return $p;
    }

    private function porca(array $metal, bool $trava): void
    {
        $cx = 800; $cy = 760; $r = 470;
        $this->sombra($cx + 40, 1260, 1000, 150);
        $ext = $this->hexagono($cx, $cy, $r);
        // seis faces chanfradas, iluminadas de cima/esquerda
        for ($i = 0; $i < 6; $i++) {
            $a = M_PI / 3 * ($i + 0.5);
            $f = 0.8 + 0.35 * (-cos($a - M_PI * 1.25));
            $j = ($i + 1) % 6;
            $this->poli([$cx, $cy, $ext[$i * 2], $ext[$i * 2 + 1], $ext[$j * 2], $ext[$j * 2 + 1]], $metal, $f);
        }
        $this->poli($this->hexagono($cx, $cy, (int) ($r * 0.86)), $metal, 1.02);
        if ($trava) {
            imagefilledellipse($this->im, $cx, $cy, 560, 560, $this->cor([90, 140, 210]));
        }
        imagefilledellipse($this->im, $cx, $cy, 470, 470, $this->cor($metal, 0.55));
        for ($i = 0; $i < 6; $i++) {
            imageellipse($this->im, $cx, $cy, 440 - $i * 40, 440 - $i * 40, $this->cor($metal, 0.8));
        }
        imagefilledellipse($this->im, $cx + 15, $cy + 15, 260, 260, $this->cor([40, 44, 52]));
    }

    private function arruela(array $metal): void
    {
        $cx = 800; $cy = 760;
        $this->sombra($cx + 40, 1260, 1000, 150);
        for ($i = 0; $i < 60; $i++) {
            $d = 920 - $i * 4;
            imagefilledellipse($this->im, $cx - 30 + $i, $cy - 30 + $i, $d, $d, $this->cor($metal, 0.7 + $i / 120));
        }
        imagefilledellipse($this->im, $cx, $cy, 430, 430, $this->cor($metal, 0.6));
        // furo mostra o fundo
        imagefilledellipse($this->im, $cx + 8, $cy + 8, 400, 400, $this->cor([236, 239, 244]));
    }

    private function bucha(array $rgb): void
    {
        $this->sombra(820, 1270, 900, 150);
        $x1 = 420; $x2 = 1180; $top = 420; $base = 1180;
        $this->cilV($x1, $top, $x2, $base, $rgb);
        imagefilledellipse($this->im, 800, $base, $x2 - $x1, 220, $this->cor($rgb, 0.8));
        $this->cilV($x1, $top, $x2, $base - 2, $rgb);
        imagefilledellipse($this->im, 800, $top, $x2 - $x1, 220, $this->cor($rgb, 1.15));
        imagefilledellipse($this->im, 800, $top, 380, 110, $this->cor($rgb, 0.45));
        for ($y = $top + 140; $y < $base - 60; $y += 120) {
            imagearc($this->im, 800, $y, $x2 - $x1, 220, 0, 180, $this->cor($rgb, 0.75));
        }
    }

    private function corrente(array $metal): void
    {
        $this->sombra(800, 1080, 1300, 140);
        $y = 760;
        for ($i = 0; $i < 6; $i++) {
            $x = 260 + $i * 210;
            if ($i % 2 === 0) {
                imagefilledellipse($this->im, $x, $y, 330, 200, $this->cor($metal, 1.1));
                imagefilledellipse($this->im, $x, $y, 230, 100, $this->cor([238, 240, 245]));
                imagearc($this->im, $x, $y - 4, 300, 170, 190, 350, $this->cor($metal, 1.45));
            } else {
                $this->cilH($x - 170, $y - 34, $x + 170, $y + 34, $metal);
            }
        }
    }

    private function motor(array $tinta): void
    {
        $this->sombra(800, 1230, 1300, 160);
        // redutor (caixa) + flange + motor (cilindro com aletas) + eixo
        $this->caixa(230, 640, 400, 480, 140, 110, $tinta);
        $this->cilH(150, 820, 240, 930, [170, 175, 182]);
        $this->cilV(640, 600, 720, 1130, $tinta);
        $this->cilH(720, 640, 1340, 1090, $tinta);
        for ($x = 780; $x < 1300; $x += 46) {
            imagefilledrectangle($this->im, $x, 640, $x + 12, 1090, $this->cor($tinta, 0.62));
        }
        imagefilledellipse($this->im, 1340, 865, 150, 450, $this->cor($tinta, 0.82));
        $this->caixa(880, 520, 240, 110, 60, 40, $tinta); // caixa de ligação
    }

    private function led(): void
    {
        $this->sombra(800, 1260, 1050, 150);
        $corpo = [52, 56, 64];
        // suporte em U
        imagesetthickness($this->im, 60);
        imagearc($this->im, 800, 820, 1180, 900, 0, 180, $this->cor($corpo, 0.9));
        imagesetthickness($this->im, 1);
        $this->caixa(330, 380, 940, 760, 90, 70, $corpo);
        imagefilledrectangle($this->im, 400, 450, 1200, 1070, $this->cor([225, 228, 232]));
        for ($i = 0; $i < 4; $i++) {
            for ($j = 0; $j < 5; $j++) {
                $x = 480 + $j * 160; $y = 530 + $i * 150;
                imagefilledellipse($this->im, $x, $y, 110, 110, $this->cor([250, 214, 110]));
                imagefilledellipse($this->im, $x - 12, $y - 12, 40, 40, $this->cor([255, 250, 225]));
            }
        }
    }

    private function mancal(array $tinta, array $metal): void
    {
        $this->sombra(800, 1230, 1300, 150);
        $this->caixa(200, 980, 1200, 160, 80, 60, $tinta);
        imagefilledellipse($this->im, 800, 820, 820, 760, $this->cor($tinta, 0.95));
        imagefilledrectangle($this->im, 390, 820, 1210, 990, $this->cor($tinta, 0.95));
        imagefilledellipse($this->im, 770, 790, 700, 640, $this->cor($tinta, 1.15));
        imagefilledellipse($this->im, 800, 820, 420, 420, $this->cor($metal, 1.1));
        imagefilledellipse($this->im, 800, 820, 240, 240, $this->cor([40, 44, 52]));
        foreach ([330, 1270] as $x) {
            imagefilledellipse($this->im, $x, 1040, 110, 60, $this->cor([30, 34, 40]));
        }
    }

    private function telha(array $metal): void
    {
        $this->sombra(800, 1200, 1400, 150);
        $ondas = 5; $x0 = 180; $x1 = 1420; $base = 1060; $alt = 520;
        $passo = 6;
        for ($x = $x0; $x < $x1; $x += $passo) {
            $t = ($x - $x0) / ($x1 - $x0) * $ondas * 2 * M_PI;
            $yb = $base + 60 * sin($t);
            $f = 0.85 + 0.38 * cos($t + 0.6);
            $this->poli([$x, (int) $yb, $x + $passo + 1, (int) $yb, $x + $passo + 1 + 140, (int) ($yb - $alt), $x + 140, (int) ($yb - $alt)], $metal, $f);
        }
    }

    private function perfil(array $metal): void
    {
        $this->sombra(830, 1200, 1300, 150);
        // U visto de frente, extrudado para cima/direita
        $u = [300, 700, 420, 700, 420, 1040, 760, 1040, 760, 700, 880, 700, 880, 1120, 300, 1120];
        $dx = 520; $dy = -360;
        $n = count($u) / 2;
        $quads = [];
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            [$ax, $ay, $bx, $by] = [$u[$i * 2], $u[$i * 2 + 1], $u[$j * 2], $u[$j * 2 + 1]];
            // normal para fora (polígono em sentido horário na tela)
            [$nx, $ny] = [$by - $ay, -($bx - $ax)];
            if ($nx * $dx + $ny * $dy <= 0) {
                continue; // face de trás
            }
            $f = $ny < 0 ? 1.2 : 0.72;
            $quads[] = [($ay + $by) / 2, [$ax, $ay, $bx, $by, $bx + $dx, $by + $dy, $ax + $dx, $ay + $dy], $f];
        }
        usort($quads, static fn ($a, $b) => $b[0] <=> $a[0]);
        foreach ($quads as [, $pts, $f]) {
            $this->poli($pts, $metal, $f);
        }
        $this->poli($u, $metal, 0.95);
    }

    private function tubo(array $metal, bool $oco): void
    {
        $this->sombra(800, 1080, 1400, 140);
        $y1 = 620; $y2 = 920; $d = $y2 - $y1;
        $this->cilH(200, $y1, 1300, $y2, $metal);
        imagefilledellipse($this->im, 200, ($y1 + $y2) / 2, 130, $d, $this->cor($metal, 0.7));
        imagefilledellipse($this->im, 1300, ($y1 + $y2) / 2, 130, $d, $this->cor($metal, 1.15));
        if ($oco) {
            imagefilledellipse($this->im, 1300, ($y1 + $y2) / 2, 96, $d - 50, $this->cor($metal, 0.3));
        } else {
            for ($i = 1; $i < 5; $i++) {
                imageellipse($this->im, 1300, ($y1 + $y2) / 2, 130 - $i * 24, $d - $i * 56, $this->cor($metal, 1.0));
            }
        }
    }

    private function silo(array $metal): void
    {
        $this->sombra(800, 1380, 900, 120);
        foreach ([520, 1080] as $x) {
            imagefilledrectangle($this->im, $x - 20, 1000, $x + 20, 1380, $this->cor([90, 96, 106]));
        }
        $this->cilV(480, 300, 1120, 960, $metal);
        // cone
        for ($x = 480; $x <= 1120; $x++) {
            $t = ($x - 480) / 640;
            $yb = 960 + (int) (280 * (1 - abs($t - 0.5) * 2));
            imageline($this->im, $x, 960, $x, $yb, $this->cor($metal, self::luz($t) * 0.85));
        }
        imagefilledrectangle($this->im, 770, 1230, 830, 1320, $this->cor($metal, 0.6));
        imagefilledellipse($this->im, 800, 300, 640, 120, $this->cor($metal, 1.2));
        for ($y = 400; $y < 960; $y += 110) {
            imagearc($this->im, 800, $y, 640, 120, 0, 180, $this->cor($metal, 0.7));
        }
    }

    private function transportador(array $tinta, array $metal): void
    {
        $this->sombra(800, 1200, 1400, 140);
        foreach ([330, 1240] as $x) {
            imagefilledrectangle($this->im, $x, 900, $x + 40, 1200, $this->cor([80, 86, 96]));
        }
        $this->cilH(180, 680, 1380, 900, $metal);
        for ($x = 220; $x < 1360; $x += 90) {
            imagesetthickness($this->im, 10);
            imageline($this->im, $x, 680, $x + 60, 900, $this->cor($metal, 0.7));
        }
        imagesetthickness($this->im, 1);
        $this->caixa(150, 520, 200, 180, 50, 40, $tinta);   // boca de entrada
        $this->caixa(1260, 600, 220, 320, 50, 40, $tinta);  // cabeçote + motor
        $this->cilH(1290, 450, 1450, 590, [62, 110, 88]);
    }

    private function elevador(array $tinta, array $metal): void
    {
        $this->sombra(820, 1420, 700, 110);
        $this->caixa(620, 330, 300, 1060, 120, 90, $metal);
        for ($y = 460; $y < 1390; $y += 190) {
            imagefilledrectangle($this->im, 606, $y, 934, $y + 18, $this->cor($metal, 0.7));
        }
        $this->caixa(560, 180, 420, 200, 140, 100, $tinta); // cabeça
        $this->caixa(380, 1220, 240, 150, 60, 45, $tinta);  // pé / alimentação
    }

    private function chapa(array $metal): void
    {
        $this->sombra(820, 1170, 1300, 150);
        $e = 34; // espessura
        // Peça cortada vista de cima em perspectiva: "frente" reta embaixo e
        // contorno de trás sorteado (chanfro, recorte ou reta).
        $fx1 = 260; $fx2 = 1150; $fy = 1000;
        $dx = 260; $dy = -300;
        $tras = match (mt_rand(0, 2)) {
            0 => [[1, 1], [0.55, 1.25], [0, 1]],          // chanfro
            1 => [[1, 1], [0.7, 1], [0.6, 0.55], [0.35, 0.55], [0.25, 1], [0, 1]], // recorte
            default => [[1, 1], [0, 1]],                  // retangular
        };
        // (u, v): u ao longo da frente, v para "dentro" da chapa
        $ponto = static fn (float $u, float $v): array => [
            (int) ($fx1 + ($fx2 - $fx1) * $u + $dx * $v),
            (int) ($fy + $dy * $v),
        ];
        $sup = [$fx1, $fy, $fx2, $fy];
        foreach ($tras as [$u, $v]) {
            array_push($sup, ...$ponto($u, $v));
        }
        $this->poli([$fx1, $fy, $fx2, $fy, $fx2, $fy + $e, $fx1, $fy + $e], $metal, 0.7);
        [$tx, $ty] = $ponto(1, 1);
        $this->poli([$fx2, $fy, $tx, $ty, $tx, $ty + $e, $fx2, $fy + $e], $metal, 0.55);
        $this->poli($sup, $metal, 1.1);

        $furos = mt_rand(2, 6);
        for ($i = 0; $i < $furos; $i++) {
            [$x, $y] = $ponto(0.12 + 0.76 * $i / max(1, $furos - 1), $i % 2 ? 0.25 : 0.4);
            $d = mt_rand(50, 100);
            imagefilledellipse($this->im, $x, $y, $d, (int) ($d * 0.45), $this->cor($metal, 0.35));
        }
    }

    private function funil(array $metal): void
    {
        $this->sombra(800, 1250, 1000, 140);
        // flange de cima, tronco de pirâmide e boca de baixo
        $this->poli([380, 520, 1220, 520, 1040, 1080, 560, 1080], $metal, 0.95);
        $this->poli([1220, 520, 1340, 430, 1120, 1010, 1040, 1080], $metal, 0.68);
        $this->caixa(340, 470, 880, 60, 130, 100, $metal);
        $this->caixa(560, 1080, 480, 120, 70, 55, $metal);
        foreach ([480, 680, 880, 1080] as $x) {
            imagefilledellipse($this->im, $x, 500, 36, 20, $this->cor($metal, 0.4));
        }
    }

    private function peca(array $tinta): void
    {
        $this->sombra(800, 1230, 1200, 150);
        $this->caixa(380, 560, 760, 620, 200, 160, $tinta);
        imagefilledrectangle($this->im, 520, 760, 1000, 900, $this->cor([240, 240, 236]));
        imagefilledrectangle($this->im, 540, 790, 860, 812, $this->cor([120, 124, 132]));
        imagefilledrectangle($this->im, 540, 840, 760, 856, $this->cor([160, 164, 172]));
    }
}
