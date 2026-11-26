#!/usr/bin/env python3
"""
Converte os CSVs exportados do ERP do cliente em INSERTs para o Oracle local.

Uso:
    python3 scripts/csv-para-sql.py var/materiais.csv [var/grupos.csv]
    -> gera docker/oracle/sql/03-dados-cliente.sql (fora do git: dados reais)

materiais.csv (export da ESMATERI):
  - separador ',', texto entre aspas, números sem aspas, CRLF;
  - cabeçalho com as colunas da ESMATERI na ordem do DDL;
  - datas AA/MM/DD (NLS 'RR/MM/DD'), campo vazio = NULL;
  - linhas com número de colunas diferente do cabeçalho (algumas vêm
    deslocadas do ERP) entram só com os campos básicos (grupo, subgrupo,
    código, descrição, unidade); o resto fica com o valor "vazio" do ERP.

grupos.csv (opcional): CD_GRUPO, DESC_GRUPO, CD_SUB_GRUPO, DESC_SUBGRUPO
  -> tabela GRUPO_SUBGRUPO (nomes dos grupos e subgrupos na tela).

Os arquivos podem estar em UTF-8 ou Latin-1 (padrão do export do ERP).
Os tipos de cada coluna saem do próprio DDL (02-ddl-esmateri.sql).
"""
import csv
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
DDL = RAIZ / "docker/oracle/sql/02-ddl-esmateri.sql"
SAIDA = RAIZ / "docker/oracle/sql/03-dados-cliente.sql"

# Colunas que valem mesmo numa linha deslocada (são as primeiras do export).
BASICAS = 6  # CD_GRUPO .. CD_UNIDADE_MEDI


def colunas_ddl() -> list[tuple[str, str, int | None]]:
    """[(nome, tipo, tamanho em bytes p/ VARCHAR2)] na ordem do CREATE TABLE."""
    texto = DDL.read_text(encoding="utf-8")
    return [
        (nome, tipo, int(tam) if tam else None)
        for nome, tipo, tam in re.findall(
            r'"(\w+)"\s+(VARCHAR2|NUMBER|DATE)(?:\((\d+)(?:,\d+)?(?: BYTE)?\))?', texto
        )
    ]


def ler_csv(caminho: Path) -> list[list[str]]:
    bruto = caminho.read_bytes()
    try:
        texto = bruto.decode("utf-8-sig")
    except UnicodeDecodeError:
        texto = bruto.decode("latin-1")
    return list(csv.reader(texto.splitlines()))


def cortar_bytes(valor: str, limite: int) -> str:
    """Corta no limite de bytes UTF-8 sem partir um caractere."""
    return valor.encode("utf-8")[:limite].decode("utf-8", "ignore")


avisos: list[str] = []


def literal(valor: str, tipo: str, tamanho: int | None, onde: str) -> str:
    if tipo == "DATE":
        if valor == "":
            return "NULL"
        m = re.fullmatch(r"(\d{2})/(\d{2})/(\d{2})", valor)
        if not m:
            raise ValueError(f"{onde}: data fora do formato AA/MM/DD: {valor!r}")
        return f"TO_DATE('{valor}', 'RR/MM/DD')"
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
        # Ex.: 'Ø' ocupa 1 byte no ERP (Latin-1) e 2 no XE local (UTF-8).
        avisos.append(f"{onde}: cortado para {tamanho} bytes: {valor!r}")
        valor = cortar_bytes(valor, tamanho)
    return "'" + valor.replace("'", "''") + "'"


def vazio(tipo: str) -> str:
    """Valor que o ERP grava quando a coluna não tem dado."""
    return {"DATE": "", "NUMBER": "0"}.get(tipo, " ")


def inserts_materiais(origem: Path) -> list[str]:
    cols = colunas_ddl()
    nomes = [c[0] for c in cols]
    linhas = ler_csv(origem)
    cabecalho, dados = linhas[0], [l for l in linhas[1:] if any(v.strip() for v in l)]

    if cabecalho[: len(nomes)] != nomes:
        sys.exit("Cabeçalho do CSV de materiais não bate com as colunas do DDL (ordem ou nomes).")

    lista = ", ".join(nomes)
    saida = []
    for n, linha in enumerate(dados, start=2):
        if len(linha) != len(cabecalho):
            avisos.append(
                f"linha {n} ({linha[2].strip()}): {len(linha)} colunas em vez de "
                f"{len(cabecalho)}; entrou só com os campos básicos"
            )
            linha = linha[:BASICAS] + [vazio(tipo) for _, tipo, _ in cols[BASICAS:]]
        valores = ", ".join(
            literal(v, tipo, tam, f"linha {n}, {nome}")
            for v, (nome, tipo, tam) in zip(linha, cols)
        )
        saida += [f"INSERT INTO ESMATERI ({lista})", f"VALUES ({valores});"]
    print(f"{len(dados)} materiais")
    return saida


def inserts_grupos(origem: Path) -> list[str]:
    saida = []
    for n, linha in enumerate(ler_csv(origem)[1:], start=2):
        linha = [v.strip() for v in linha]
        if len(linha) < 4 or not linha[0]:
            continue  # linha em branco
        # Grupo sem subgrupo cadastrado (ex.: 25, 35) vem com subgrupo vazio:
        # entra como ' ' só para guardar o nome do grupo.
        valores = ", ".join(
            literal(v, "VARCHAR2", tam, f"grupos linha {n}")
            for v, tam in zip(linha[:4], (3, 60, 3, 60))
        )
        saida.append(
            f"INSERT INTO GRUPO_SUBGRUPO (CD_GRUPO, DESC_GRUPO, CD_SUB_GRUPO, DESC_SUBGRUPO) VALUES ({valores});"
        )
    print(f"{len(saida)} subgrupos")
    return saida


def main() -> None:
    if len(sys.argv) not in (2, 3):
        sys.exit(__doc__)
    arquivos = [Path(a) for a in sys.argv[1:]]

    saida = [
        "-- ============================================",
        f"-- GERADO por scripts/csv-para-sql.py a partir de {', '.join(a.name for a in arquivos)}.",
        "-- Dados reais do cliente: NÃO versionar (está no .gitignore).",
        "-- Quando este arquivo existe, o 01-setup.sh usa ele no lugar dos mocks.",
        "-- ============================================",
        "WHENEVER SQLERROR EXIT SQL.SQLCODE",
        "SET DEFINE OFF",
        "SET FEEDBACK OFF",
        "",
    ]
    saida += inserts_materiais(arquivos[0])
    if len(arquivos) == 2:
        saida += inserts_grupos(arquivos[1])
    saida.append("COMMIT;")

    SAIDA.write_text("\n".join(saida) + "\n", encoding="utf-8")
    for a in avisos:
        print("aviso:", a)
    print(f"-> {SAIDA.relative_to(RAIZ)}")


if __name__ == "__main__":
    main()
