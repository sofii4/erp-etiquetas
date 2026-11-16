<?php
/**
 * Imagem do material em tamanho grande (só visualização).
 * Preenchido por public/assets/js/materiais.js.
 */
?>
<dialog id="modal-imagem" aria-labelledby="modal-imagem-titulo"
        class="w-[calc(100%-2rem)] max-w-2xl rounded-3xl border border-gray-800 bg-gray-900 text-white p-0 shadow-2xl backdrop:bg-black/80">
    <div class="p-4 sm:p-6 flex flex-col gap-4">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p data-codigo class="font-mono text-xs font-bold text-blue-300"></p>
                <h2 id="modal-imagem-titulo" class="text-lg font-black mt-0.5 break-words"></h2>
            </div>
            <button type="button" data-fechar class="p-2 -m-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800" aria-label="Fechar">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <div class="imagem-palco" data-palco>
            <img data-imagem alt="" decoding="async">
            <span data-ficticia class="selo-ficticia hidden">Imagem ilustrativa</span>
        </div>
    </div>
</dialog>
