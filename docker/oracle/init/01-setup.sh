#!/bin/bash
# ============================================
# Executado pela imagem gvenzl/oracle-xe só no PRIMEIRO boot (volume vazio).
# Para rodar de novo: scripts/reset.sh
#
#   - "db_tst"      : dono da ESMATERI, como no ERP. Schema-only (sem senha,
#                     ninguém faz login com ele).
#   - ${DB_USER}    : usuário da aplicação, só com SELECT na ESMATERI
#                     (espelha o usuário read-only pedido para a AWS).
#
# Os SQLs de docker/oracle/sql/ não têm nome de schema nem de PDB, então
# servem também para o 19c e para outros ambientes.
# ============================================
set -euo pipefail

PDB="${DB_SERVICE:-XEPDB1}"
SCHEMA="${DB_SCHEMA:?DB_SCHEMA não definido}"
APP_USER="${DB_USER:?DB_USER não definido}"
APP_PASS="${DB_PASS:?DB_PASS não definido}"
# docker/oracle/sql montado pelo docker-compose.yml
SQL_DIR=/opt/erp-sql

roda_sql() {
    sqlplus -s / as sysdba <<EOF
WHENEVER SQLERROR EXIT SQL.SQLCODE
SET ECHO OFF FEEDBACK ON
ALTER SESSION SET CONTAINER = ${PDB};
$1
EXIT
EOF
}

echo "ERP: criando usuários em ${PDB}..."
roda_sql "
CREATE USER \"${SCHEMA}\" NO AUTHENTICATION
    DEFAULT TABLESPACE USERS QUOTA UNLIMITED ON USERS;

CREATE USER ${APP_USER} IDENTIFIED BY \"${APP_PASS}\"
    DEFAULT TABLESPACE USERS;
GRANT CREATE SESSION TO ${APP_USER};
"

for f in "$SQL_DIR"/02-*.sql "$SQL_DIR"/03-*.sql; do
    echo "ERP: rodando $(basename "$f")..."
    roda_sql "ALTER SESSION SET CURRENT_SCHEMA = \"${SCHEMA}\";
@${f}"
done

echo "ERP: liberando leitura para ${APP_USER}..."
roda_sql "GRANT SELECT ON \"${SCHEMA}\".ESMATERI TO ${APP_USER};"

echo "ERP: setup concluído."
