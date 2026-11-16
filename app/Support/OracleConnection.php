<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Wrapper mínimo sobre o OCI8.
 *
 * Os repositories só conhecem query()/queryOne(): se um dia o driver mudar
 * (PDO_OCI, por exemplo), só esta classe é reescrita.
 *
 * A conexão é aberta na primeira consulta, então instanciar a classe em toda
 * requisição não custa nada para rotas que não usam o banco.
 */
final class OracleConnection
{
    /** @var resource|null */
    private $conn = null;

    public function __construct(
        private readonly string $connectString,
        private readonly string $user,
        private readonly string $password,
        private readonly string $charset = 'AL32UTF8',
    ) {
    }

    /** @param array{host:string,port:string,service:string,user:string,pass:string,charset?:string} $cfg */
    public static function fromConfig(array $cfg): self
    {
        foreach (['host', 'port', 'service', 'user', 'pass'] as $chave) {
            if (($cfg[$chave] ?? '') === '') {
                throw new \InvalidArgumentException(
                    "Configuração do Oracle incompleta: '{$chave}' vazio (confira DB_* no .env)."
                );
            }
        }

        return new self(
            sprintf('//%s:%s/%s', $cfg['host'], $cfg['port'], $cfg['service']),
            $cfg['user'],
            $cfg['pass'],
            $cfg['charset'] ?? 'AL32UTF8',
        );
    }

    /**
     * Executa um SELECT e devolve todas as linhas como arrays associativos
     * (chaves em MAIÚSCULO, como o Oracle devolve).
     *
     * @param array<string, scalar|null> $binds nomes sem ':' — ex.: ['grupo' => '001']
     * @return list<array<string, string|null>>
     */
    public function query(string $sql, array $binds = []): array
    {
        $stmt = $this->executar($sql, $binds);

        $linhas = [];
        oci_fetch_all($stmt, $linhas, 0, -1, OCI_FETCHSTATEMENT_BY_ROW | OCI_ASSOC);
        oci_free_statement($stmt);

        return $linhas;
    }

    /**
     * Uma linha só. Colunas BLOB/CLOB já vêm como string (OCI_RETURN_LOBS).
     *
     * @param array<string, scalar|null> $binds
     * @return array<string, string|null>|null
     */
    public function queryOne(string $sql, array $binds = []): ?array
    {
        $stmt = $this->executar($sql, $binds);
        $linha = oci_fetch_array($stmt, OCI_ASSOC | OCI_RETURN_NULLS | OCI_RETURN_LOBS);
        oci_free_statement($stmt);

        return $linha === false ? null : $linha;
    }

    /**
     * INSERT/UPDATE/DELETE SEM commit: quem chama fecha a transação com
     * commit() ou rollback().
     *
     * $blobs são binds de BLOB (conteúdo binário em string): cada um vira um
     * LOB temporário, então o tamanho não esbarra no limite de 32 KB do bind
     * comum de RAW/VARCHAR2.
     *
     * @param array<string, scalar|null> $binds
     * @param array<string, string>      $blobs
     * @return int linhas afetadas
     */
    public function executarDml(string $sql, array $binds = [], array $blobs = []): int
    {
        $conn = $this->conexao();
        $lobs = [];

        try {
            $stmt = $this->executar($sql, $binds, function ($stmt) use ($conn, $blobs, &$lobs, $sql): void {
                foreach ($blobs as $nome => $conteudo) {
                    $lob = oci_new_descriptor($conn, OCI_D_LOB);
                    if ($lob === false || !$lob->writeTemporary($conteudo, OCI_TEMP_BLOB)) {
                        throw OracleException::de(oci_error($conn), $sql);
                    }
                    // Bind por referência ao ELEMENTO do array (não a $lob, que
                    // muda a cada volta do laço).
                    $lobs[$nome] = $lob;
                    if (!@oci_bind_by_name($stmt, ':' . $nome, $lobs[$nome], -1, OCI_B_BLOB)) {
                        throw OracleException::de(oci_error($stmt), $sql);
                    }
                }
            });
            $linhas = oci_num_rows($stmt);
            oci_free_statement($stmt);

            return (int) $linhas;
        } finally {
            foreach ($lobs as $lob) {
                @$lob->close();
                $lob->free();
            }
        }
    }

    public function commit(): void
    {
        if ($this->conn !== null && !oci_commit($this->conn)) {
            throw OracleException::de(oci_error($this->conn));
        }
    }

    public function rollback(): void
    {
        if ($this->conn !== null) {
            @oci_rollback($this->conn);
        }
    }

    /**
     * @param array<string, scalar|null> $binds
     * @param (callable(resource): void)|null $bindsExtras binds especiais (LOB) antes do execute
     * @return resource
     */
    private function executar(string $sql, array $binds, ?callable $bindsExtras = null)
    {
        $conn = $this->conexao();

        $stmt = @oci_parse($conn, $sql);
        if ($stmt === false) {
            throw OracleException::de(oci_error($conn), $sql);
        }

        // oci_bind_by_name guarda uma REFERÊNCIA à variável: ela precisa
        // continuar viva (e com o valor certo) até o oci_execute.
        foreach ($binds as $nome => &$valor) {
            if (!@oci_bind_by_name($stmt, ':' . $nome, $valor)) {
                throw OracleException::de(oci_error($stmt), $sql);
            }
        }
        unset($valor);

        if ($bindsExtras !== null) {
            $bindsExtras($stmt);
        }

        if (!@oci_execute($stmt, OCI_DEFAULT)) {
            $erro = oci_error($stmt);
            oci_free_statement($stmt);
            throw OracleException::de($erro, $sql);
        }

        return $stmt;
    }

    /** @return resource */
    private function conexao()
    {
        if ($this->conn === null) {
            // pconnect: o worker do PHP-FPM reaproveita a sessão entre requisições.
            $conn = @oci_pconnect($this->user, $this->password, $this->connectString, $this->charset);
            if ($conn === false) {
                throw OracleException::de(oci_error());
            }
            $this->conn = $conn;
        }

        return $this->conn;
    }
}
