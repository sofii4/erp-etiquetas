/**
 * Tela de materiais: filtros → GET /api/materiais → tabela paginada.
 *
 * Os filtros ficam na URL (?descricao=...&pagina=2), então F5, voltar do PDF
 * e compartilhar o link mantêm a mesma busca.
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

    const linhaMaterial = (m, termo) => {
        const tr = el('tr', 'linha-material');

        const tdCodigo = el('td', 'px-4 py-3 font-mono font-bold text-blue-200 whitespace-nowrap', m.CD_MATERIAL);
        const tdDesc = el('td', 'px-4 py-3 text-gray-200 md:min-w-[16rem]');
        tdDesc.appendChild(descricaoComDestaque(m.DESCRICAO, termo));
        // No celular as colunas abaixo somem; local e unidade vão sob a descrição.
        tdDesc.appendChild(el('div', 'md:hidden mt-1 text-xs font-mono text-gray-500',
            `${m.LOCALIZACAO ?? '—'} · ${m.CD_UNIDADE_MEDI ?? '—'}`));
        const oculto = 'hidden md:table-cell ';
        const tdGrupo = el('td', oculto + 'px-4 py-3 font-mono text-gray-400 whitespace-nowrap', `${m.CD_GRUPO ?? '—'} / ${m.CD_SUB_GRUPO ?? '—'}`);
        const tdUn = el('td', oculto + 'px-4 py-3 text-gray-400', m.CD_UNIDADE_MEDI ?? '—');
        const tdLocal = el('td', oculto + 'px-4 py-3 font-mono text-gray-300 whitespace-nowrap', m.LOCALIZACAO ?? '—');
        const tdData = el('td', oculto + 'px-4 py-3 text-gray-500 whitespace-nowrap', formatarData(m.DT_CADASTRO));

        const tdAcao = el('td', 'px-4 py-3 text-right');
        const link = el('a', 'btn-etiqueta', 'Imprimir');
        link.href = `/etiquetas/${encodeURIComponent(m.CD_MATERIAL)}`;
        link.target = '_blank';
        link.rel = 'noopener';
        link.title = `Abrir etiqueta de ${m.CD_MATERIAL} (PDF) para imprimir`;
        const icone = el('span', '', '⎙');
        icone.setAttribute('aria-hidden', 'true');
        link.prepend(icone);
        tdAcao.appendChild(link);

        tr.append(tdCodigo, tdDesc, tdGrupo, tdUn, tdLocal, tdData, tdAcao);
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

    // ── Modal do scanner (Fase 2) ────────────────

    const modal = document.getElementById('modal-scanner');
    document.getElementById('btn-scanner').addEventListener('click', () => modal.showModal());
    modal.querySelector('[data-fechar]').addEventListener('click', () => modal.close());
    // Clique fora do conteúdo (no backdrop) fecha.
    modal.addEventListener('click', (ev) => { if (ev.target === modal) modal.close(); });

    // ── Início: restaura filtros da URL ──────────

    const inicial = new URLSearchParams(location.search);
    form.elements.codigo.value = inicial.get('codigo') ?? '';
    form.elements.descricao.value = inicial.get('descricao') ?? '';
    selGrupo.value = inicial.get('grupo') ?? '';
    pagina = Math.max(1, parseInt(inicial.get('pagina') ?? '1', 10) || 1);

    carregarSubgrupos(selGrupo.value, inicial.get('subgrupo') ?? '').then(() => buscar({ atualizarUrl: false }));
})();
