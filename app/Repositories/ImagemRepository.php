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
 * Cada material tem até duas imagens (POSICAO 1 = principal, 2 = segunda).
 * Regra mantida aqui: a 2 só existe se houver a 1. Gravar a 2 sem ter a 1
 * grava como 1; remover a 1 promove a 2.
 *
 * Usado pela tela (ImagemController) e pelos scripts de manutenção
 * (scripts/imagem-material.php e gerar-imagens-ficticias.php).
 */
final class ImagemRepository
{
    public const POSICOES = [1, 2];

    /** Sem MINIATURA (imagem incluída à mão por SQL), a miniatura é a própria IMAGEM. */
    private const COLUNA = ['mini' => 'COALESCE(MINIATURA, IMAGEM)', 'grande' => 'IMAGEM'];

    public function __construct(private readonly OracleConnection $db)
    {
    }

    /**
     * Lê UMA das versões de UMA das imagens (só o BLOB pedido sai do banco).
     *
     * @param 'mini'|'grande' $tamanho
     * @return array{MIME: ?string, HASH: string, CONTEUDO: string}|null
     */
    public function ler(string $cdMaterial, int $posicao, string $tamanho): ?array
    {
        $coluna = self::COLUNA[$tamanho] ?? self::COLUNA['mini'];

        /** @var array{MIME: ?string, HASH: string, CONTEUDO: string}|null */
        return $this->db->queryOne(
            "SELECT MIME, HASH, {$coluna} AS CONTEUDO
               FROM MATERIAL_IMAGEM
              WHERE CD_MATERIAL = :cd AND POSICAO = :pos",
            ['cd' => $cdMaterial, 'pos' => $posicao]
        );
    }

    /**
     * Grava ou substitui uma imagem do material. HASH, BYTES_* e
     * DT_ATUALIZACAO são preenchidos pelo trigger TRG_MATERIAL_IMAGEM_VERSAO.
     *
     * @param array{imagem: string, miniatura: string, mime: string, largura: int, altura: int,
     *              bytes_original: int} $img saída do ImagemService
     * @return int posição em que a imagem ficou (2 vira 1 se ainda não houver a 1)
     */
    public function salvar(string $cdMaterial, array $img, bool $ficticia = false, int $posicao = 1): int
    {
        self::validarPosicao($posicao);

        try {
            if ($posicao === 2 && !$this->existe($cdMaterial, 1)) {
                $posicao = 1;
            }

            $binds = [
                'cd'       => $cdMaterial,
                'pos'      => $posicao,
                'mime'     => $img['mime'],
                'largura'  => $img['largura'],
                'altura'   => $img['altura'],
                'bytes_o'  => $img['bytes_original'],
                'ficticia' => $ficticia ? 'S' : 'N',
            ];
            $blobs = ['miniatura' => $img['miniatura'], 'imagem' => $img['imagem']];

            $alteradas = $this->db->executarDml(
                'UPDATE MATERIAL_IMAGEM
                    SET MIME = :mime, LARGURA = :largura, ALTURA = :altura,
                        BYTES_ORIGINAL = :bytes_o, FICTICIA = :ficticia,
                        MINIATURA = :miniatura, IMAGEM = :imagem
                  WHERE CD_MATERIAL = :cd AND POSICAO = :pos',
                $binds,
                $blobs
            );

            if ($alteradas === 0) {
                $this->db->executarDml(
                    'INSERT INTO MATERIAL_IMAGEM
                        (CD_MATERIAL, POSICAO, MIME, LARGURA, ALTURA, BYTES_ORIGINAL, FICTICIA, MINIATURA, IMAGEM)
                     VALUES
                        (:cd, :pos, :mime, :largura, :altura, :bytes_o, :ficticia, :miniatura, :imagem)',
                    $binds,
                    $blobs
                );
            }

            $this->db->commit();

            return $posicao;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** Remove uma imagem. Se for a 1 e houver a 2, a 2 vira a principal. */
    public function remover(string $cdMaterial, int $posicao = 1): bool
    {
        self::validarPosicao($posicao);

        try {
            $removidas = $this->db->executarDml(
                'DELETE FROM MATERIAL_IMAGEM WHERE CD_MATERIAL = :cd AND POSICAO = :pos',
                ['cd' => $cdMaterial, 'pos' => $posicao]
            );
            if ($removidas > 0 && $posicao === 1) {
                $this->db->executarDml(
                    'UPDATE MATERIAL_IMAGEM SET POSICAO = 1 WHERE CD_MATERIAL = :cd AND POSICAO = 2',
                    ['cd' => $cdMaterial]
                );
            }
            $this->db->commit();

            return $removidas > 0;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Troca a 1 com a 2 (a segunda vira a principal). Só age quando o
     * material tem as duas. O trigger dá HASH novo às duas linhas, então o
     * cache do navegador não confunde uma com a outra.
     */
    public function inverter(string $cdMaterial): bool
    {
        try {
            // Um UPDATE só: o Oracle confere a PK no fim do comando.
            $alteradas = $this->db->executarDml(
                'UPDATE MATERIAL_IMAGEM
                    SET POSICAO = 3 - POSICAO
                  WHERE CD_MATERIAL = :cd
                    AND (SELECT COUNT(*) FROM MATERIAL_IMAGEM WHERE CD_MATERIAL = :cd) = 2',
                ['cd' => $cdMaterial]
            );
            $this->db->commit();

            return $alteradas === 2;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** @return array<string, bool> CD_MATERIAL => a principal é fictícia? (só quem já tem imagem) */
    public function existentes(): array
    {
        $linhas = $this->db->query('SELECT CD_MATERIAL, FICTICIA FROM MATERIAL_IMAGEM WHERE POSICAO = 1');

        return array_map(
            static fn (?string $f): bool => $f === 'S',
            array_column($linhas, 'FICTICIA', 'CD_MATERIAL')
        );
    }

    private function existe(string $cdMaterial, int $posicao): bool
    {
        return $this->db->queryOne(
            'SELECT 1 AS OK FROM MATERIAL_IMAGEM WHERE CD_MATERIAL = :cd AND POSICAO = :pos',
            ['cd' => $cdMaterial, 'pos' => $posicao]
        ) !== null;
    }

    private static function validarPosicao(int $posicao): void
    {
        if (!in_array($posicao, self::POSICOES, true)) {
            throw new \InvalidArgumentException('Posição de imagem inválida: ' . $posicao);
        }
    }
}
