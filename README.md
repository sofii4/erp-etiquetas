# Etiquetas ERP

Módulo que complementa o ERP: consulta os materiais da tabela `ESMATERI` (Oracle) e gera etiquetas para imprimir, com um QR Code que guarda o código do material (`CD_MATERIAL`).

**O que já funciona (Fase 1)**

- Tela de consulta de materiais, com filtros por código, descrição e grupo/subgrupo, e com paginação.
- Etiqueta em PDF (padrão 100 × 50 mm, configurável) com os dados do material e o QR Code.
- Botão **Escanear QR Code**, ainda só visual (mockup). Ele reserva o lugar da Fase 2, a movimentação de estoque pela leitura do QR Code.

A aplicação acessa o banco **só para leitura**: o usuário dela tem apenas `SELECT` na `ESMATERI`.

---

## Tecnologias

| Camada | Tecnologia |
|---|---|
| Linguagem | PHP 8.3 (PHP-FPM, imagem Debian) |
| Framework | [Slim 4](https://www.slimframework.com/) + slim/psr7 |
| Banco de dados | Oracle: **XE 21c** em desenvolvimento, **19c** em produção (AWS) |
| Driver do banco | OCI8 + Oracle Instant Client |
| QR Code | [endroid/qr-code](https://github.com/endroid/qr-code) |
| PDF da etiqueta | [dompdf](https://github.com/dompdf/dompdf) |
| Configuração | vlucas/phpdotenv (`.env`) |
| Servidor web | nginx 1.27 |
| Front-end | HTML (templates PHP), Tailwind (arquivo local) e JavaScript puro |
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
│   │   ├── LeituraController.php    # reservado para a Fase 2 (responde 501)
│   │   └── HealthController.php     # /health: verifica app + banco
│   ├── Repositories/
│   │   └── MaterialRepository.php   # todo o SQL da ESMATERI (bind variables)
│   ├── Services/
│   │   ├── QrCodeService.php        # gera o QR Code do CD_MATERIAL
│   │   └── EtiquetaService.php      # monta o PDF da etiqueta
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
│           └── 03-mocks.sql         # 22 materiais fictícios para teste
├── public/
│   ├── index.php                    # ponto de entrada e rotas
│   └── assets/                      # CSS e JS da tela
├── templates/
│   ├── materiais.php                # tela de consulta
│   ├── etiqueta.php                 # layout da etiqueta
│   └── partials/modal-scanner.php   # janela (mockup) do leitor de QR
├── scripts/
│   ├── start.sh                     # sobe tudo
│   ├── stop.sh                      # para os containers
│   ├── reset.sh                     # apaga o banco local e recomeça
│   └── csv-para-sql.py              # converte o CSV do cliente em INSERTs
├── docker-compose.yml
├── .env.docker.example              # modelo do .env
└── composer.json
```

### Como as peças se conectam

```
Navegador ──► nginx (porta 8190) ──► PHP-FPM (Slim) ──► Oracle (ESMATERI)
                                         │
                                         ├─ QrCodeService  → imagem do QR
                                         └─ EtiquetaService → PDF da etiqueta
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
| GET | `/api/grupos` | Lista de grupos |
| GET | `/api/grupos/{grupo}/subgrupos` | Subgrupos de um grupo |
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

### 3. (Opcional) Carregar os materiais reais do cliente

Sem este passo, o banco é carregado com 22 materiais fictícios. Para usar o export real da `ESMATERI` (CSV):

```bash
python3 scripts/csv-para-sql.py var/arquivo.csv
```

O script gera `docker/oracle/sql/03-dados-cliente.sql`. Quando esse arquivo existe, ele é usado no lugar dos dados fictícios. Ele **fica fora do git** porque contém dados reais do cliente.

> Os dados só são carregados no **primeiro** boot do banco. Se o banco já foi criado, rode `scripts/reset.sh` antes do próximo passo.

### 4. Subir os containers

```bash
./scripts/start.sh
```

O script monta a imagem do PHP, sobe os três containers e espera todos ficarem prontos. No primeiro boot o Oracle leva **cerca de 1 minuto** para criar o banco, os usuários e a tabela.

### 5. Instalar as dependências PHP (só na primeira vez)

```bash
docker compose exec -u $(id -u):$(id -g) -e COMPOSER_HOME=/tmp/composer php composer install
```

O `-u` faz a pasta `vendor/` ficar com o seu usuário, e não com o root.

### 6. Acessar

- Sistema: <http://localhost:8190>
- Teste rápido: <http://localhost:8190/health>

Se o Docker roda numa VM, troque `localhost` pelo IP da VM.

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
- Usuário: `ETIQUETAS_APP` com a senha de `DB_PASS`. Ele só consegue ler os dados.

---

## Observações

- **Schema:** a tabela pertence ao schema `"db_tst"`, escrito em minúsculas e por isso sempre entre aspas no SQL. Na AWS o nome real pode ser outro: basta mudar `DB_SCHEMA` no `.env`.
- **Oracle 19c:** para validar na versão de produção, troque a imagem do serviço `oracle` no `docker-compose.yml` e o `DB_SERVICE` no `.env` (por exemplo, `ORCLPDB1`).
- **Configuração:** dentro do Docker, o PHP recebe as configurações pelo ambiente, definidas no `docker-compose.yml`, e não lendo o `.env`. A senha do SYSTEM (`ORACLE_PASSWORD`) não é repassada à aplicação.
