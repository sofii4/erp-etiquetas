<?php
/**
 * Leitura de QR Code da etiqueta → mostra o material.
 * Comportamento em public/assets/js/scanner.js.
 *
 * Duas formas de ler:
 *  - câmera (celular/notebook) — só funciona em HTTPS ou localhost;
 *  - campo de código, que também recebe a leitura de coletores com leitor
 *    embutido (eles "digitam" o código e mandam Enter).
 *
 * Entrada/saída de estoque ainda não grava nada: os botões ficam desativados
 * até definirmos onde a movimentação é registrada (POST /api/leituras → 501).
 */
?>
<dialog id="modal-scanner" aria-labelledby="modal-scanner-titulo"
        class="w-[calc(100%-2rem)] max-w-md rounded-3xl border border-gray-800 bg-gray-900 text-white p-0 shadow-2xl backdrop:bg-black/70">
    <div class="p-5 sm:p-6 flex flex-col gap-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="modal-scanner-titulo" class="text-xl font-black">Escanear QR Code</h2>
                <p class="text-xs text-gray-400 mt-1">Aponte a câmera para a etiqueta do material.</p>
            </div>
            <button type="button" data-fechar class="p-2 -m-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800" aria-label="Fechar">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <!-- Câmera -->
        <div data-leitura class="flex flex-col gap-3">
            <div class="scanner-area" data-area>
                <video data-video muted playsinline aria-label="Imagem da câmera"></video>
                <span class="canto tl"></span><span class="canto tr"></span>
                <span class="canto bl"></span><span class="canto br"></span>
                <span class="linha-leitura" data-linha></span>
                <p data-camera-status class="scanner-status">Abrindo a câmera…</p>
            </div>
            <button type="button" data-trocar-camera
                    class="hidden self-center px-3 py-1.5 rounded-lg border border-gray-700 text-xs font-bold text-gray-300 hover:border-blue-400 hover:text-white">
                Trocar câmera
            </button>
        </div>

        <!-- Material lido -->
        <section data-resultado class="hidden flex-col gap-4" aria-live="polite">
            <div class="flex gap-4 items-start">
                <div class="resultado-foto" data-foto>
                    <img data-foto-img alt="" width="96" height="96" decoding="async">
                    <span data-foto-vazia class="text-[10px] font-bold uppercase text-gray-500">Sem foto</span>
                </div>
                <div class="min-w-0">
                    <p data-r-codigo class="font-mono text-sm font-bold text-blue-300"></p>
                    <p data-r-descricao class="font-bold leading-snug mt-0.5 break-words"></p>
                    <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs">
                        <dt class="text-gray-500">Grupo</dt><dd data-r-grupo class="font-mono text-gray-300"></dd>
                        <dt class="text-gray-500">Unidade</dt><dd data-r-unidade class="text-gray-300"></dd>
                        <dt class="text-gray-500">Local</dt><dd data-r-local class="font-mono text-gray-300"></dd>
                    </dl>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <a data-r-etiqueta target="_blank" rel="noopener"
                   class="px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold text-center">Imprimir etiqueta</a>
                <button type="button" data-r-lista
                        class="px-4 py-2.5 rounded-xl border border-gray-700 text-gray-200 text-sm font-bold hover:border-blue-400">Ver na lista</button>
            </div>

            <div class="grid grid-cols-2 gap-3" title="Movimentação de estoque ainda não disponível">
                <button type="button" disabled class="px-4 py-2.5 rounded-xl bg-gray-800 text-gray-500 text-sm font-bold border border-gray-700 cursor-not-allowed">Entrada</button>
                <button type="button" disabled class="px-4 py-2.5 rounded-xl bg-gray-800 text-gray-500 text-sm font-bold border border-gray-700 cursor-not-allowed">Saída</button>
            </div>
            <p class="-mt-2 text-[11px] text-gray-500 text-center">Entrada e saída de estoque: em breve.</p>
        </section>

        <!-- Mensagens (erro de câmera, QR inválido, material não encontrado) -->
        <p data-mensagem role="alert" class="hidden rounded-xl border px-3 py-2 text-sm"></p>

        <button type="button" data-ler-outro
                class="hidden px-4 py-2.5 rounded-xl border border-blue-500/50 text-blue-200 text-sm font-bold hover:bg-blue-500/10">
            Ler outro QR Code
        </button>

        <!-- Digitação / coletor com leitor embutido -->
        <form data-manual class="flex flex-col gap-1.5" autocomplete="off">
            <label for="scanner-codigo" class="text-xs uppercase tracking-widest text-gray-500 font-bold">Ou digite o código</label>
            <div class="flex gap-2">
                <input id="scanner-codigo" name="cd" maxlength="20" inputmode="numeric" enterkeyhint="search"
                       placeholder="Código do material" class="campo font-mono flex-1 min-w-0">
                <button type="submit" class="px-4 rounded-xl bg-gray-800 border border-gray-700 text-sm font-bold hover:border-blue-400">Buscar</button>
            </div>
        </form>
    </div>
</dialog>
