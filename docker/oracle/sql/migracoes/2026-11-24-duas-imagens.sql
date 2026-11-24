-- ============================================
-- Migração: até DUAS imagens por material (coluna POSICAO).
--
-- Só para bancos criados ANTES desta mudança — num banco novo o
-- 04-material-imagem.sql já cria a tabela com POSICAO. As imagens que já
-- existem viram a imagem 1 (principal). Ver README ("Banco já existente").
--
-- Roda no schema do usuário da aplicação (dono da MATERIAL_IMAGEM).
-- No 19c, ADD COLUMN com DEFAULT + NOT NULL só altera o dicionário: não
-- reescreve as linhas nem os BLOBs.
-- ============================================
WHENEVER SQLERROR EXIT SQL.SQLCODE

ALTER TABLE "MATERIAL_IMAGEM" ADD ("POSICAO" NUMBER(1,0) DEFAULT 1 NOT NULL);

ALTER TABLE "MATERIAL_IMAGEM" DROP CONSTRAINT "PK_MATERIAL_IMAGEM" DROP INDEX;
ALTER TABLE "MATERIAL_IMAGEM" ADD CONSTRAINT "PK_MATERIAL_IMAGEM" PRIMARY KEY ("CD_MATERIAL", "POSICAO");
ALTER TABLE "MATERIAL_IMAGEM" ADD CONSTRAINT "CK_MATERIAL_IMAGEM_POS" CHECK ("POSICAO" IN (1, 2));

COMMENT ON TABLE "MATERIAL_IMAGEM" IS 'Imagens (até duas) de cada material da ESMATERI (enviadas pela tela ou pelo script de manutenção)';
COMMENT ON COLUMN "MATERIAL_IMAGEM"."POSICAO" IS '1 = principal (miniatura da tabela), 2 = segunda imagem';
