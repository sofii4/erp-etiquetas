<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\OracleConnection;
use Throwable;

/**
 * SQL da MATERIAL_IMAGEM — tabela própria do módulo, no schema do usuário
 * da aplicação (por isso sem prefixo de schema). Ver
 * docker/oracle/sql/04-material-imagem.sql.
 *
 * A tela só usa ler(). salvar()/remover() são dos scripts de manutenção
 * (scripts/imagem-material.php e gerar-imagens-ficticias.php).
 */
final class ImagemRepository
{
    /** Sem MINIATURA (imagem incluída à mão por SQL), a miniatura é a própria IMAGEM. */
    private const COLUNA = ['mini' => 'COALESCE(MINIATURA, IMAGEM)', 'grande' => 'IMAGEM'];

    public function __construct(private readonly OracleConnection $db)
    {
    }

    /**
     * Lê UMA das versões (só o BLOB pedido sai do banco).
     *
     * @param 'mini'|'grande' $tamanho
     * @return array{MIME: ?string, HASH: string, CONTEUDO: string}|null
     */
    public function ler(string $cdMaterial, string $tamanho): ?array
    {
        $coluna = self::COLUNA[$tamanho] ?? self::COLUNA['mini'];

        /** @var array{MIME: ?string, HASH: string, CONTEUDO: string}|null */
        return $this->db->queryOne(
            "SELECT MIME, HASH, {$coluna} AS CONTEUDO FROM MATERIAL_IMAGEM WHERE CD_MATERIAL = :cd",
            ['cd' => $cdMaterial]
        );
    }

    /**
     * Grava ou substitui a imagem do material. HASH, BYTES_* e DT_ATUALIZACAO
     * são preenchidos pelo trigger TRG_MATERIAL_IMAGEM_VERSAO.
     *
     * @param array{imagem: string, miniatura: string, mime: string, largura: int, altura: int,
     *              bytes_original: int} $img saída do ImagemService
     */
    public function salvar(string $cdMaterial, array $img, bool $ficticia = false): void
    {
        $binds = [
            'cd'       => $cdMaterial,
            'mime'     => $img['mime'],
            'largura'  => $img['largura'],
            'altura'   => $img['altura'],
            'bytes_o'  => $img['bytes_original'],
            'ficticia' => $ficticia ? 'S' : 'N',
        ];
        $blobs = ['miniatura' => $img['miniatura'], 'imagem' => $img['imagem']];

        try {
            $alteradas = $this->db->executarDml(
                'UPDATE MATERIAL_IMAGEM
                    SET MIME = :mime, LARGURA = :largura, ALTURA = :altura,
                        BYTES_ORIGINAL = :bytes_o, FICTICIA = :ficticia,
                        MINIATURA = :miniatura, IMAGEM = :imagem
                  WHERE CD_MATERIAL = :cd',
                $binds,
                $blobs
            );

            if ($alteradas === 0) {
                $this->db->executarDml(
                    'INSERT INTO MATERIAL_IMAGEM
                        (CD_MATERIAL, MIME, LARGURA, ALTURA, BYTES_ORIGINAL, FICTICIA, MINIATURA, IMAGEM)
                     VALUES
                        (:cd, :mime, :largura, :altura, :bytes_o, :ficticia, :miniatura, :imagem)',
                    $binds,
                    $blobs
                );
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function remover(string $cdMaterial): bool
    {
        try {
            $removidas = $this->db->executarDml(
                'DELETE FROM MATERIAL_IMAGEM WHERE CD_MATERIAL = :cd',
                ['cd' => $cdMaterial]
            );
            $this->db->commit();

            return $removidas > 0;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** @return array<string, bool> CD_MATERIAL => é fictícia? (só quem já tem imagem) */
    public function existentes(): array
    {
        $linhas = $this->db->query('SELECT CD_MATERIAL, FICTICIA FROM MATERIAL_IMAGEM');

        return array_map(
            static fn (?string $f): bool => $f === 'S',
            array_column($linhas, 'FICTICIA', 'CD_MATERIAL')
        );
    }
}
