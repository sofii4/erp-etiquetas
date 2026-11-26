<?php
/**
 * Imagens do material (até duas): ampliar, tirar foto (câmera), enviar
 * arquivo, remover ou tornar principal. As ações valem para a imagem
 * escolhida no seletor (data-fotos). Comportamento em
 * public/assets/js/materiais.js.
 *
 * Estados (data-modo no <dialog>): ver | camera | previa.
 */
?>
<dialog id="modal-imagem" aria-labelledby="modal-imagem-titulo" data-modo="ver"
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
            <video data-video class="hidden" muted playsinline aria-label="Imagem da câmera"></video>
            <p data-sem-foto class="hidden text-sm font-bold uppercase tracking-widest text-gray-500">Sem foto</p>
            <span data-selo-previa class="selo-previa hidden">Prévia · ainda não salva</span>
        </div>

        <!-- Seletor: imagem 1 (principal, aparece na tabela) e imagem 2. Preenchido pelo JS. -->
        <div data-fotos class="flex gap-2" role="group" aria-label="Imagens do material">
            <button type="button" data-foto="1" class="foto-slot"></button>
            <button type="button" data-foto="2" class="foto-slot"></button>
        </div>

        <p data-msg role="alert" class="hidden rounded-xl border px-3 py-2 text-sm"></p>

        <!-- ver: ações sobre a imagem atual -->
        <div data-acoes="ver" class="flex flex-wrap gap-2">
            <button type="button" data-tirar-foto class="btn-img btn-img-primario">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
                Tirar foto
            </button>
            <button type="button" data-escolher-arquivo class="btn-img">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 20h16"/></svg>
                Enviar arquivo
            </button>
            <button type="button" data-principal class="btn-img hidden">Tornar principal</button>
            <button type="button" data-remover class="btn-img btn-img-perigo sm:ml-auto">Remover imagem</button>
        </div>

        <!-- camera: vídeo ao vivo (computador / navegador com getUserMedia) -->
        <div data-acoes="camera" class="hidden flex flex-wrap gap-2">
            <button type="button" data-capturar class="btn-img btn-img-primario">Capturar</button>
            <button type="button" data-trocar-camera class="btn-img hidden">Trocar câmera</button>
            <button type="button" data-cancelar class="btn-img sm:ml-auto">Cancelar</button>
        </div>

        <!-- previa: confirma antes de gravar -->
        <div data-acoes="previa" class="hidden flex flex-wrap gap-2">
            <button type="button" data-salvar class="btn-img btn-img-primario">Salvar imagem</button>
            <button type="button" data-outra class="btn-img">Escolher outra</button>
            <button type="button" data-cancelar class="btn-img sm:ml-auto">Cancelar</button>
        </div>

        <!-- Arquivo e câmera nativa do celular (capture abre a câmera direto). -->
        <input type="file" data-input-arquivo accept="image/jpeg,image/png,image/webp,image/gif" class="hidden">
        <input type="file" data-input-camera accept="image/*" capture="environment" class="hidden">
    </div>
</dialog>
