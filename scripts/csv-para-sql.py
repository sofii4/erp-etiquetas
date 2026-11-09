#!/usr/bin/env python3
"""
Converte o CSV exportado da ESMATERI do cliente em INSERTs para o Oracle local.

Uso:
    python3 scripts/csv-para-sql.py var/arquivo.csv
    -> gera docker/oracle/sql/03-dados-cliente.sql (fora do git: dados reais)

Formato esperado do CSV (export do ERP):
  - separador ',', texto entre aspas, números sem aspas, CRLF;
  - cabeçalho com as colunas da ESMATERI na ordem do DDL (colunas extras
    no fim, como RN/PRIORIDADE, são ignoradas);
  - datas AA/MM/DD (NLS 'RR/MM/DD'), campo vazio = NULL.

Os tipos de cada coluna saem do próprio DDL (02-ddl-esmateri.sql).
"""
import csv
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
DDL = RAIZ / "docker/oracle/sql/02-ddl-esmateri.sql"
SAIDA = RAIZ / "docker/oracle/sql/03-dados-cliente.sql"


def colunas_ddl() -> list[tuple[str, str, int | None]]:
    """[(nome, tipo, tamanho em bytes p/ VARCHAR2)] na ordem do CREATE TABLE."""
    texto = DDL.read_text(encoding="utf-8")
    return [
        (nome, tipo, int(tam) if tam else None)
        for nome, tipo, tam in re.findall(
            r'"(\w+)"\s+(VARCHAR2|NUMBER|DATE)(?:\((\d+)(?:,\d+)?(?: BYTE)?\))?', texto
        )
    ]


def literal(valor: str, tipo: str, tamanho: int | None, onde: str) -> str:
    if tipo == "DATE":
        if valor == "":
            return "NULL"
        m = re.fullmatch(r"(\d{2})/(\d{2})/(\d{2})", valor)
        if not m:
            raise ValueError(f"{onde}: data fora do formato AA/MM/DD: {valor!r}")
        aa, mm, dd = m.groups()
        return f"TO_DATE('{aa}/{mm}/{dd}', 'RR/MM/DD')"
    if tipo == "NUMBER":
        if valor == "":
            return "NULL"
        if not re.fullmatch(r"-?\d+(\.\d+)?", valor):
            raise ValueError(f"{onde}: número inválido: {valor!r}")
        return valor
    # VARCHAR2: no ERP os NOT NULL "vazios" são ' ' ('' viraria NULL no Oracle)
    if valor == "":
        valor = " "
    if tamanho and len(valor.encode("utf-8")) > tamanho:
        raise ValueError(f"{onde}: {len(valor.encode())} bytes > VARCHAR2({tamanho}): {valor!r}")
    return "'" + valor.replace("'", "''") + "'"


def main() -> None:
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    origem = Path(sys.argv[1])
    cols = colunas_ddl()
    nomes = [c[0] for c in cols]

    with origem.open(newline="", encoding="utf-8-sig") as f:
        linhas = list(csv.reader(f))
    cabecalho, dados = linhas[0], [l for l in linhas[1:] if any(v.strip() for v in l)]

    if cabecalho[: len(nomes)] != nomes:
        sys.exit("Cabeçalho do CSV não bate com as colunas do DDL (ordem ou nomes).")

    lista = ", ".join(nomes)
    saida = [
        "-- ============================================",
        f"-- GERADO por scripts/csv-para-sql.py a partir de {origem.name}.",
        "-- Dados reais do cliente: NÃO versionar (está no .gitignore).",
        "-- Quando este arquivo existe, o 01-setup.sh usa ele no lugar dos mocks.",
        "-- ============================================",
        "WHENEVER SQLERROR EXIT SQL.SQLCODE",
        "SET DEFINE OFF",
        "",
    ]
    for n, linha in enumerate(dados, start=2):
        if len(linha) < len(nomes):
            sys.exit(f"Linha {n}: {len(linha)} campos, esperado ao menos {len(nomes)}.")
        valores = ", ".join(
            literal(v, tipo, tam, f"linha {n}, {nome}")
            for v, (nome, tipo, tam) in zip(linha, cols)
        )
        saida += [
            f"-- {linha[2]} {linha[4]}",
            f"INSERT INTO ESMATERI ({lista})",
            f"VALUES ({valores});",
            "",
        ]
    saida.append("COMMIT;")

    SAIDA.write_text("\n".join(saida) + "\n", encoding="utf-8")
    print(f"{len(dados)} materiais -> {SAIDA.relative_to(RAIZ)}")


if __name__ == "__main__":
    main()
