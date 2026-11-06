<?php
/**
 * Mockup da leitura de QR Code (Fase 2).
 *
 * Na Fase 2: trocar a área .scanner-area por uma lib de leitura pela câmera
 * (ex.: html5-qrcode), buscar o material em GET /api/materiais/{cd} e enviar
 * a movimentação para POST /api/leituras.
 */
?>
<dialog id="modal-scanner" aria-labelledby="modal-scanner-titulo"
        class="w-[calc(100%-2rem)] max-w-md rounded-3xl border border-gray-800 bg-gray-900 text-white p-0 shadow-2xl backdrop:bg-black/70">
    <div class="p-6 flex flex-col gap-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <span class="px-2 py-0.5 rounded-full bg-indigo-500/15 text-indigo-300 text-[10px] font-black uppercase tracking-wider">Em breve · Fase 2</span>
                <h2 id="modal-scanner-titulo" class="text-xl font-black mt-2">Escanear QR Code</h2>
            </div>
            <button type="button" data-fechar class="p-2 -m-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800" aria-label="Fechar">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <!-- Área onde a câmera vai aparecer -->
        <div class="scanner-area" aria-hidden="true">
            <span class="canto tl"></span><span class="canto tr"></span>
            <span class="canto bl"></span><span class="canto br"></span>
            <span class="linha-leitura"></span>
            <p class="text-xs text-gray-500 font-bold uppercase tracking-widest">Câmera</p>
        </div>

        <div class="text-sm text-gray-400 flex flex-col gap-2">
            <p>Aqui será possível apontar a câmera do celular ou coletor para a etiqueta e registrar a <strong class="text-gray-200">entrada</strong> ou a <strong class="text-gray-200">saída</strong> do material no estoque.</p>
            <p>Por enquanto, use a busca para localizar o material e imprimir a etiqueta.</p>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <button type="button" disabled class="px-4 py-2.5 rounded-xl bg-gray-800 text-gray-500 text-sm font-bold border border-gray-700 cursor-not-allowed">Entrada</button>
            <button type="button" disabled class="px-4 py-2.5 rounded-xl bg-gray-800 text-gray-500 text-sm font-bold border border-gray-700 cursor-not-allowed">Saída</button>
        </div>
    </div>
</dialog>
