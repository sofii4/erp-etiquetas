-- ============================================
-- Nomes dos grupos e subgrupos dos materiais (export do ERP do cliente:
-- CD_GRUPO, DESC_GRUPO, CD_SUB_GRUPO, DESC_SUBGRUPO). Só é lida pela
-- aplicação, para mostrar o nome junto do código. Sem schema: o 01-setup.sh
-- faz ALTER SESSION SET CURRENT_SCHEMA antes de rodar.
-- ============================================
WHENEVER SQLERROR EXIT SQL.SQLCODE

CREATE TABLE "GRUPO_SUBGRUPO"
   ("CD_GRUPO"      VARCHAR2(3 BYTE)  NOT NULL,
    "DESC_GRUPO"    VARCHAR2(60 BYTE) NOT NULL,
    "CD_SUB_GRUPO"  VARCHAR2(3 BYTE)  NOT NULL,
    "DESC_SUBGRUPO" VARCHAR2(60 BYTE) NOT NULL,
    CONSTRAINT "PK_GRUPO_SUBGRUPO" PRIMARY KEY ("CD_GRUPO", "CD_SUB_GRUPO")
   );
