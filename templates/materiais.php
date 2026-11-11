<?php
/**
 * Consulta de materiais (ESMATERI) + impressão de etiqueta.
 * Os dados vêm de /api/materiais via public/assets/js/materiais.js.
 *
 * @var string        $appName
 * @var list<string>  $grupos   preenchidos no servidor para o select já abrir pronto
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Materiais e Etiquetas · <?= e($appName) ?></title>
    <script src="<?= asset('/assets/js/tailwind.js') ?>"></script>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
    <script src="<?= asset('/assets/js/materiais.js') ?>" defer></script>
</head>
<body class="bg-gray-950 text-white min-h-screen">
<div class="max-w-7xl mx-auto p-4 md:p-6 lg:p-8 flex flex-col gap-6">

    <!-- Cabeçalho -->
    <header class="relative overflow-hidden rounded-3xl border border-gray-800 bg-gradient-to-br from-gray-900 via-gray-900 to-blue-950 shadow-2xl">
        <div class="relative p-6 md:p-8 flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-widest text-blue-300 font-bold">Estoque · <?= e($appName) ?></p>
                <h1 class="text-2xl md:text-4xl font-black text-white mt-1">Materiais e Etiquetas</h1>
                <p class="text-sm text-gray-400 mt-2 max-w-2xl">
                    Encontre o material e imprima a etiqueta com QR Code. O QR guarda o código do material.
                </p>
            </div>

            <!-- Fase 2: leitura de QR Code. Fica visível para o usuário saber onde
                 vai estar; abre só um mockup explicativo. -->
            <button type="button" id="btn-scanner"
                    class="self-start lg:self-auto inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-dashed border-gray-600 bg-gray-900/60 text-gray-400 text-sm font-bold hover:border-blue-400 hover:text-gray-200 transition"
                    aria-haspopup="dialog" aria-controls="modal-scanner">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/>
                    <path d="M7 12h10"/>
                </svg>
                Escanear QR Code
            </button>
        </div>
    </header>

    <!-- Filtros -->
    <form id="filtros" class="rounded-2xl border border-gray-800 bg-gray-900 p-4 md:p-5" autocomplete="off">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-4">
            <label class="lg:col-span-3 flex flex-col gap-1.5">
                <span class="text-xs uppercase tracking-widest text-gray-500 font-bold">Código</span>
                <input name="codigo" maxlength="20" inputmode="numeric" placeholder="Começa com… ex.: 0104"
                       class="campo font-mono">
            </label>
            <label class="lg:col-span-5 flex flex-col gap-1.5">
                <span class="text-xs uppercase tracking-widest text-gray-500 font-bold">Descrição</span>
                <input name="descricao" maxlength="60" placeholder="Contém… ex.: parafuso" class="campo">
            </label>
            <label class="lg:col-span-2 flex flex-col gap-1.5">
                <span class="text-xs uppercase tracking-widest text-gray-500 font-bold">Grupo</span>
                <select name="grupo" class="campo">
                    <option value="">Todos</option>
                    <?php foreach ($grupos as $g): ?>
                        <option value="<?= e($g) ?>"><?= e($g) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="lg:col-span-2 flex flex-col gap-1.5">
                <span class="text-xs uppercase tracking-widest text-gray-500 font-bold">Subgrupo</span>
                <select name="subgrupo" class="campo" disabled>
                    <option value="">Todos</option>
                </select>
            </label>
        </div>
        <div class="flex flex-wrap items-center gap-3 mt-4">
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold transition">
                Buscar
            </button>
            <button type="reset" class="px-5 py-2.5 rounded-xl bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm font-bold border border-gray-700 transition">
                Limpar
            </button>
            <p id="resumo" class="text-sm text-gray-500 sm:ml-auto" aria-live="polite"></p>
        </div>
    </form>

    <!-- Resultados -->
    <section class="rounded-2xl border border-gray-800 bg-gray-900 overflow-hidden" aria-labelledby="titulo-resultados">
        <h2 id="titulo-resultados" class="sr-only">Resultados</h2>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-950/60 text-left text-xs uppercase tracking-widest text-gray-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-bold">Código</th>
                        <th scope="col" class="px-4 py-3 font-bold">Descrição</th>
                        <th scope="col" class="hidden md:table-cell px-4 py-3 font-bold whitespace-nowrap">Grupo / Sub</th>
                        <th scope="col" class="hidden md:table-cell px-4 py-3 font-bold">Un.</th>
                        <th scope="col" class="hidden md:table-cell px-4 py-3 font-bold">Localização</th>
                        <th scope="col" class="hidden md:table-cell px-4 py-3 font-bold">Cadastro</th>
                        <th scope="col" class="px-4 py-3 font-bold text-right">Etiqueta</th>
                    </tr>
                </thead>
                <tbody id="linhas" class="divide-y divide-gray-800"></tbody>
            </table>
        </div>

        <!-- Estados: carregando / vazio / erro (o JS mostra um por vez) -->
        <div id="estado" class="hidden px-4 py-12 text-center text-sm text-gray-400"></div>

        <nav id="paginacao" class="hidden flex items-center justify-between gap-3 px-4 py-3 border-t border-gray-800 text-sm" aria-label="Paginação">
            <button type="button" data-pagina="anterior" class="pag-btn">← Anterior</button>
            <span id="pagina-info" class="text-gray-400"></span>
            <button type="button" data-pagina="proxima" class="pag-btn">Próxima →</button>
        </nav>
    </section>
</div>

<?php include __DIR__ . '/partials/modal-scanner.php'; ?>
</body>
</html>
