# Etiquetas ERP

Módulo que complementa o ERP: consulta os materiais da tabela `ESMATERI` (Oracle) e gera etiquetas para imprimir, com um QR Code que guarda o código do material (`CD_MATERIAL`).

**Banco de dados em uso:** Oracle Database **XE 21c** (Express Edition, gratuito), rodando em Docker com a imagem `gvenzl/oracle-xe:21-slim-faststart`. É o banco de **desenvolvimento e testes**.

> ⚠️ **Antes de colocar em produção: migrar para o Oracle 19c.** O 19c é a versão exigida para produção (é a do ERP na AWS). O código já foi escrito usando só recursos que existem no 19c, mas a validação nele ainda não foi feita. Veja [Migração para o Oracle 19c](#migração-para-o-oracle-19c-antes-da-produção).

**Dados atuais:** export real do ERP com **5.000 materiais** e a tabela de **grupos/subgrupos** (11 grupos, 251 subgrupos). Os CSVs ficam fora do git (dados do cliente). Sem eles, o sistema sobe com 22 materiais fictícios (veja o [passo 3](#3-carregar-os-materiais-reais-do-cliente)).

**O que já funciona (Fase 1)**

- Tela de consulta de materiais, com filtros por código, descrição (sem diferenciar acento: "balanca" acha "BALANÇA") e grupo/subgrupo, e com paginação. Grupos e subgrupos aparecem com o nome ("30 · EQUIPAMENTOS PADRAO"), vindo da tabela `GRUPO_SUBGRUPO`; o filtro só mostra os que têm material.
- **Até duas imagens por material**, guardadas no banco: a **principal** aparece em miniatura na tabela (com o selo "2" quando há a segunda) e as duas são ampliadas ao clicar. No modal, um seletor alterna entre "Principal" e "Foto 2"; para cada uma dá para **tirar a foto com a câmera** ou **enviar um arquivo** (com prévia antes de salvar), remover, e **tornar a segunda a principal**. Material sem imagem mostra "+ Foto". Por enquanto não há controle de permissão: qualquer usuário pode alterar. Também dá para manter as imagens direto no banco (veja [Manutenção das imagens](#manutenção-das-imagens)).
- Etiqueta em PDF (padrão 100 × 50 mm, configurável) com os dados do material e o QR Code.
- Botão **Escanear QR Code**: lê o QR da etiqueta pela câmera (celular ou notebook) ou pelo campo de código e mostra o material, com atalhos para imprimir a etiqueta e filtrar a lista. A entrada e a saída de estoque (Fase 2) ainda não estão disponíveis.

### Como funciona o leitor de QR Code

- O QR guarda só o `CD_MATERIAL`. Depois da leitura, a tela busca o material em `GET /api/materiais/{cd}`.
- A leitura pela câmera usa a biblioteca [qr-scanner](https://github.com/nimiq/qr-scanner) (MIT), salva em `public/assets/js/vendor/qr-scanner` (não depende de CDN). Ela usa o leitor nativo do navegador quando existe e, se não existir, um worker próprio.
- **O navegador só libera a câmera em HTTPS ou em `localhost`.** Por isso o nginx também atende em HTTPS (porta `8191`), com um certificado autoassinado gerado por `scripts/gerar-certificado.sh`. Pelo celular, acesse `https://<ip-da-vm>:8191` e aceite o aviso de certificado uma vez. Em `http://`, a tela avisa e oferece o campo de código.
- O campo de código também atende **coletores com leitor embutido**, que "digitam" o código lido e mandam Enter.
- A câmera é desligada sempre que a janela fecha.

A `ESMATERI` e a `GRUPO_SUBGRUPO` (nomes dos grupos e subgrupos, export do ERP) são acessadas **só para leitura**: o usuário da aplicação tem apenas `SELECT` nelas. As imagens ficam numa tabela própria do módulo (`MATERIAL_IMAGEM`), no schema do usuário da aplicação.

### Como as imagens são guardadas

- Ficam **no Oracle, em BLOB**, nunca em arquivo no servidor: uma linha por imagem, com `POSICAO` 1 (principal) ou 2. A PK é `(CD_MATERIAL, POSICAO)`.
- Regra: **a 2 só existe se houver a 1**. Enviar a 2 sem ter a principal grava como principal; remover a principal promove a 2; "Tornar principal" troca as duas num único `UPDATE`.
- Câmera: no celular abre o app de câmera do aparelho; no computador abre o vídeo ao vivo no próprio modal (só em HTTPS ou `localhost`). Fotos grandes são reduzidas no navegador (até 2048 px) antes do envio.
- Pela tela ou pelo script de manutenção, qualquer arquivo (JPG, PNG, WebP ou GIF, até 10 MB) vira **WebP** em duas versões:
  - **imagem**: até 1024 px, ~5–100 KB;
  - **miniatura**: até 160 px, ~1 KB.

  A rotação das fotos de celular (EXIF) é corrigida e os metadados (GPS, câmera) são descartados. No teste, uma foto de 1,2 MB virou 95 KB no banco.
- A miniatura fica guardada *dentro* da linha da tabela e a imagem grande fica fora dela (SECUREFILE). A listagem só lê o `HASH` das duas posições (um LEFT JOIN por posição, então continua uma linha por material), nunca o BLOB. A API devolve `IMAGEM_HASH`/`IMAGEM_FICTICIA` (principal) e `IMAGEM2_HASH`/`IMAGEM2_FICTICIA`.
- As imagens são servidas com `ETag` e `Cache-Control: immutable`, usando o `HASH` na URL. O trigger `TRG_MATERIAL_IMAGEM_VERSAO` gera um `HASH` novo a cada inclusão ou alteração, inclusive as feitas à mão por SQL. Assim o navegador nunca fica preso numa imagem antiga.
- O export do cliente não tem fotos. Por isso `scripts/gerar-imagens-ficticias.php` desenha **ilustrações** por tipo de material (parafuso, porca, tubo, perfil, chapa, motorredutor, elevador...). Elas não levam selo na tela, mas ficam marcadas no banco com `FICTICIA = 'S'` (assim nunca sobrescrevem uma foto real) e só ocupam a imagem principal.

---

## Tecnologias

| Camada | Tecnologia |
|---|---|
| Linguagem | PHP 8.3 (PHP-FPM, imagem Debian) |
| Framework | [Slim 4](https://www.slimframework.com/) + slim/psr7 |
| Banco de dados | Oracle **XE 21c** em desenvolvimento (atual) → **19c** em produção (AWS, exigido) |
| Driver do banco | OCI8 + Oracle Instant Client |
| QR Code | [endroid/qr-code](https://github.com/endroid/qr-code) |
| PDF da etiqueta | [dompdf](https://github.com/dompdf/dompdf) |
| Configuração | vlucas/phpdotenv (`.env`) |
| Servidor web | nginx 1.27 |
| Front-end | HTML (templates PHP), Tailwind (arquivo local) e JavaScript puro |
| Leitura de QR | [qr-scanner](https://github.com/nimiq/qr-scanner) (arquivo local) |
| Infraestrutura | Docker + Docker Compose |

O SQL usa apenas recursos que existem no Oracle 19c, para funcionar igual em produção.

---

## Estrutura do projeto

```
projeto-erp/
├── app/
│   ├── Controllers/
│   │   ├── MaterialController.php   # tela /materiais e API de materiais/grupos
│   │   ├── EtiquetaController.php   # gera a etiqueta (PDF ou HTML)
│   │   ├── ImagemController.php     # mostra, grava e remove a imagem do material
│   │   ├── LeituraController.php    # reservado para a Fase 2 (responde 501)
│   │   └── HealthController.php     # /health: verifica app + banco
│   ├── Repositories/
│   │   ├── MaterialRepository.php   # todo o SQL da ESMATERI (bind variables)
│   │   └── ImagemRepository.php     # SQL da MATERIAL_IMAGEM (BLOBs)
│   ├── Services/
│   │   ├── QrCodeService.php        # gera o QR Code do CD_MATERIAL
│   │   ├── EtiquetaService.php      # monta o PDF da etiqueta
│   │   └── ImagemService.php        # reduz e converte a imagem para WebP
│   └── Support/                     # conexão Oracle, JSON, templates, helpers
├── config/
│   ├── app.php                      # nome, ambiente, debug
│   └── database.php                 # dados de conexão (vindos do ambiente)
├── docker/
│   ├── nginx/default.conf           # configuração do nginx
│   ├── php/Dockerfile               # PHP 8.3 + Instant Client + OCI8 + gd + composer
│   ├── php/php.ini
│   └── oracle/
│       ├── init/01-setup.sh         # 1º boot: cria usuários, tabela e dados
│       └── sql/
│           ├── 02-ddl-esmateri.sql  # estrutura da tabela ESMATERI
│           ├── 02-ddl-grupo-subgrupo.sql # nomes dos grupos/subgrupos
│           ├── 03-mocks.sql         # 22 materiais fictícios para teste
│           ├── 04-material-imagem.sql # tabela das imagens (do módulo)
│           └── migracoes/           # ajustes para bancos já existentes
├── public/
│   ├── index.php                    # ponto de entrada e rotas
│   └── assets/                      # CSS e JS da tela (scanner.js = leitor de QR)
├── templates/
│   ├── materiais.php                # tela de consulta
│   ├── etiqueta.php                 # layout da etiqueta
│   └── partials/
│       ├── modal-scanner.php        # janela do leitor de QR (câmera ou código)
│       └── modal-imagem.php         # imagem ampliada
├── scripts/
│   ├── start.sh                     # sobe tudo
│   ├── stop.sh                      # para os containers
│   ├── reset.sh                     # apaga o banco local e recomeça
│   ├── gerar-certificado.sh         # certificado HTTPS local (câmera)
│   ├── csv-para-sql.py              # converte o CSV do cliente em INSERTs
│   ├── imagem-material.php          # inclui/troca/remove a imagem de um material
│   └── gerar-imagens-ficticias.php  # ilustrações para os materiais sem imagem
├── docker-compose.yml
├── .env.docker.example              # modelo do .env
└── composer.json
```

### Como as peças se conectam

```
Navegador ──► nginx (8190 http / 8191 https) ──► PHP-FPM (Slim) ──► Oracle (ESMATERI + GRUPO_SUBGRUPO + MATERIAL_IMAGEM)
                                                    │
                                                    ├─ QrCodeService   → imagem do QR
                                                    ├─ EtiquetaService → PDF da etiqueta
                                                      └─ ImagemController → imagem do material (MATERIAL_IMAGEM)
```

O Compose sobe três containers na mesma rede:

- `erp_oracle`: o banco.
- `erp_php`: a aplicação.
- `erp_nginx`: o servidor web.

### Rotas

| Método | Rota | O que faz |
|---|---|---|
| GET | `/materiais` | Tela de consulta (`/` redireciona para cá) |
| GET | `/api/materiais` | Lista materiais com filtros e paginação |
| GET | `/api/materiais/{cd}` | Detalhe de um material |
| GET | `/api/materiais/{cd}/imagem[/{pos}]` | Imagem 1 (principal) ou 2; sem `/{pos}` vale a 1 (`?tam=mini\|grande`, `&v={hash}` para cache longo) |
| POST | `/api/materiais/{cd}/imagem[/{pos}]` | Inclui ou troca a imagem (multipart, campo `imagem`, até 10 MB). Responde com o material |
| DELETE | `/api/materiais/{cd}/imagem[/{pos}]` | Remove a imagem (remover a 1 promove a 2). Responde com o material |
| POST | `/api/materiais/{cd}/imagem/inverter` | Troca a 1 com a 2 (`409` se não houver as duas) |
| GET | `/api/grupos` | Grupos que têm material: `CD_GRUPO` e `DESCRICAO` |
| GET | `/api/grupos/{grupo}/subgrupos` | Subgrupos do grupo que têm material: `CD_SUB_GRUPO` e `DESCRICAO` |
| GET | `/etiquetas/{cd}` | Etiqueta em PDF (`?formato=html` para pré-visualizar) |
| POST | `/api/leituras` | Fase 2: por enquanto responde `501` |
| GET | `/health` | Verifica se a aplicação e o banco estão de pé |

---

## Como rodar

### Pré-requisitos

- **Docker** e **Docker Compose v2** (comando `docker compose`).
- Cerca de **10 GB livres** em disco: a imagem do Oracle XE tem ~6 GB e os dados ~3 GB.
- Cerca de **3 GB de RAM** livres para o Oracle.
- Não é preciso ter PHP nem Composer instalados na máquina. Tudo roda dentro dos containers.
- **Python 3**, só para converter os CSVs do cliente (passo 3).
- Portas livres no host: `8190` (site), `8191` (site com HTTPS) e `1521` (Oracle). Dá para trocar no `.env`.

### 1. Clonar o repositório

```bash
git clone git@github.com:sofii4/erp-etiquetas.git projeto-erp
cd projeto-erp
```

### 2. Criar o `.env`

```bash
cp .env.docker.example .env
chmod 600 .env
```

Edite o `.env` e troque pelo menos as senhas:

- `ORACLE_PASSWORD`: senha do usuário SYSTEM do Oracle.
- `DB_PASS`: senha do usuário da aplicação (`ETIQUETAS_APP`).

As outras variáveis já vêm prontas para o ambiente local. Para mudar o tamanho da etiqueta, ajuste `ETIQUETA_LARGURA_MM` e `ETIQUETA_ALTURA_MM`. Para mudar a porta do site, ajuste `WEB_HOST_PORT`.

### 3. Carregar os materiais reais do cliente

Sem este passo, o banco é carregado com 22 materiais fictícios, sem nomes de grupos. Para usar o export real, coloque os dois CSVs em `var/` (pasta fora do git) e rode:

```bash
python3 scripts/csv-para-sql.py var/dataset_sofia.csv var/grupo_subgrupo_sofia.csv
```

- 1º arquivo: export da `ESMATERI` (cabeçalho com as 106 colunas, na ordem do DDL).
- 2º arquivo (opcional): grupos e subgrupos, com as colunas `CD_GRUPO, DESC_GRUPO, CD_SUB_GRUPO, DESC_SUBGRUPO`.

Saída esperada com os arquivos atuais: `5000 materiais`, `251 subgrupos` e alguns avisos (veja [Ajustes feitos nos dados](#ajustes-feitos-nos-dados-do-cliente)).

Os CSVs podem vir em Latin-1 (padrão do export) ou UTF-8. Linhas com colunas a mais ou a menos que o cabeçalho entram só com os campos básicos (grupo, subgrupo, código, descrição, unidade), e descrições acima de 60 bytes são cortadas; o script avisa cada caso. Sem o CSV de grupos, a tela mostra só os códigos.

O script gera `docker/oracle/sql/03-dados-cliente.sql`. Quando esse arquivo existe, ele é usado no lugar dos dados fictícios. Ele **fica fora do git** porque contém dados reais do cliente.

> Os dados só são carregados no **primeiro** boot do banco. Se o banco já foi criado, rode `scripts/reset.sh` antes do próximo passo.

### 4. Subir os containers

```bash
./scripts/start.sh
```

Na primeira vez o script gera o certificado HTTPS local (`docker/nginx/certs`, fora do git). Depois monta a imagem do PHP, sobe os três containers e espera todos ficarem prontos. No primeiro boot o Oracle leva **cerca de 1 minuto** para criar o banco, os usuários e as tabelas e carregar os 5.000 materiais. Para conferir se a carga terminou: `docker compose logs oracle | grep ERP:` deve terminar em `ERP: setup concluído.`

### 5. Instalar as dependências PHP (só na primeira vez)

```bash
docker compose exec -u $(id -u):$(id -g) -e COMPOSER_HOME=/tmp/composer php composer install
```

O `-u` faz a pasta `vendor/` ficar com o seu usuário, e não com o root.

### 6. (Opcional) Gerar imagens ilustrativas

```bash
docker compose exec php php scripts/gerar-imagens-ficticias.php
```

Com os 5.000 materiais leva **cerca de 11 minutos** (a tela já funciona durante a geração; quem ainda não tem imagem mostra "+ Foto"). O script só preenche os materiais **sem** imagem. Com `--refazer`, ele regera as que já são fictícias. Imagens reais (`FICTICIA = 'N'`, gravadas pelo script de manutenção ou por SQL) nunca são sobrescritas. Com `--previa=var/previa`, ele só grava os PNGs na pasta, sem mexer no banco.

### 7. Acessar

- Sistema: <http://localhost:8190>
- Com HTTPS (necessário para a câmera fora do `localhost`): <https://localhost:8191>
- Teste rápido: <http://localhost:8190/health>

Se o Docker roda numa VM, troque `localhost` pelo IP da VM (`hostname -I` mostra o IP). Pelo celular, use o endereço HTTPS e aceite o aviso de certificado na primeira vez. Se o IP da VM mudar, rode `./scripts/gerar-certificado.sh` e `docker compose restart nginx`.

### Problemas comuns

| Sintoma | O que fazer |
|---|---|
| A tela abre, mas a busca dá erro de banco | O Oracle ainda está subindo. Espere `docker compose ps` mostrar `erp_oracle` como `healthy` e confira <http://localhost:8190/health> |
| Continua com 22 materiais (fictícios) depois de gerar o SQL | Os dados só entram no primeiro boot do banco: rode `./scripts/reset.sh` (responda `yes`) e `./scripts/start.sh` |
| "port is already allocated" ao subir | Outra aplicação usa a porta. Troque `WEB_HOST_PORT`, `WEB_HTTPS_PORT` ou `ORACLE_HOST_PORT` no `.env` |
| Câmera não abre no celular | Precisa ser pelo `https://<ip-da-vm>:8191`. Em `http://` o navegador bloqueia a câmera |
| Aviso de certificado no navegador | Normal em desenvolvimento (certificado autoassinado). Aceite uma vez |

### Comandos do dia a dia

| Comando | O que faz |
|---|---|
| `./scripts/start.sh` | Sobe tudo |
| `./scripts/stop.sh` | Para os containers (os dados do banco são mantidos) |
| `./scripts/reset.sh` | **Apaga o banco local**. No próximo start ele é recriado do zero |
| `docker compose logs -f` | Mostra os logs em tempo real |
| `docker compose logs -f php` | Logs só da aplicação |

### Acessar o banco por fora (DBeaver, SQL Developer)

- Host: `localhost` (ou o IP da VM)
- Porta: `1521` (variável `ORACLE_HOST_PORT`)
- Service name: `XEPDB1`
- Usuário: `ETIQUETAS_APP` com a senha de `DB_PASS`. Na `ESMATERI` e na `GRUPO_SUBGRUPO` (schema `"db_tst"`) ele só consegue ler. A `MATERIAL_IMAGEM` pertence a ele, então é por esse usuário que se alteram as imagens via SQL.

---

## Manutenção das imagens

O jeito normal é pela tela (câmera ou arquivo). Também dá para incluir, trocar ou remover direto na tabela `MATERIAL_IMAGEM` (schema do usuário da aplicação). Há dois caminhos.

### Pelo script (recomendado: otimiza a imagem)

Coloque o arquivo dentro do projeto (por exemplo, em `var/`) e rode:

```bash
# incluir ou trocar a principal
docker compose exec php php scripts/imagem-material.php importar 2000100039 var/foto.jpg

# incluir ou trocar a segunda imagem (posição 2)
docker compose exec php php scripts/imagem-material.php importar 2000100039 var/foto2.jpg 2

# remover (sem posição = principal; a 2, se houver, vira a principal)
docker compose exec php php scripts/imagem-material.php remover 2000100039
docker compose exec php php scripts/imagem-material.php remover 2000100039 2
```

O script confere se o material existe na `ESMATERI` e se o arquivo é mesmo uma imagem. Depois converte para WebP, gera a miniatura e grava.

### Direto por SQL (DBeaver, SQL Developer)

Basta preencher `CD_MATERIAL` e `IMAGEM`, carregando o arquivo JPG, PNG, WebP ou GIF no campo BLOB. `POSICAO` vale 1 (principal) se ficar vazia; use 2 para a segunda imagem, só se o material já tiver a 1. O resto é automático:

- `MINIATURA` é opcional. Sem ela, a tela usa a própria `IMAGEM` reduzida pelo navegador. Funciona, mas a página fica mais pesada se a imagem for grande.
- `HASH`, `BYTES_IMAGEM`, `BYTES_MINIATURA` e `DT_ATUALIZACAO` são preenchidos pelo trigger.
- O tipo do arquivo é detectado pelo conteúdo, então a coluna `MIME` pode ficar vazia.

```sql
-- remover as duas
DELETE FROM MATERIAL_IMAGEM WHERE CD_MATERIAL = '2000100039';
-- remover só a segunda
DELETE FROM MATERIAL_IMAGEM WHERE CD_MATERIAL = '2000100039' AND POSICAO = 2;
COMMIT;
```

Por esse caminho a imagem vai **sem otimização**. Prefira arquivos já pequenos (até ~1000 px), ou use o script.

---

## Ajustes feitos nos dados do cliente

O `csv-para-sql.py` avisa cada caso abaixo ao rodar. Com o export atual:

- **Codificação:** os CSVs vêm em Latin-1 (ISO-8859-1). O script aceita Latin-1 ou UTF-8 e grava UTF-8 (o XE é sempre AL32UTF8).
- **8 materiais com colunas deslocadas** (108 colunas em vez de 106: cadastros de jan/2026 com códigos curtos, como `002820` e `002970`). Entram só com grupo, subgrupo, código, descrição e unidade; o resto fica com o valor "vazio" do ERP.
- **2 descrições cortadas em 60 bytes** (`0106300104` e `3001900026`): com acento, passam do `VARCHAR2(60 BYTE)` em UTF-8. No ERP cabem porque lá cada letra acentuada ocupa 1 byte. Isso indica que o banco do cliente não é UTF-8 (a confirmar).
- **Grupos 25 e 35 (EDGE)** não têm subgrupos na tabela de grupos: os materiais deles mostram o nome do grupo e só o código do subgrupo.
- **Linha em branco** no início do CSV de grupos é ignorada.

Outras características dos dados que aparecem na tela:

- `LOCALIZACAO` está vazia em todos os materiais: a etiqueta e a lista mostram "—".
- 11 materiais não têm descrição: aparecem como "—".
- As descrições usam abreviações ("PARAF", "MONT."): a busca por "parafuso" não acha nada. Para esses casos, use o filtro de grupo/subgrupo.
- O filtro só lista grupos e subgrupos com algum material (por exemplo, o grupo 50 Imobilizados não aparece).

---

## Migração para o Oracle 19c (antes da produção)

**Exigência:** a produção roda no Oracle **19c** (AWS). O XE 21c usado aqui é só para desenvolvimento. Antes de entregar, é preciso subir este mesmo projeto num 19c e repetir os testes.

O que já está pronto para isso:

- Todo o SQL usa só recursos do 19c (nada de `BOOLEAN` em SQL, `IF EXISTS`, `SELECT` sem `FROM DUAL`...). A busca sem acento usa `TRANSLATE`, que existe em todas as versões.
- Os scripts de `docker/oracle/sql/` não têm nome de schema nem de PDB, então rodam iguais no 19c.

Passos para validar localmente com a imagem oficial da Oracle:

1. Criar conta em <https://container-registry.oracle.com>, aceitar a licença de **Database → enterprise** e fazer `docker login container-registry.oracle.com`.
2. No `docker-compose.yml`, no serviço `oracle`:
   - `image: container-registry.oracle.com/database/enterprise:19.3.0.0`;
   - senha em `ORACLE_PWD` (no lugar de `ORACLE_PASSWORD`);
   - scripts de init montados em `/opt/oracle/scripts/setup` (no lugar de `/container-entrypoint-initdb.d`);
   - dados em `/opt/oracle/oradata` (igual) e o `healthcheck` trocado pelo da imagem oficial.
3. No `.env`: `DB_SERVICE=ORCLPDB1`.
4. `./scripts/reset.sh` e `./scripts/start.sh`. O primeiro boot do 19c é bem mais lento (pode passar de 20 minutos) e a imagem tem uns 8 GB.
5. Repetir o teste: filtros, busca com acento, etiqueta em PDF, leitor de QR e envio de foto.

Itens a confirmar com o cliente antes da produção:

- Charset do banco (`NLS_CHARACTERSET`). Pelas descrições, parece não ser UTF-8; se for `WE8MSWIN1252`, criar o 19c local com `ORACLE_CHARACTERSET=WE8MSWIN1252`.
- Nome real do schema (`DB_SCHEMA`) e da tabela de grupos/subgrupos no ERP. Se ela tiver outro nome ou outras colunas, ajustar `joinGrupo()`, `listarGrupos()` e `listarSubGrupos()` no `MaterialRepository`.
- Usuário da aplicação com `SELECT` na `ESMATERI` e na tabela de grupos, e cota num tablespace para a `MATERIAL_IMAGEM`.

---

## Observações

- **Schema:** a tabela pertence ao schema `"db_tst"`, escrito em minúsculas e por isso sempre entre aspas no SQL. Na AWS o nome real pode ser outro: basta mudar `DB_SCHEMA` no `.env`.
- **Banco já existente (criado antes das imagens):** os scripts de init só rodam no primeiro boot. Para criar a tabela de imagens sem apagar o banco, rode:
  ```bash
  docker compose exec -T oracle bash -c 'sqlplus -s / as sysdba <<EOF
  ALTER SESSION SET CONTAINER = ${DB_SERVICE};
  ALTER USER ${DB_USER} QUOTA UNLIMITED ON USERS;
  ALTER SESSION SET CURRENT_SCHEMA = ${DB_USER};
  @/opt/erp-sql/04-material-imagem.sql
  EOF'
  ```
  Depois de mudar o `Dockerfile` ou o `php.ini`, rode `docker compose build php` (o suporte a WebP e EXIF depende disso).
- **Banco criado antes das duas imagens:** a tabela já existe sem `POSICAO`. Rode a migração (as imagens atuais viram a principal):
  ```bash
  docker compose exec -T oracle bash -c 'sqlplus -s / as sysdba <<EOF
  ALTER SESSION SET CONTAINER = ${DB_SERVICE};
  ALTER SESSION SET CURRENT_SCHEMA = ${DB_USER};
  @/opt/erp-sql/migracoes/2026-11-24-duas-imagens.sql
  EOF'
  ```
- **Produção (AWS):** o usuário da aplicação precisa de `SELECT` na `ESMATERI` e na tabela de grupos/subgrupos. Se no ERP ela tiver outro nome ou outras colunas, basta ajustar o `joinGrupo()`, o `listarGrupos()` e o `listarSubGrupos()` do `MaterialRepository`. Ele também precisa de cota num tablespace e da tabela `MATERIAL_IMAGEM` criada no schema dele (`04-material-imagem.sql`; se ela já tiver sido criada antes das duas imagens, rode também a migração acima). A `ESMATERI` continua só com `SELECT`. A única gravação da aplicação é na `MATERIAL_IMAGEM` (upload de fotos pela tela).
- **HTTPS em produção:** o certificado autoassinado é só para desenvolvimento. Na AWS o HTTPS deve vir de um certificado válido (no balanceador ou no nginx).
- **Configuração:** dentro do Docker, o PHP recebe as configurações pelo ambiente, definidas no `docker-compose.yml`, e não lendo o `.env`. A senha do SYSTEM (`ORACLE_PASSWORD`) não é repassada à aplicação.
