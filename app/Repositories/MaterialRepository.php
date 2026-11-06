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
 * O ERP grava "vazio" como ' ' nas colunas VARCHAR2 NOT NULL; TRIM(' ') no
 * Oracle vira NULL, então a API devolve null nesses casos.
 */
final class MaterialRepository
{
    private const COLUNAS = <<<'SQL'
        TRIM(CD_MATERIAL)     AS CD_MATERIAL,
        TRIM(DESCRICAO)       AS DESCRICAO,
        TRIM(CD_GRUPO)        AS CD_GRUPO,
        TRIM(CD_SUB_GRUPO)    AS CD_SUB_GRUPO,
        TRIM(CD_UNIDADE_MEDI) AS CD_UNIDADE_MEDI,
        TRIM(LOCALIZACAO)     AS LOCALIZACAO,
        TO_CHAR(DT_CADASTRO,    'YYYY-MM-DD"T"HH24:MI:SS') AS DT_CADASTRO,
        TO_CHAR(DT_MODIFICACAO, 'YYYY-MM-DD"T"HH24:MI:SS') AS DT_MODIFICACAO,
        TRIM(USUARIO_CRIACAO) AS USUARIO_CRIACAO,
        TRIM(USUARIO_MODIFIC) AS USUARIO_MODIFIC
        SQL;

    public const POR_PAGINA_MAX = 100;

    /** Nome da tabela já qualificado e entre aspas: "db_tst"."ESMATERI" */
    private readonly string $tabela;

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
            "SELECT COUNT(*) AS TOTAL FROM {$this->tabela} {$where}",
            $binds
        )['TOTAL'] ?? 0);

        $dados = $total === 0 ? [] : $this->db->query(
            'SELECT ' . self::COLUNAS . "
               FROM {$this->tabela}
               {$where}
              ORDER BY CD_MATERIAL
             OFFSET :pular ROWS FETCH NEXT :qtd ROWS ONLY",
            $binds + ['pular' => ($pagina - 1) * $porPagina, 'qtd' => $porPagina]
        );

        return ['dados' => $dados, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    }

    /** @return array<string, string|null>|null */
    public function porCodigo(string $cdMaterial): ?array
    {
        return $this->db->queryOne(
            'SELECT ' . self::COLUNAS . " FROM {$this->tabela} WHERE CD_MATERIAL = :cd",
            ['cd' => $cdMaterial]
        );
    }

    /** @return list<string> */
    public function listarGrupos(): array
    {
        $linhas = $this->db->query(
            "SELECT DISTINCT TRIM(CD_GRUPO) AS CD_GRUPO FROM {$this->tabela} ORDER BY 1"
        );

        return array_column($linhas, 'CD_GRUPO');
    }

    /** @return list<string> */
    public function listarSubGrupos(string $grupo): array
    {
        $linhas = $this->db->query(
            "SELECT DISTINCT TRIM(CD_SUB_GRUPO) AS CD_SUB_GRUPO
               FROM {$this->tabela}
              WHERE CD_GRUPO = :grupo
              ORDER BY 1",
            ['grupo' => $grupo]
        );

        return array_column($linhas, 'CD_SUB_GRUPO');
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
            $condicoes[] = "CD_MATERIAL LIKE :codigo ESCAPE '\\'";
            $binds['codigo'] = self::escaparLike($codigo) . '%';
        }

        $descricao = trim((string) ($filtros['descricao'] ?? ''));
        if ($descricao !== '') {
            $condicoes[] = "UPPER(DESCRICAO) LIKE :descricao ESCAPE '\\'";
            $binds['descricao'] = '%' . self::escaparLike(mb_strtoupper($descricao)) . '%';
        }

        $grupo = trim((string) ($filtros['grupo'] ?? ''));
        if ($grupo !== '') {
            $condicoes[] = 'CD_GRUPO = :grupo';
            $binds['grupo'] = $grupo;
        }

        $subgrupo = trim((string) ($filtros['subgrupo'] ?? ''));
        if ($subgrupo !== '') {
            $condicoes[] = 'CD_SUB_GRUPO = :subgrupo';
            $binds['subgrupo'] = $subgrupo;
        }

        $where = $condicoes === [] ? '' : 'WHERE ' . implode(' AND ', $condicoes);

        return [$where, $binds];
    }

    /** Faz '%' e '_' digitados pelo usuário valerem como texto, não como curinga. */
    private static function escaparLike(string $valor): string
    {
        return strtr($valor, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
