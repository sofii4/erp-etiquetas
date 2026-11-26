<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\OracleConnection;
use InvalidArgumentException;

/**
 * Todo o SQL da ESMATERI mora aqui.
 *
 * Regras para continuar compatível com o Oracle 19c (dev roda no XE 21c):
 *  - sempre bind variables (nunca concatenar valor do usuário);
 *  - nada de recursos 21c/23ai (BOOLEAN em SQL, IF EXISTS, SELECT sem FROM DUAL...);
 *  - só as colunas usadas — a tabela tem 106.
 *
 * A ESMATERI só é LIDA. As imagens (até duas) vêm de MATERIAL_IMAGEM e os
 * nomes de grupo/subgrupo de GRUPO_SUBGRUPO (LEFT JOIN: sem cadastro, o nome
 * vem null e a tela mostra só o código).
 *
 * O ERP grava "vazio" como ' ' nas colunas VARCHAR2 NOT NULL; TRIM(' ') no
 * Oracle vira NULL, então a API devolve null nesses casos.
 */
final class MaterialRepository
{
    // "m." = ESMATERI; "i." e "i2." = imagens 1 (principal) e 2 da
    // MATERIAL_IMAGEM (tabela própria do módulo, no schema da aplicação). Um
    // JOIN por posição mantém uma linha por material. Do lado da imagem só
    // vem o HASH: o BLOB é baixado à parte, pela URL
    // /api/materiais/{cd}/imagem/{pos}?v={hash}.
    private const COLUNAS = <<<'SQL'
        TRIM(m.CD_MATERIAL)     AS CD_MATERIAL,
        TRIM(m.DESCRICAO)       AS DESCRICAO,
        TRIM(m.CD_GRUPO)        AS CD_GRUPO,
        TRIM(m.CD_SUB_GRUPO)    AS CD_SUB_GRUPO,
        TRIM(gg.DESC_GRUPO)     AS DESC_GRUPO,
        TRIM(g.DESC_SUBGRUPO)   AS DESC_SUBGRUPO,
        TRIM(m.CD_UNIDADE_MEDI) AS CD_UNIDADE_MEDI,
        TRIM(m.LOCALIZACAO)     AS LOCALIZACAO,
        TO_CHAR(m.DT_CADASTRO,    'YYYY-MM-DD"T"HH24:MI:SS') AS DT_CADASTRO,
        TO_CHAR(m.DT_MODIFICACAO, 'YYYY-MM-DD"T"HH24:MI:SS') AS DT_MODIFICACAO,
        TRIM(m.USUARIO_CRIACAO) AS USUARIO_CRIACAO,
        TRIM(m.USUARIO_MODIFIC) AS USUARIO_MODIFIC,
        i.HASH                  AS IMAGEM_HASH,
        i.FICTICIA              AS IMAGEM_FICTICIA,
        i2.HASH                 AS IMAGEM2_HASH,
        i2.FICTICIA             AS IMAGEM2_FICTICIA
        SQL;

    private const JOIN_IMAGEM = 'LEFT JOIN MATERIAL_IMAGEM i  ON i.CD_MATERIAL  = m.CD_MATERIAL AND i.POSICAO  = 1 '
                              . 'LEFT JOIN MATERIAL_IMAGEM i2 ON i2.CD_MATERIAL = m.CD_MATERIAL AND i2.POSICAO = 2';

    /**
     * Nome do subgrupo ("g.", pela PK do par) e do grupo ("gg.", à parte: há
     * material em subgrupo que não está no catálogo, mas o grupo está).
     * Continua uma linha por material.
     */
    private function joinGrupo(): string
    {
        return "LEFT JOIN {$this->tabelaGrupos} g ON g.CD_GRUPO = m.CD_GRUPO AND g.CD_SUB_GRUPO = m.CD_SUB_GRUPO "
             . "LEFT JOIN (SELECT CD_GRUPO, MAX(DESC_GRUPO) AS DESC_GRUPO FROM {$this->tabelaGrupos} GROUP BY CD_GRUPO) gg "
             . 'ON gg.CD_GRUPO = m.CD_GRUPO';
    }

    public const POR_PAGINA_MAX = 100;

    /** Nome da tabela já qualificado e entre aspas: "db_tst"."ESMATERI" */
    private readonly string $tabela;

    /** "db_tst"."GRUPO_SUBGRUPO": nomes dos grupos e subgrupos */
    private readonly string $tabelaGrupos;

