/**
 * Tela de materiais: filtros → GET /api/materiais → tabela paginada.
 *
 * Os filtros ficam na URL (?descricao=...&pagina=2), então F5, voltar do PDF
 * e compartilhar o link mantêm a mesma busca.
 *
 * Imagens: miniatura em cada linha (clique amplia). Só visualização — as
 * imagens são mantidas direto no banco (MATERIAL_IMAGEM).
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
                selSub.add(new Option(sub, sub, false, sub === selecionado));
            }
        } catch {
            selSub.disabled = true;
        }
    };

    // ── Busca ────────────────────────────────────

    // ── Imagem do material ───────────────────────
    //
    // A lista só traz IMAGEM_HASH; a miniatura (WebP ~1 KB, gravada no banco)
    // vem por <img loading="lazy">. O hash vai na URL, então o navegador
    // guarda a imagem em cache até ela mudar.

    const urlImagem = (cd, hash, tam) =>
        `/api/materiais/${encodeURIComponent(cd)}/imagem?tam=${tam}&v=${encodeURIComponent(hash)}`;

    /** Materiais da página atual, por código (o modal usa). */
    const materiaisDaPagina = new Map();

    /** Célula da imagem: botão que amplia, ou um marcador "sem foto". */
    const miniatura = (m) => {
        if (!m.IMAGEM_HASH) {
            const vazio = el('span', 'miniatura vazia', 'Sem foto');
            vazio.title = 'Material sem imagem';
            return vazio;
        }
        const botao = el('button', 'miniatura');
        botao.type = 'button';
        botao.dataset.acao = 'imagem';
        botao.title = m.IMAGEM_FICTICIA === 'S' ? 'Imagem ilustrativa · clique para ampliar' : 'Clique para ampliar';
        botao.setAttribute('aria-label', `Ampliar imagem de ${m.DESCRICAO ?? m.CD_MATERIAL}`);
        const img = el('img');
        img.src = urlImagem(m.CD_MATERIAL, m.IMAGEM_HASH, 'mini');
        img.alt = '';
        img.width = 64;
        img.height = 64;
        img.loading = 'lazy';
        img.decoding = 'async';
        botao.appendChild(img);
        return botao;
    };

    const linhaMaterial = (m, termo) => {
        const tr = el('tr', 'linha-material');
        tr.dataset.cd = m.CD_MATERIAL;

        // Miniatura: clique amplia.
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

        const tdGrupo = el('td', 'hidden md:table-cell px-4 py-2.5 font-mono text-gray-400 whitespace-nowrap', `${m.CD_GRUPO ?? '—'} / ${m.CD_SUB_GRUPO ?? '—'}`);
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

    // ── Imagem ampliada (só visualização) ────────

    const modalImg = document.getElementById('modal-imagem');
    const mImg = modalImg.querySelector('[data-imagem]');
    let cdModal = null;

    const mostrarNoModal = (m) => {
        cdModal = m.CD_MATERIAL;
        modalImg.querySelector('[data-codigo]').textContent = m.CD_MATERIAL;
        modalImg.querySelector('#modal-imagem-titulo').textContent = m.DESCRICAO ?? '—';
        modalImg.querySelector('[data-ficticia]').classList.toggle('hidden', m.IMAGEM_FICTICIA !== 'S');
        // A miniatura (já em cache) aparece na hora; a grande substitui ao chegar.
        mImg.src = urlImagem(m.CD_MATERIAL, m.IMAGEM_HASH, 'mini');
        mImg.alt = `Imagem de ${m.DESCRICAO ?? m.CD_MATERIAL}`;
        const grande = new Image();
        grande.onload = () => { if (cdModal === m.CD_MATERIAL) mImg.src = grande.src; };
        grande.src = urlImagem(m.CD_MATERIAL, m.IMAGEM_HASH, 'grande');
    };

    tbody.addEventListener('click', (ev) => {
        const botao = ev.target.closest('[data-acao="imagem"]');
        if (!botao) return;
        const m = materiaisDaPagina.get(botao.closest('tr').dataset.cd);
        if (!m?.IMAGEM_HASH) return;
        mostrarNoModal(m);
        modalImg.showModal();
    });
    modalImg.querySelector('[data-fechar]').addEventListener('click', () => modalImg.close());
    modalImg.addEventListener('click', (ev) => { if (ev.target === modalImg) modalImg.close(); });
    modalImg.addEventListener('close', () => { cdModal = null; });

    // ── Início: restaura filtros da URL ──────────

    const inicial = new URLSearchParams(location.search);
    form.elements.codigo.value = inicial.get('codigo') ?? '';
    form.elements.descricao.value = inicial.get('descricao') ?? '';
    selGrupo.value = inicial.get('grupo') ?? '';
    pagina = Math.max(1, parseInt(inicial.get('pagina') ?? '1', 10) || 1);

    carregarSubgrupos(selGrupo.value, inicial.get('subgrupo') ?? '').then(() => buscar({ atualizarUrl: false }));
})();
