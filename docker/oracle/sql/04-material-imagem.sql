-- ============================================
-- Imagens dos materiais — tabela PRÓPRIA do módulo, no schema do usuário da
-- aplicação. A ESMATERI (do ERP) continua intocada e só com SELECT.
--
-- O 01-setup.sh roda este arquivo com CURRENT_SCHEMA = DB_USER.
--
-- A tela mostra, inclui (câmera/arquivo), troca e remove. Também dá pelo banco:
--  - recomendado: scripts/imagem-material.php (reduz e converte para WebP,
--    gera a miniatura) — ver README;
--  - ou direto por SQL/DBeaver/SQL Developer: basta CD_MATERIAL + IMAGEM
--    (JPG, PNG, WebP ou GIF). MINIATURA é opcional (sem ela a tela usa a
--    IMAGEM) e o trigger abaixo cuida de HASH, tamanhos e data.
--
-- Otimizações:
--  - Pelo script, IMAGEM fica com até 1024 px (~10–100 KB) e MINIATURA com
--    até 160 px (~1 KB), ambas em WebP.
--  - SECUREFILE (padrão do 19c). Sem COMPRESS/DEDUPLICATE: exigem a licença
--    Advanced Compression e WebP já vem comprimido.
--  - MINIATURA fica DENTRO da linha quando cabe (até ~4000 bytes): a lista
--    lê a miniatura sem acessar o segmento do LOB.
--  - IMAGEM fica FORA da linha: a linha continua pequena e o LEFT JOIN da
--    listagem (que só lê HASH) não carrega a imagem grande.
--  - CACHE nos dois LOBs: as imagens são lidas muito mais do que gravadas.
--  - HASH vira ETag e versão na URL: o navegador guarda a imagem em cache e
--    só baixa de novo quando ela muda.
-- ============================================
WHENEVER SQLERROR EXIT SQL.SQLCODE

CREATE TABLE "MATERIAL_IMAGEM" (
    "CD_MATERIAL"     VARCHAR2(20 BYTE)  NOT NULL,
    "MIME"            VARCHAR2(30 BYTE),
    "LARGURA"         NUMBER(5,0),
    "ALTURA"          NUMBER(5,0),
    "BYTES_IMAGEM"    NUMBER(10,0),
    "BYTES_MINIATURA" NUMBER(10,0),
    "BYTES_ORIGINAL"  NUMBER(10,0),
    "HASH"            VARCHAR2(32 BYTE)  NOT NULL,
    "FICTICIA"        CHAR(1 BYTE)       DEFAULT 'N' NOT NULL,
    "DT_ATUALIZACAO"  DATE               DEFAULT SYSDATE NOT NULL,
    "MINIATURA"       BLOB,
    "IMAGEM"          BLOB               NOT NULL,
    CONSTRAINT "PK_MATERIAL_IMAGEM" PRIMARY KEY ("CD_MATERIAL"),
    CONSTRAINT "CK_MATERIAL_IMAGEM_FICT" CHECK ("FICTICIA" IN ('S', 'N'))
)
LOB ("MINIATURA") STORE AS SECUREFILE (ENABLE STORAGE IN ROW CACHE)
LOB ("IMAGEM")    STORE AS SECUREFILE (DISABLE STORAGE IN ROW CACHE);

-- Toda inclusão/alteração ganha uma versão nova (HASH) e a data, mesmo
-- quando feita à mão por SQL — senão o navegador continuaria mostrando a
-- imagem antiga do cache.
CREATE OR REPLACE TRIGGER "TRG_MATERIAL_IMAGEM_VERSAO"
BEFORE INSERT OR UPDATE ON "MATERIAL_IMAGEM"
FOR EACH ROW
BEGIN
    :NEW."HASH"            := LOWER(SUBSTR(RAWTOHEX(SYS_GUID()), 1, 16));
    :NEW."DT_ATUALIZACAO"  := SYSDATE;
    :NEW."BYTES_IMAGEM"    := DBMS_LOB.GETLENGTH(:NEW."IMAGEM");
    :NEW."BYTES_MINIATURA" := DBMS_LOB.GETLENGTH(:NEW."MINIATURA");
END;
/

COMMENT ON TABLE "MATERIAL_IMAGEM" IS 'Imagem de cada material da ESMATERI (enviada pela tela ou pelo script de manutenção)';
COMMENT ON COLUMN "MATERIAL_IMAGEM"."CD_MATERIAL" IS 'Mesmo CD_MATERIAL da ESMATERI';
COMMENT ON COLUMN "MATERIAL_IMAGEM"."IMAGEM" IS 'JPG, PNG, WebP ou GIF (pelo script: WebP até 1024 px)';
COMMENT ON COLUMN "MATERIAL_IMAGEM"."MINIATURA" IS 'Opcional: versão pequena para a tabela (sem ela a tela usa IMAGEM)';
COMMENT ON COLUMN "MATERIAL_IMAGEM"."HASH" IS 'Versão da imagem (ETag/URL); preenchido pelo trigger';
COMMENT ON COLUMN "MATERIAL_IMAGEM"."FICTICIA" IS 'S = imagem ilustrativa gerada para teste';
