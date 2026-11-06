<?php
/**
 * Etiqueta de material — o mesmo HTML vira PDF (dompdf) e pré-visualização.
 *
 * O dompdf não suporta flexbox/grid: layout em tabela, medidas em mm.
 *
 * @var array<string, string|null> $material
 * @var string $qrCode    data URI do PNG
 * @var float  $larguraMm
 * @var float  $alturaMm
 */
$margem  = 2.5;                                  // mm — também é a "quiet zone" do QR
$util    = $larguraMm - 2 * $margem;              // largura útil
$alturaU = $alturaMm - 2 * $margem;               // altura útil
$qrMm    = $alturaU - 2;                          // QR quadrado, ocupando a altura útil
$colQr   = $qrMm + 2.5;                           // coluna do QR + respiro até o texto
$colInfo = $util - $colQr;
$grupo   = trim(($material['CD_GRUPO'] ?? '') . ' / ' . ($material['CD_SUB_GRUPO'] ?? ''), ' /');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Etiqueta <?= e($material['CD_MATERIAL']) ?></title>
<style>
    /* Medidas em mm e SEM box-sizing/height:100%: o dompdf soma padding por
       fora e a etiqueta transbordava para uma 2ª página. A margem vai no body
       porque o dompdf ignora a margin do @page quando o papel vem do setPaper. */
    @page { size: <?= $larguraMm ?>mm <?= $alturaMm ?>mm; margin: 0; }
    html { margin: 0; padding: 0; }
    body { margin: <?= $margem ?>mm; padding: 0; }
    body { font-family: "DejaVu Sans", sans-serif; color: #000; }

    table.layout { width: <?= $util ?>mm; border-collapse: collapse; table-layout: fixed; }
    table.layout td { vertical-align: top; padding: 0; }
    td.qr   { width: <?= $colQr ?>mm; }
    td.info { width: <?= $colInfo ?>mm; }
    td.qr img { width: <?= $qrMm ?>mm; height: <?= $qrMm ?>mm; display: block; }

    .codigo {
        font-family: "DejaVu Sans Mono", monospace;
        font-size: 12pt;
        font-weight: bold;
        word-wrap: break-word;
        border-bottom: 0.3mm solid #000;
        padding-bottom: 0.8mm;
        margin-bottom: 1mm;
    }
    /* Até 60 bytes de DESCRICAO = no máximo 2 linhas nessa largura. */
    .descricao { font-size: 8pt; line-height: 1.15; word-wrap: break-word; }

    table.meta { width: 100%; border-collapse: collapse; margin-top: 1.2mm; font-size: 7.5pt; }
    table.meta td { padding: 0.3mm 0; vertical-align: baseline; }
    table.meta td.rotulo { width: 12mm; color: #444; }
    table.meta td.valor  { font-weight: bold; }
    .local { font-size: 10pt; }

    /* Só na pré-visualização no navegador (o PDF usa media "print") */
    @media screen {
        body { background: #e5e7eb; margin: 0; padding: 24px; }
        .etiqueta {
            width: <?= $util ?>mm; height: <?= $alturaU ?>mm; padding: <?= $margem ?>mm;
            background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.25);
        }
    }
</style>
</head>
<body>
<div class="etiqueta">
    <table class="layout">
        <tr>
            <td class="qr"><img src="<?= $qrCode ?>" alt="QR Code <?= e($material['CD_MATERIAL']) ?>"></td>
            <td class="info">
                <div class="codigo"><?= e($material['CD_MATERIAL']) ?></div>
                <div class="descricao"><?= e($material['DESCRICAO']) ?></div>
                <table class="meta">
                    <tr>
                        <td class="rotulo">Local</td>
                        <td class="valor local"><?= e($material['LOCALIZACAO'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td class="rotulo">Unidade</td>
                        <td class="valor"><?= e($material['CD_UNIDADE_MEDI'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td class="rotulo">Grupo</td>
                        <td class="valor"><?= e($grupo !== '' ? $grupo : '—') ?></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
