/**
 * Leitor de QR Code da etiqueta (modal #modal-scanner).
 *
 * O QR guarda só o CD_MATERIAL. Fluxo: câmera (ou campo de código) →
 * GET /api/materiais/{cd} → mostra o material, com atalhos para imprimir a
 * etiqueta e filtrar a lista.
 *
 * Câmera: lib qr-scanner (Nimiq, MIT) em assets/js/vendor/qr-scanner. Usa o
 * BarcodeDetector nativo quando o navegador tem e, se não tiver, um worker
 * próprio. O navegador só libera a câmera em HTTPS ou localhost.
 *
 * O campo de código também serve para coletores com leitor embutido, que
 * "digitam" o código lido e mandam Enter.
 */
(() => {
    'use strict';

    const modal = document.getElementById('modal-scanner');
    const botaoAbrir = document.getElementById('btn-scanner');
    if (!modal || !botaoAbrir) return;

    const $ = (sel) => modal.querySelector(sel);
    const video = $('[data-video]');
    const area = $('[data-area]');
    const status = $('[data-camera-status]');
    const blocoLeitura = $('[data-leitura]');
    const btnTrocar = $('[data-trocar-camera]');
    const resultado = $('[data-resultado]');
    const mensagem = $('[data-mensagem]');
    const btnOutro = $('[data-ler-outro]');
    const formManual = $('[data-manual]');
    const inputCodigo = formManual.elements.cd;

    /** Mesmo limite da API (CD_MATERIAL é VARCHAR2(20)); sem espaços/controle. */
    const CODIGO_VALIDO = /^[0-9A-Za-z._\/-]{1,20}$/;

    let scanner = null;        // instância de QrScanner (criada na 1ª abertura)
    let cameras = [];
    let cameraAtual = 0;
    let buscando = false;      // evita buscas em paralelo com leituras repetidas
    let busca = null;          // AbortController da busca em andamento

    // ── Estados da tela ──────────────────────────

    const mostrarMensagem = (texto, tipo = 'erro') => {
        mensagem.textContent = texto;
        mensagem.className = 'rounded-xl border px-3 py-2 text-sm ' + (tipo === 'erro'
            ? 'border-red-500/40 bg-red-500/10 text-red-200'
            : 'border-gray-700 bg-gray-800/60 text-gray-300');
    };
    const esconderMensagem = () => { mensagem.className = 'hidden'; mensagem.textContent = ''; };

    const setStatusCamera = (texto) => {
        status.textContent = texto;
        status.classList.toggle('hidden', !texto);
        area.classList.toggle('ativa', !texto);
    };

    const modoLeitura = () => {
        resultado.classList.add('hidden');
        resultado.classList.remove('flex');
        btnOutro.classList.add('hidden');
        blocoLeitura.classList.remove('hidden');
        esconderMensagem();
    };

    const modoResultado = () => {
        blocoLeitura.classList.add('hidden');
        btnOutro.classList.remove('hidden');
    };

    // ── Câmera ───────────────────────────────────

    const erroDeCamera = (erro) => {
        const nome = erro?.name ?? '';
        const texto = String(erro?.message ?? erro ?? '');
        if (nome === 'NotAllowedError' || /permission|denied/i.test(texto)) {
            return 'Permissão da câmera negada. Libere o acesso nas configurações do navegador e abra o leitor de novo.';
        }
        if (nome === 'NotFoundError' || /no camera|not found/i.test(texto)) {
            return 'Nenhuma câmera encontrada neste aparelho. Digite o código abaixo.';
        }
        if (nome === 'NotReadableError') {
            return 'A câmera está sendo usada por outro aplicativo. Feche-o e tente de novo.';
        }
        return 'Não foi possível abrir a câmera. Digite o código abaixo.';
    };

    const iniciarCamera = async () => {
        if (!window.isSecureContext) {
            setStatusCamera('Câmera indisponível');
            const porta = location.port === '8190' ? '8191' : location.port;
            mostrarMensagem(`A câmera só funciona em conexão segura. Abra https://${location.hostname}${porta ? ':' + porta : ''}${location.pathname} ou digite o código abaixo.`, 'info');
            return;
        }
        if (typeof window.QrScanner === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
            setStatusCamera('Câmera indisponível');
            mostrarMensagem('Este navegador não permite usar a câmera. Digite o código abaixo.', 'info');
            return;
        }

        setStatusCamera('Abrindo a câmera…');
        try {
            if (!scanner) {
                scanner = new window.QrScanner(video, (r) => aoLer(r.data), {
                    preferredCamera: 'environment',
                    maxScansPerSecond: 8,
                    returnDetailedScanResult: true,
                    // "Nenhum QR neste quadro" chega aqui o tempo todo: ignora.
                    onDecodeError: () => {},
                });
            }
            await scanner.start();
            if (!modal.open) { scanner.stop(); return; } // fechou enquanto abria
            setStatusCamera('');

            // Lista de câmeras só depois da permissão (antes vem sem nomes).
            if (!cameras.length) {
                cameras = await window.QrScanner.listCameras(true).catch(() => []);
                btnTrocar.classList.toggle('hidden', cameras.length < 2);
            }
        } catch (erro) {
            setStatusCamera('Câmera indisponível');
            mostrarMensagem(erroDeCamera(erro));
        }
    };

    const pararCamera = () => {
        scanner?.stop();
    };

    btnTrocar.addEventListener('click', async () => {
        if (!scanner || cameras.length < 2) return;
        cameraAtual = (cameraAtual + 1) % cameras.length;
        try {
            await scanner.setCamera(cameras[cameraAtual].id);
        } catch (erro) {
            mostrarMensagem(erroDeCamera(erro));
        }
    });

    // ── Leitura → material ───────────────────────

    const preencherResultado = (m) => {
        $('[data-r-codigo]').textContent = m.CD_MATERIAL;
        $('[data-r-descricao]').textContent = m.DESCRICAO ?? '—';
        $('[data-r-grupo]').textContent = `${m.CD_GRUPO ?? '—'} / ${m.CD_SUB_GRUPO ?? '—'}`;
        $('[data-r-unidade]').textContent = m.CD_UNIDADE_MEDI ?? '—';
        $('[data-r-local]').textContent = m.LOCALIZACAO ?? '—';

        const etiqueta = $('[data-r-etiqueta]');
        etiqueta.href = `/etiquetas/${encodeURIComponent(m.CD_MATERIAL)}`;
        $('[data-r-lista]').dataset.cd = m.CD_MATERIAL;

        const img = $('[data-foto-img]');
        const vazia = $('[data-foto-vazia]');
        if (m.IMAGEM_HASH) {
            img.src = `/api/materiais/${encodeURIComponent(m.CD_MATERIAL)}/imagem?tam=grande&v=${encodeURIComponent(m.IMAGEM_HASH)}`;
            img.alt = `Imagem de ${m.DESCRICAO ?? m.CD_MATERIAL}`;
            img.classList.remove('hidden');
            vazia.classList.add('hidden');
        } else {
            img.removeAttribute('src');
            img.alt = '';
            img.classList.add('hidden');
            vazia.classList.remove('hidden');
        }

        resultado.classList.remove('hidden');
        resultado.classList.add('flex');
    };

    const buscarMaterial = async (bruto, origem) => {
        const cd = String(bruto ?? '').trim();
        if (buscando) return;

        if (!CODIGO_VALIDO.test(cd)) {
            if (origem === 'camera') {
                // QR de outra coisa (link, texto…): avisa e continua lendo.
                mostrarMensagem('Este QR Code não é de uma etiqueta de material.');
            } else {
                mostrarMensagem('Código inválido. Use até 20 letras ou números, sem espaços.');
            }
            return;
        }

        buscando = true;
        if (origem === 'camera') {
            scanner?.pause();
            navigator.vibrate?.(60);
        }
        esconderMensagem();
        busca?.abort();
        busca = new AbortController();

        try {
            const resp = await fetch(`/api/materiais/${encodeURIComponent(cd)}`, {
                headers: { Accept: 'application/json' },
                signal: busca.signal,
            });
            const corpo = await resp.json().catch(() => ({}));
            if (!modal.open) return;

            modoResultado();
            if (resp.status === 404) {
                resultado.classList.add('hidden');
                resultado.classList.remove('flex');
                mostrarMensagem(`Código ${cd} lido, mas não há material com esse código no cadastro.`);
                return;
            }
            if (!resp.ok) throw new Error(corpo.erro || `Erro ${resp.status} ao buscar o material.`);

            preencherResultado(corpo);
            inputCodigo.value = '';
        } catch (erro) {
            if (erro.name === 'AbortError') return;
            modoResultado();
            resultado.classList.add('hidden');
            mostrarMensagem(erro.message || 'Não foi possível buscar o material. Tente novamente.');
        } finally {
            buscando = false;
            // Se não achou, deixa o código selecionado: a próxima digitação ou
            // leitura do coletor substitui em vez de emendar no anterior.
            if (origem === 'manual' && inputCodigo.value) inputCodigo.select();
        }
    };

    const aoLer = (texto) => buscarMaterial(texto, 'camera');

    formManual.addEventListener('submit', (ev) => {
        ev.preventDefault();
        pararCamera();
        buscarMaterial(inputCodigo.value, 'manual');
    });

    btnOutro.addEventListener('click', () => {
        modoLeitura();
        iniciarCamera();
    });

    // "Ver na lista": filtra a tabela pelo código e fecha o leitor.
    $('[data-r-lista]').addEventListener('click', (ev) => {
        const form = document.getElementById('filtros');
        const cd = ev.currentTarget.dataset.cd ?? '';
        if (!form || !cd) return;
        form.reset();
        // O reset dispara uma busca no materiais.js (via setTimeout); o
        // requestSubmit depois dele faz a busca final já com o código.
        setTimeout(() => {
            form.elements.codigo.value = cd;
            form.requestSubmit();
        }, 0);
        modal.close();
    });

    // ── Abrir / fechar ───────────────────────────

    botaoAbrir.addEventListener('click', () => {
        modoLeitura();
        inputCodigo.value = '';
        modal.showModal();
        // Em computador (coletor USB ou digitação) o foco vai para o campo;
        // no celular não, para o teclado não cobrir a câmera.
        if (window.matchMedia('(pointer: fine)').matches) inputCodigo.focus();
        iniciarCamera();
    });

    $('[data-fechar]').addEventListener('click', () => modal.close());
    modal.addEventListener('click', (ev) => { if (ev.target === modal) modal.close(); });
    // Sempre desliga a câmera ao fechar (botão, Esc ou clique fora).
    modal.addEventListener('close', () => {
        pararCamera();
        busca?.abort();
        buscando = false;
    });
})();
