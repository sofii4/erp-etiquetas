/**
 * Tela de materiais: filtros → GET /api/materiais → tabela paginada.
 *
 * Os filtros ficam na URL (?descricao=...&pagina=2), então F5, voltar do PDF
 * e compartilhar o link mantêm a mesma busca.
 *
 * Imagens: até duas por material (MATERIAL_IMAGEM, posição 1 = principal).
 * A linha mostra a miniatura da principal (com selo "2" se houver a segunda).
 * O clique abre o modal, que amplia, alterna entre as duas e permite tirar
 * foto (câmera), enviar arquivo, remover ou tornar principal.
 */
(() => {
    'use strict';

    const POR_PAGINA = 20;

    const form = document.getElementById('filtros');
    const selGrupo = form.elements.grupo;
    const selSub = form.elements.subgrupo;
    const tbody = document.getElementById('linhas');
    const estado = document.getElementById('estado');
    const resumo = document.getElementById('resumo');
    const paginacao = document.getElementById('paginacao');
    const paginaInfo = document.getElementById('pagina-info');
    const btnAnterior = paginacao.querySelector('[data-pagina="anterior"]');
    const btnProxima = paginacao.querySelector('[data-pagina="proxima"]');

    let pagina = 1;
    let totalPaginas = 1;
    let requisicaoAtual = null; // AbortController da busca em andamento

    // ── Utilidades ───────────────────────────────

    const formatarData = (iso) => {
        if (!iso) return '—';
        const [ano, mes, dia] = iso.slice(0, 10).split('-');
        return `${dia}/${mes}/${ano}`;
    };

    /** Cria um elemento com classes e texto (texto SEMPRE via textContent). */
    const el = (tag, classes = '', texto = null) => {
        const n = document.createElement(tag);
        if (classes) n.className = classes;
        if (texto !== null) n.textContent = texto;
        return n;
    };

    /** Descrição com o trecho buscado destacado, sem usar innerHTML. */
    const descricaoComDestaque = (texto, termo) => {
        const frag = document.createDocumentFragment();
        if (!texto) return frag.appendChild(document.createTextNode('—')), frag;
        const i = termo ? texto.toLocaleUpperCase('pt-BR').indexOf(termo.toLocaleUpperCase('pt-BR')) : -1;
        if (i < 0) {
            frag.appendChild(document.createTextNode(texto));
        } else {
            frag.appendChild(document.createTextNode(texto.slice(0, i)));
            frag.appendChild(el('mark', '', texto.slice(i, i + termo.length)));
            frag.appendChild(document.createTextNode(texto.slice(i + termo.length)));
        }
        return frag;
    };

    const filtrosAtuais = () => {
        const dados = new FormData(form);
        const filtros = {};
        for (const campo of ['codigo', 'descricao', 'grupo', 'subgrupo']) {
            const v = String(dados.get(campo) ?? '').trim();
            if (v) filtros[campo] = v;
        }
        return filtros;
    };

    const mostrarEstado = (texto, tipo = 'info') => {
        tbody.replaceChildren();
        estado.textContent = texto;
        estado.className = 'px-4 py-12 text-center text-sm ' + (tipo === 'erro' ? 'text-red-300' : 'text-gray-400');
        paginacao.classList.add('hidden');
    };

    // ── Subgrupos dependem do grupo ──────────────

    const carregarSubgrupos = async (grupo, selecionado = '') => {
        selSub.replaceChildren(new Option('Todos', ''));
        selSub.disabled = !grupo;
        if (!grupo) return;

        try {
            const resp = await fetch(`/api/grupos/${encodeURIComponent(grupo)}/subgrupos`, {
                headers: { Accept: 'application/json' },
            });
            if (!resp.ok) throw new Error();
            for (const sub of await resp.json()) {
                const cd = sub.CD_SUB_GRUPO;
                selSub.add(new Option(sub.DESCRICAO ? `${cd} · ${sub.DESCRICAO}` : cd, cd, false, cd === selecionado));
            }
        } catch {
            selSub.disabled = true;
        }
    };

    // ── Busca ────────────────────────────────────

    // ── Imagem do material ───────────────────────
    //
    // A lista só traz os hashes (IMAGEM_HASH = principal, IMAGEM2_HASH = a
    // segunda); a miniatura (WebP ~1 KB, gravada no banco) vem por
    // <img loading="lazy">. O hash vai na URL, então o navegador guarda a
    // imagem em cache até ela mudar.

    const urlImagem = (cd, pos, hash, tam) =>
        `/api/materiais/${encodeURIComponent(cd)}/imagem/${pos}?tam=${tam}&v=${encodeURIComponent(hash)}`;

    /** Hash e "fictícia?" da imagem 1 ou 2 do material. */
    const foto = (m, pos) => pos === 2
        ? { hash: m?.IMAGEM2_HASH ?? null, ficticia: m?.IMAGEM2_FICTICIA === 'S' }
        : { hash: m?.IMAGEM_HASH ?? null, ficticia: m?.IMAGEM_FICTICIA === 'S' };

    const CAMPOS_IMAGEM = ['IMAGEM_HASH', 'IMAGEM_FICTICIA', 'IMAGEM2_HASH', 'IMAGEM2_FICTICIA'];

    /** Materiais da página atual, por código (o modal usa). */
    const materiaisDaPagina = new Map();

    /** Célula da imagem: botão que abre o modal (amplia ou adiciona foto). */
    const miniatura = (m) => {
        const botao = el('button', m.IMAGEM_HASH ? 'miniatura' : 'miniatura vazia');
        botao.type = 'button';
        botao.dataset.acao = 'imagem';
        if (!m.IMAGEM_HASH) {
            botao.textContent = '+ Foto';
            botao.title = 'Material sem imagem · clique para adicionar';
            botao.setAttribute('aria-label', `Adicionar imagem de ${m.DESCRICAO ?? m.CD_MATERIAL}`);
            return botao;
        }
        const duas = Boolean(m.IMAGEM2_HASH);
        botao.title = (m.IMAGEM_FICTICIA === 'S' ? 'Imagem ilustrativa · ' : '')
            + (duas ? '2 imagens · ' : '') + 'clique para ampliar ou trocar';
        botao.setAttribute('aria-label', `Ampliar ${duas ? 'imagens' : 'imagem'} de ${m.DESCRICAO ?? m.CD_MATERIAL}`);
        const img = el('img');
        img.src = urlImagem(m.CD_MATERIAL, 1, m.IMAGEM_HASH, 'mini');
        img.alt = '';
        img.width = 64;
        img.height = 64;
        img.loading = 'lazy';
        img.decoding = 'async';
        botao.appendChild(img);
        if (duas) {
            const qtd = el('span', 'miniatura-qtd', '2');
            qtd.setAttribute('aria-hidden', 'true');
            botao.appendChild(qtd);
        }
        return botao;
    };

    const linhaMaterial = (m, termo) => {
        const tr = el('tr', 'linha-material');
        tr.dataset.cd = m.CD_MATERIAL;

        // Miniatura: clique abre o modal da imagem.
        const tdImg = el('td', 'pl-3 pr-1 sm:pl-4 py-2.5 align-middle');
        tdImg.appendChild(miniatura(m));

        // Material: descrição em destaque, código e (em telas menores) local/un.
        const tdMat = el('td', 'px-3 sm:px-4 py-2.5 align-middle min-w-[11rem]');
        const desc = el('div', 'text-gray-100 font-semibold leading-snug');
        desc.appendChild(descricaoComDestaque(m.DESCRICAO, termo));
        const cod = el('div', 'mt-1 font-mono text-xs font-bold text-blue-300', m.CD_MATERIAL);
        const extra = el('div', 'lg:hidden mt-0.5 text-xs text-gray-500',
            `Local ${m.LOCALIZACAO ?? '—'} · ${m.CD_UNIDADE_MEDI ?? '—'}`);
        tdMat.append(desc, cod, extra);

        // Grupo: código em cima, nome do subgrupo (ou do grupo) embaixo.
        const tdGrupo = el('td', 'hidden md:table-cell px-4 py-2.5 align-middle');
        tdGrupo.append(el('div', 'font-mono text-gray-400 whitespace-nowrap', `${m.CD_GRUPO ?? '—'} / ${m.CD_SUB_GRUPO ?? '—'}`));
        const nomeGrupo = m.DESC_SUBGRUPO ?? m.DESC_GRUPO;
        if (nomeGrupo) {
            const nome = el('div', 'mt-0.5 text-xs text-gray-500 max-w-[12rem] truncate', nomeGrupo);
            nome.title = [m.DESC_GRUPO, m.DESC_SUBGRUPO].filter(Boolean).join(' / ');
            tdGrupo.append(nome);
        }
        const tdUn = el('td', 'hidden md:table-cell px-4 py-2.5 text-gray-400', m.CD_UNIDADE_MEDI ?? '—');
        const tdLocal = el('td', 'hidden lg:table-cell px-4 py-2.5 font-mono text-gray-300 whitespace-nowrap', m.LOCALIZACAO ?? '—');
        const tdData = el('td', 'hidden lg:table-cell px-4 py-2.5 text-gray-500 whitespace-nowrap', formatarData(m.DT_CADASTRO));

        const tdAcao = el('td', 'pl-2 pr-3 sm:px-4 py-2.5 text-right');
        const link = el('a', 'btn-etiqueta');
        link.href = `/etiquetas/${encodeURIComponent(m.CD_MATERIAL)}`;
        link.target = '_blank';
        link.rel = 'noopener';
        link.title = `Abrir etiqueta de ${m.CD_MATERIAL} (PDF) para imprimir`;
        const icone = el('span', '', '⎙');
        icone.setAttribute('aria-hidden', 'true');
        link.append(icone, el('span', 'hidden sm:inline', 'Imprimir'));
        link.setAttribute('aria-label', `Imprimir etiqueta de ${m.CD_MATERIAL}`);
        tdAcao.appendChild(link);

        tr.append(tdImg, tdMat, tdGrupo, tdUn, tdLocal, tdData, tdAcao);
        return tr;
    };

    const buscar = async ({ atualizarUrl = true } = {}) => {
        const filtros = filtrosAtuais();
        const params = new URLSearchParams({ ...filtros, pagina, por_pagina: POR_PAGINA });

        if (atualizarUrl) {
            const urlParams = new URLSearchParams(filtros);
            if (pagina > 1) urlParams.set('pagina', pagina);
            const qs = urlParams.toString();
            history.replaceState(null, '', qs ? `?${qs}` : location.pathname);
        }

        requisicaoAtual?.abort();
        requisicaoAtual = new AbortController();

        resumo.textContent = 'Buscando…';
        tbody.setAttribute('aria-busy', 'true');
        if (!tbody.children.length) mostrarEstado('Carregando materiais…');

        try {
            const resp = await fetch(`/api/materiais?${params}`, {
                headers: { Accept: 'application/json' },
                signal: requisicaoAtual.signal,
            });
            const corpo = await resp.json().catch(() => ({}));
            if (!resp.ok) throw new Error(corpo.erro || `Erro ${resp.status} ao buscar materiais.`);

            renderizar(corpo, filtros.descricao ?? '');
        } catch (erro) {
            if (erro.name === 'AbortError') return;
            resumo.textContent = '';
            mostrarEstado(erro.message || 'Não foi possível buscar os materiais. Tente novamente.', 'erro');
        } finally {
            tbody.removeAttribute('aria-busy');
        }
    };

    const renderizar = ({ dados, total, pagina: pag, por_pagina: porPagina }, termo) => {
        totalPaginas = Math.max(1, Math.ceil(total / porPagina));

        if (total === 0) {
            resumo.textContent = 'Nenhum material encontrado.';
            mostrarEstado('Nenhum material com esses filtros. Tente outra descrição ou limpe os filtros.');
            return;
        }

        // Página além do fim (ex.: filtros mudaram com ?pagina=5 na URL) → volta para a última.
        if (dados.length === 0 && pag > 1) {
            pagina = totalPaginas;
            buscar();
            return;
        }

        estado.className = 'hidden';
        materiaisDaPagina.clear();
        for (const m of dados) materiaisDaPagina.set(m.CD_MATERIAL, m);
        tbody.replaceChildren(...dados.map((m) => linhaMaterial(m, termo)));

        const ini = (pag - 1) * porPagina + 1;
        const fim = ini + dados.length - 1;
        resumo.textContent = `${total} ${total === 1 ? 'material' : 'materiais'}`;
        paginaInfo.textContent = `${ini}–${fim} de ${total} · página ${pag} de ${totalPaginas}`;
        btnAnterior.disabled = pag <= 1;
        btnProxima.disabled = pag >= totalPaginas;
        paginacao.classList.toggle('hidden', totalPaginas <= 1);
    };

    // ── Eventos ──────────────────────────────────

    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        pagina = 1;
        buscar();
    });

    form.addEventListener('reset', () => {
        // O reset nativo limpa os campos depois deste evento.
        setTimeout(() => {
            carregarSubgrupos('');
            pagina = 1;
            buscar();
        });
    });

    selGrupo.addEventListener('change', async () => {
        await carregarSubgrupos(selGrupo.value);
        pagina = 1;
        buscar();
    });

    selSub.addEventListener('change', () => {
        pagina = 1;
        buscar();
    });

    btnAnterior.addEventListener('click', () => {
        if (pagina > 1) { pagina--; buscar(); }
    });
    btnProxima.addEventListener('click', () => {
        if (pagina < totalPaginas) { pagina++; buscar(); }
    });

    // O leitor de QR Code fica em scanner.js.

    // ── Modal da imagem: ver, tirar foto, enviar, remover ──
    //
    // Câmera: no celular usa o app de câmera nativo (<input capture>, funciona
    // até em http); no computador abre o vídeo ao vivo (getUserMedia, só em
    // HTTPS ou localhost) e captura um quadro.
    //
    // TODO(permissões): por enquanto qualquer usuário pode alterar imagens.

    const modalImg = document.getElementById('modal-imagem');
    const $m = (sel) => modalImg.querySelector(sel);
    const mImg = $m('[data-imagem]');
    const mVideo = $m('[data-video]');
    const mSemFoto = $m('[data-sem-foto]');
    const mMsg = $m('[data-msg]');
    const inputArquivo = $m('[data-input-arquivo]');
    const inputCamera = $m('[data-input-camera]');
    const btnRemover = $m('[data-remover]');
    const btnPrincipal = $m('[data-principal]');
    const slots = [...modalImg.querySelectorAll('[data-foto]')];
    const btnTrocarCam = $m('[data-trocar-camera]');
    const btnSalvar = $m('[data-salvar]');

    /** Lado máximo enviado ao servidor (ele reduz para 1024 px de qualquer jeito). */
    const LADO_ENVIO = 2048;
    const BYTES_MAX = 10 * 1024 * 1024;
    const celular = window.matchMedia('(pointer: coarse)').matches;

    let mAtual = null;        // material aberto no modal
    let posAtual = 1;         // imagem escolhida no seletor (1 = principal, 2)
    let previa = null;        // { blob, url, origem: 'camera' | 'arquivo' }
    let stream = null;        // MediaStream da câmera ao vivo
    let cameras = [];
    let cameraAtual = 0;
    let enviando = false;

    const msg = (texto, tipo = 'erro') => {
        if (!texto) { mMsg.className = 'hidden'; mMsg.textContent = ''; return; }
        mMsg.textContent = texto;
        mMsg.className = 'rounded-xl border px-3 py-2 text-sm ' + (tipo === 'erro'
            ? 'border-red-500/40 bg-red-500/10 text-red-200'
            : 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200');
    };

    const descartarPrevia = () => {
        if (previa) URL.revokeObjectURL(previa.url);
        previa = null;
    };

    const pararVideo = () => {
        stream?.getTracks().forEach((t) => t.stop());
        stream = null;
        mVideo.srcObject = null;
    };

    /** Troca o estado do modal e o que o palco mostra. */
    const modo = (novo) => {
        modalImg.dataset.modo = novo;
        for (const bloco of modalImg.querySelectorAll('[data-acoes]')) {
            bloco.classList.toggle('hidden', bloco.dataset.acoes !== novo);
        }
        if (novo !== 'camera') pararVideo();
        if (novo !== 'previa') descartarPrevia();

        const m = mAtual;
        const f = foto(m, posAtual);
        mVideo.classList.toggle('hidden', novo !== 'camera');
        $m('[data-selo-previa]').classList.toggle('hidden', novo !== 'previa');
        $m('[data-ficticia]').classList.toggle('hidden', !(novo === 'ver' && f.hash && f.ficticia));
        desenharSlots();

        if (novo === 'previa') {
            mImg.src = previa.url;
            mImg.classList.remove('hidden');
            mSemFoto.classList.add('hidden');
            $m('[data-outra]').textContent = previa.origem === 'camera' ? 'Tirar outra' : 'Escolher outra';
        } else if (novo === 'camera') {
            mImg.classList.add('hidden');
            mSemFoto.classList.add('hidden');
        } else {
            const tem = Boolean(f.hash);
            const pos = posAtual;
            mImg.classList.toggle('hidden', !tem);
            mSemFoto.classList.toggle('hidden', tem);
            mSemFoto.textContent = pos === 2 ? 'Sem a segunda foto' : 'Sem foto';
            btnRemover.classList.toggle('hidden', !tem);
            btnPrincipal.classList.toggle('hidden', !(tem && pos === 2));
            if (tem) {
                // A miniatura (já em cache) aparece na hora; a grande substitui ao chegar.
                mImg.src = urlImagem(m.CD_MATERIAL, pos, f.hash, 'mini');
                mImg.alt = `Imagem ${pos} de ${m.DESCRICAO ?? m.CD_MATERIAL}`;
                const grande = new Image();
                grande.onload = () => {
                    if (mAtual === m && posAtual === pos && modalImg.dataset.modo === 'ver') mImg.src = grande.src;
                };
                grande.src = urlImagem(m.CD_MATERIAL, pos, f.hash, 'grande');
            } else {
                mImg.removeAttribute('src');
            }
        }
    };

    /**
     * Seletor das duas imagens. A 2 só fica disponível depois da principal;
     * durante câmera/prévia/envio o seletor trava (a ação vale para a imagem
     * escolhida).
     */
    const desenharSlots = () => {
        const m = mAtual;
        const livre = modalImg.dataset.modo === 'ver' && !enviando;
        for (const slot of slots) {
            const pos = Number(slot.dataset.foto);
            const f = foto(m, pos);
            const bloqueada = pos === 2 && !m?.IMAGEM_HASH;
            slot.replaceChildren();
            slot.classList.toggle('vazia', !f.hash);
            slot.setAttribute('aria-pressed', String(pos === posAtual));
            slot.disabled = !livre || bloqueada;
            const nome = pos === 1 ? 'Principal' : 'Foto 2';
            if (f.hash) {
                const img = el('img');
                img.src = urlImagem(m.CD_MATERIAL, pos, f.hash, 'mini');
                img.alt = '';
                img.decoding = 'async';
                slot.append(img, el('span', 'foto-slot-rotulo', nome));
                slot.title = `Ver ${pos === 1 ? 'a imagem principal' : 'a segunda imagem'}`;
            } else {
                slot.textContent = pos === 1 ? '+ Principal' : '+ Foto 2';
                slot.title = bloqueada ? 'Adicione a imagem principal primeiro' : 'Adicionar a segunda imagem';
            }
            slot.setAttribute('aria-label', `${pos === 1 ? 'Imagem principal' : 'Segunda imagem'}${f.hash ? '' : ' (vazia)'}`);
        }
    };

    for (const slot of slots) {
        slot.addEventListener('click', () => {
            const pos = Number(slot.dataset.foto);
            if (pos === posAtual || modalImg.dataset.modo !== 'ver') return;
            posAtual = pos;
            msg('');
            modo('ver');
        });
    }

    const abrirModal = (m) => {
        mAtual = m;
        posAtual = 1;
        $m('[data-codigo]').textContent = m.CD_MATERIAL;
        $m('#modal-imagem-titulo').textContent = m.DESCRICAO ?? '—';
        msg('');
        modo('ver');
        modalImg.showModal();
    };

    // ── Prévia ───────────────────────────────────

    /**
     * Reduz fotos grandes antes de enviar (menos dados pela rede do celular).
     * Se o navegador não conseguir, manda o arquivo original.
     */
    const reduzirParaEnvio = async (arquivo) => {
        try {
            const bmp = await createImageBitmap(arquivo, { imageOrientation: 'from-image' });
            const escala = Math.min(1, LADO_ENVIO / Math.max(bmp.width, bmp.height));
            if (escala === 1 && arquivo.size <= 3 * 1024 * 1024) { bmp.close(); return arquivo; }
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bmp.width * escala);
            canvas.height = Math.round(bmp.height * escala);
            canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
            bmp.close();
            const blob = await new Promise((ok) => canvas.toBlob(ok, 'image/jpeg', 0.9));
            return blob ?? arquivo;
        } catch {
            return arquivo;
        }
    };

    const mostrarPrevia = async (arquivo, origem) => {
        if (!arquivo) return;
        if (!/^image\//.test(arquivo.type)) { msg('Escolha um arquivo de imagem (JPG, PNG, WebP ou GIF).'); return; }
        const blob = await reduzirParaEnvio(arquivo);
        if (blob.size > BYTES_MAX) { msg('Imagem maior que 10 MB.'); return; }
        msg('');
        descartarPrevia();
        previa = { blob, url: URL.createObjectURL(blob), origem };
        modo('previa');
    };

    inputArquivo.addEventListener('change', () => {
        mostrarPrevia(inputArquivo.files[0], 'arquivo');
        inputArquivo.value = '';
    });
    inputCamera.addEventListener('change', () => {
        mostrarPrevia(inputCamera.files[0], 'camera');
        inputCamera.value = '';
    });

    // ── Câmera ao vivo (computador) ──────────────

    const erroDeCamera = (erro) => {
        switch (erro?.name) {
            case 'NotAllowedError': return 'Permissão da câmera negada. Libere o acesso no navegador ou use "Enviar arquivo".';
            case 'NotFoundError': return 'Nenhuma câmera encontrada. Use "Enviar arquivo".';
            case 'NotReadableError': return 'A câmera está sendo usada por outro programa (o leitor de QR, por exemplo). Feche-o e tente de novo.';
            default: return 'Não foi possível abrir a câmera. Use "Enviar arquivo".';
        }
    };

    const abrirVideo = async () => {
        pararVideo();
        const video = cameras[cameraAtual]
            ? { deviceId: { exact: cameras[cameraAtual].deviceId } }
            : { facingMode: 'environment' };
        stream = await navigator.mediaDevices.getUserMedia({
            video: { ...video, width: { ideal: 1920 }, height: { ideal: 1080 } },
            audio: false,
        });
        if (!modalImg.open || modalImg.dataset.modo !== 'camera') { pararVideo(); return; }
        mVideo.srcObject = stream;
        await mVideo.play().catch(() => {});
    };

    const iniciarCamera = async () => {
        msg('');
        // Celular: câmera nativa (melhor foco/resolução e funciona sem HTTPS).
        if (celular || !navigator.mediaDevices?.getUserMedia) {
            if (!celular && !window.isSecureContext) {
                const porta = location.port === '8190' ? '8191' : location.port;
                msg(`A câmera do computador só funciona em conexão segura (https://${location.hostname}${porta ? ':' + porta : ''}). Use "Enviar arquivo".`);
                return;
            }
            inputCamera.click();
            return;
        }
        modo('camera');
        try {
            await abrirVideo();
            // Nomes das câmeras só aparecem depois da permissão.
            if (!cameras.length) {
                cameras = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
                const ativa = stream?.getVideoTracks()[0]?.getSettings().deviceId;
                cameraAtual = Math.max(0, cameras.findIndex((c) => c.deviceId === ativa));
            }
            btnTrocarCam.classList.toggle('hidden', cameras.length < 2);
        } catch (erro) {
            modo('ver');
            msg(erroDeCamera(erro));
        }
    };

    $m('[data-capturar]').addEventListener('click', () => {
        if (!stream || !mVideo.videoWidth) return;
        const canvas = document.createElement('canvas');
        canvas.width = mVideo.videoWidth;
        canvas.height = mVideo.videoHeight;
        canvas.getContext('2d').drawImage(mVideo, 0, 0);
        canvas.toBlob((blob) => {
            if (blob) mostrarPrevia(new File([blob], 'foto.jpg', { type: 'image/jpeg' }), 'camera');
        }, 'image/jpeg', 0.9);
    });

    btnTrocarCam.addEventListener('click', async () => {
        if (cameras.length < 2) return;
        cameraAtual = (cameraAtual + 1) % cameras.length;
        try { await abrirVideo(); } catch (erro) { msg(erroDeCamera(erro)); }
    });

    // ── Gravar / remover ─────────────────────────

    /** Atualiza o material na página (hashes das duas imagens) e a miniatura da linha. */
    const aplicarNoMaterial = (cd, corpo) => {
        const m = materiaisDaPagina.get(cd) ?? mAtual;
        for (const campo of CAMPOS_IMAGEM) m[campo] = corpo?.[campo] ?? null;
        const celula = tbody.querySelector(`tr[data-cd="${CSS.escape(cd)}"] td`);
        celula?.replaceChildren(miniatura(m));
        return m;
    };

    const ocupado = (sim) => {
        enviando = sim;
        for (const b of modalImg.querySelectorAll('[data-acoes] button')) b.disabled = sim;
        desenharSlots();
    };

    const urlApi = (cd, sufixo) => `/api/materiais/${encodeURIComponent(cd)}/imagem/${sufixo}`;

    btnSalvar.addEventListener('click', async () => {
        if (!previa || enviando) return;
        const cd = mAtual.CD_MATERIAL;
        const pos = posAtual;
        const dados = new FormData();
        dados.append('imagem', previa.blob, previa.blob.name || 'imagem.jpg');
        ocupado(true);
        btnSalvar.textContent = 'Salvando…';
        try {
            const resp = await fetch(urlApi(cd, pos), {
                method: 'POST',
                body: dados,
                headers: { Accept: 'application/json' },
            });
            const corpo = await resp.json().catch(() => ({}));
            if (!resp.ok) throw new Error(corpo.erro || (resp.status === 413 ? 'Imagem maior que 10 MB.' : `Erro ${resp.status} ao salvar a imagem.`));
            mAtual = aplicarNoMaterial(cd, corpo);
            // O servidor grava a 2 como 1 se ainda não houver a principal.
            if (pos === 2 && !mAtual.IMAGEM2_HASH) posAtual = 1;
            modo('ver');
            msg(posAtual === 1 ? 'Imagem principal salva.' : 'Segunda imagem salva.', 'ok');
        } catch (erro) {
            msg(erro.message || 'Não foi possível salvar a imagem. Tente de novo.');
        } finally {
            ocupado(false);
            btnSalvar.textContent = 'Salvar imagem';
        }
    });

    /** Recarrega o material (usado quando a imagem já não existia: 404). */
    const recarregarMaterial = async (cd) => {
        const resp = await fetch(`/api/materiais/${encodeURIComponent(cd)}`, { headers: { Accept: 'application/json' } });
        if (!resp.ok) throw new Error(`Erro ${resp.status} ao atualizar o material.`);
        return resp.json();
    };

    btnRemover.addEventListener('click', async () => {
        const pos = posAtual;
        if (enviando || !foto(mAtual, pos).hash) return;
        const temSegunda = pos === 1 && Boolean(mAtual.IMAGEM2_HASH);
        const aviso = pos === 1
            ? `Remover a imagem principal de ${mAtual.CD_MATERIAL}?` + (temSegunda ? ' A segunda imagem passa a ser a principal.' : '')
            : `Remover a segunda imagem de ${mAtual.CD_MATERIAL}?`;
        if (!confirm(aviso)) return;
        const cd = mAtual.CD_MATERIAL;
        ocupado(true);
        try {
            const resp = await fetch(urlApi(cd, pos), { method: 'DELETE', headers: { Accept: 'application/json' } });
            const corpo = await resp.json().catch(() => ({}));
            if (!resp.ok && resp.status !== 404) throw new Error(corpo.erro || `Erro ${resp.status} ao remover a imagem.`);
            mAtual = aplicarNoMaterial(cd, resp.ok ? corpo : await recarregarMaterial(cd));
            posAtual = 1;
            modo('ver');
            msg(temSegunda ? 'Imagem removida. A segunda imagem agora é a principal.' : 'Imagem removida.', 'ok');
        } catch (erro) {
            msg(erro.message || 'Não foi possível remover a imagem.');
        } finally {
            ocupado(false);
        }
    });

    btnPrincipal.addEventListener('click', async () => {
        if (enviando || !mAtual?.IMAGEM_HASH || !mAtual?.IMAGEM2_HASH) return;
        const cd = mAtual.CD_MATERIAL;
        ocupado(true);
        try {
            const resp = await fetch(urlApi(cd, 'inverter'), { method: 'POST', headers: { Accept: 'application/json' } });
            const corpo = await resp.json().catch(() => ({}));
            if (!resp.ok) throw new Error(corpo.erro || `Erro ${resp.status} ao trocar a imagem principal.`);
            mAtual = aplicarNoMaterial(cd, corpo);
            posAtual = 1;
            modo('ver');
            msg('Esta agora é a imagem principal.', 'ok');
        } catch (erro) {
            msg(erro.message || 'Não foi possível trocar a imagem principal.');
        } finally {
            ocupado(false);
        }
    });

    // ── Botões e abertura ────────────────────────

    $m('[data-tirar-foto]').addEventListener('click', iniciarCamera);
    $m('[data-escolher-arquivo]').addEventListener('click', () => inputArquivo.click());
    $m('[data-outra]').addEventListener('click', () => {
        if (previa?.origem === 'camera') { modo('ver'); iniciarCamera(); } else inputArquivo.click();
    });
    for (const b of modalImg.querySelectorAll('[data-cancelar]')) {
        b.addEventListener('click', () => { msg(''); modo('ver'); });
    }

    tbody.addEventListener('click', (ev) => {
        const botao = ev.target.closest('[data-acao="imagem"]');
        if (!botao) return;
        const m = materiaisDaPagina.get(botao.closest('tr').dataset.cd);
        if (m) abrirModal(m);
    });
    $m('[data-fechar]').addEventListener('click', () => modalImg.close());
    modalImg.addEventListener('click', (ev) => { if (ev.target === modalImg) modalImg.close(); });
    // Sempre desliga a câmera e descarta a prévia ao fechar (botão, Esc ou clique fora).
    modalImg.addEventListener('close', () => {
        pararVideo();
        descartarPrevia();
        mAtual = null;
    });

    // ── Início: restaura filtros da URL ──────────

    const inicial = new URLSearchParams(location.search);
    form.elements.codigo.value = inicial.get('codigo') ?? '';
    form.elements.descricao.value = inicial.get('descricao') ?? '';
    selGrupo.value = inicial.get('grupo') ?? '';
    pagina = Math.max(1, parseInt(inicial.get('pagina') ?? '1', 10) || 1);

    carregarSubgrupos(selGrupo.value, inicial.get('subgrupo') ?? '').then(() => buscar({ atualizarUrl: false }));
})();