    public function __construct(
        private readonly OracleConnection $db,
        string $schema,
    ) {
        // O schema entra no SQL como identificador (não dá para usar bind),
        // então só aceitamos nomes válidos do Oracle.
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_$#]{0,127}$/', $schema)) {
            throw new InvalidArgumentException('DB_SCHEMA inválido: ' . $schema);
        }
        $this->tabela = sprintf('"%s"."ESMATERI"', $schema);
        $this->tabelaGrupos = sprintf('"%s"."GRUPO_SUBGRUPO"', $schema);
    }

    /**
     * @param array{codigo?:string,descricao?:string,grupo?:string,subgrupo?:string} $filtros
     * @return array{dados: list<array<string, string|null>>, total: int, pagina: int, por_pagina: int}
     */
    public function buscar(array $filtros, int $pagina = 1, int $porPagina = 20): array
    {
        $pagina = max(1, $pagina);
        $porPagina = min(self::POR_PAGINA_MAX, max(1, $porPagina));

        [$where, $binds] = $this->montarFiltros($filtros);

        $total = (int) ($this->db->queryOne(
            "SELECT COUNT(*) AS TOTAL FROM {$this->tabela} m {$where}",
            $binds
        )['TOTAL'] ?? 0);

        $dados = $total === 0 ? [] : $this->db->query(
            'SELECT ' . self::COLUNAS . "
               FROM {$this->tabela} m
               " . self::JOIN_IMAGEM . '
               ' . $this->joinGrupo() . "
               {$where}
              ORDER BY m.CD_MATERIAL
             OFFSET :pular ROWS FETCH NEXT :qtd ROWS ONLY",
            $binds + ['pular' => ($pagina - 1) * $porPagina, 'qtd' => $porPagina]
        );

        return ['dados' => $dados, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    }

    /** @return array<string, string|null>|null */
    public function porCodigo(string $cdMaterial): ?array
    {
        return $this->db->queryOne(
            'SELECT ' . self::COLUNAS . " FROM {$this->tabela} m " . self::JOIN_IMAGEM . ' ' . $this->joinGrupo() . ' WHERE m.CD_MATERIAL = :cd',
            ['cd' => $cdMaterial]
        );
    }

    /**
     * Só os grupos que têm material, com o nome quando cadastrado.
     *
     * @return list<array{CD_GRUPO: string, DESCRICAO: string|null}>
     */
    public function listarGrupos(): array
    {
        return $this->db->query(
            "SELECT m.CD_GRUPO, MAX(TRIM(g.DESC_GRUPO)) AS DESCRICAO
               FROM (SELECT DISTINCT TRIM(CD_GRUPO) AS CD_GRUPO FROM {$this->tabela}) m
               LEFT JOIN {$this->tabelaGrupos} g ON g.CD_GRUPO = m.CD_GRUPO
              GROUP BY m.CD_GRUPO
              ORDER BY m.CD_GRUPO"
        );
    }

    /**
     * Só os subgrupos do grupo que têm material, com o nome quando cadastrado.
     *
     * @return list<array{CD_SUB_GRUPO: string, DESCRICAO: string|null}>
     */
    public function listarSubGrupos(string $grupo): array
    {
        return $this->db->query(
            "SELECT m.CD_SUB_GRUPO, TRIM(g.DESC_SUBGRUPO) AS DESCRICAO
               FROM (SELECT DISTINCT TRIM(CD_SUB_GRUPO) AS CD_SUB_GRUPO
                       FROM {$this->tabela}
                      WHERE CD_GRUPO = :grupo) m
               LEFT JOIN {$this->tabelaGrupos} g
                 ON g.CD_GRUPO = :grupo AND g.CD_SUB_GRUPO = m.CD_SUB_GRUPO
              ORDER BY m.CD_SUB_GRUPO",
            ['grupo' => $grupo]
        );
    }

    /**
     * @param array<string, string> $filtros
     * @return array{0: string, 1: array<string, string>}
     */
    private function montarFiltros(array $filtros): array
    {
        $condicoes = [];
        $binds = [];

        $codigo = trim((string) ($filtros['codigo'] ?? ''));
        if ($codigo !== '') {
            // Prefixo: o código é hierárquico (grupo+sub+seq) e assim o índice
            // KESMATERI1 é aproveitado.
            $condicoes[] = "m.CD_MATERIAL LIKE :codigo ESCAPE '\\'";
            $binds['codigo'] = self::escaparLike($codigo) . '%';
        }

        $descricao = trim((string) ($filtros['descricao'] ?? ''));
        if ($descricao !== '') {
            // Sem diferenciar acento nem maiúscula: "balanca" acha "BALANÇA".
            $condicoes[] = self::semAcento('m.DESCRICAO') . ' LIKE ' . self::semAcento(':descricao') . " ESCAPE '\\'";
            $binds['descricao'] = '%' . self::escaparLike($descricao) . '%';
        }

        $grupo = trim((string) ($filtros['grupo'] ?? ''));
        if ($grupo !== '') {
            $condicoes[] = 'm.CD_GRUPO = :grupo';
            $binds['grupo'] = $grupo;
        }

        $subgrupo = trim((string) ($filtros['subgrupo'] ?? ''));
        if ($subgrupo !== '') {
            $condicoes[] = 'm.CD_SUB_GRUPO = :subgrupo';
            $binds['subgrupo'] = $subgrupo;
        }

        $where = $condicoes === [] ? '' : 'WHERE ' . implode(' AND ', $condicoes);

        return [$where, $binds];
    }

    /**
     * Maiúsculas e sem acento, em SQL. TRANSLATE com a lista explícita (e não
     * CONVERT para US7ASCII, que troca "Ã" por "?") funciona igual no 19c.
     */
    private static function semAcento(string $expr): string
    {
        return "TRANSLATE(UPPER({$expr}), 'ÁÀÂÃÄÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇÑ', 'AAAAAEEEEIIIIOOOOOUUUUCN')";
    }

    /** Faz '%' e '_' digitados pelo usuário valerem como texto, não como curinga. */
    private static function escaparLike(string $valor): string
    {
        return strtr($valor, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
