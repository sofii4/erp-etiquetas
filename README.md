# Etiquetas ERP

Módulo que complementa o ERP: consulta os materiais da tabela `ESMATERI` (Oracle) e gera etiquetas para imprimir, com um QR Code que guarda o código do material (`CD_MATERIAL`).

**O que já funciona (Fase 1)**

- Tela de consulta de materiais, com filtros por código, descrição e grupo/subgrupo, e com paginação.
- **Imagem de cada material**, guardada no banco: miniatura na tabela e ampliação ao clicar. Pela tela dá para **tirar a foto com a câmera** ou **enviar um arquivo** (com prévia antes de salvar) e para remover a imagem. Material sem imagem mostra "+ Foto". Por enquanto não há controle de permissão: qualquer usuário pode alterar. Também dá para manter as imagens direto no banco (veja [Manutenção das imagens](#manutenção-das-imagens)).
- Etiqueta em PDF (padrão 100 × 50 mm, configurável) com os dados do material e o QR Code.
- Botão **Escanear QR Code**: lê o QR da etiqueta pela câmera (celular ou notebook) ou pelo campo de código e mostra o material, com atalhos para imprimir a etiqueta e filtrar a lista. A entrada e a saída de estoque (Fase 2) ainda não estão disponíveis.

### Como funciona o leitor de QR Code

- O QR guarda só o `CD_MATERIAL`. Depois da leitura, a tela busca o material em `GET /api/materiais/{cd}`.
- A leitura pela câmera usa a biblioteca [qr-scanner](https://github.com/nimiq/qr-scanner) (MIT), salva em `public/assets/js/vendor/qr-scanner` (não depende de CDN). Ela usa o leitor nativo do navegador quando existe e, se não existir, um worker próprio.
- **O navegador só libera a câmera em HTTPS ou em `localhost`.** Por isso o nginx também atende em HTTPS (porta `8191`), com um certificado autoassinado gerado por `scripts/gerar-certificado.sh`. Pelo celular, acesse `https://<ip-da-vm>:8191` e aceite o aviso de certificado uma vez. Em `http://`, a tela avisa e oferece o campo de código.
- O campo de código também atende **coletores com leitor embutido**, que "digitam" o código lido e mandam Enter.
- A câmera é desligada sempre que a janela fecha.

A `ESMATERI` do ERP é acessada **só para leitura**: o usuário da aplicação tem apenas `SELECT` nela. As imagens ficam numa tabela própria do módulo (`MATERIAL_IMAGEM`), no schema do usuário da aplicação.

### Como as imagens são guardadas

- Ficam **no Oracle, em BLOB**, nunca em arquivo no servidor.
- Câmera: no celular abre o app de câmera do aparelho; no computador abre o vídeo ao vivo no próprio modal (só em HTTPS ou `localhost`). Fotos grandes são reduzidas no navegador (até 2048 px) antes do envio.
- Pela tela ou pelo script de manutenção, qualquer arquivo (JPG, PNG, WebP ou GIF, até 10 MB) vira **WebP** em duas versões:
  - **imagem**: até 1024 px, ~5–100 KB;
  - **miniatura**: até 160 px, ~1 KB.

  A rotação das fotos de celular (EXIF) é corrigida e os metadados (GPS, câmera) são descartados. No teste, uma foto de 1,2 MB virou 95 KB no banco.
- A miniatura fica guardada *dentro* da linha da tabela e a imagem grande fica fora dela (SECUREFILE). A listagem só lê o `HASH` (LEFT JOIN), nunca o BLOB.
- As imagens são servidas com `ETag` e `Cache-Control: immutable`, usando o `HASH` na URL. O trigger `TRG_MATERIAL_IMAGEM_VERSAO` gera um `HASH` novo a cada inclusão ou alteração, inclusive as feitas à mão por SQL. Assim o navegador nunca fica preso numa imagem antiga.
- O export do cliente não tem fotos. Por isso `scripts/gerar-imagens-ficticias.php` desenha **ilustrações** por tipo de material (parafuso, porca, tubo, perfil, chapa, motorredutor, elevador...). Elas são marcadas com o selo "Imagem ilustrativa" e com `FICTICIA = 'S'`.

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
│           ├── 03-mocks.sql         # 22 materiais fictícios para teste
│           └── 04-material-imagem.sql # tabela das imagens (do módulo)
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
Navegador ──► nginx (8190 http / 8191 https) ──► PHP-FPM (Slim) ──► Oracle (ESMATERI + MATERIAL_IMAGEM)
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
| GET | `/api/materiais/{cd}/imagem` | Imagem do material (`?tam=mini\|grande`, `&v={hash}` para cache longo) |
| POST | `/api/materiais/{cd}/imagem` | Inclui ou troca a imagem (multipart, campo `imagem`, até 10 MB) |
| DELETE | `/api/materiais/{cd}/imagem` | Remove a imagem |
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

Na primeira vez o script gera o certificado HTTPS local (`docker/nginx/certs`, fora do git). Depois monta a imagem do PHP, sobe os três containers e espera todos ficarem prontos. No primeiro boot o Oracle leva **cerca de 1 minuto** para criar o banco, os usuários e a tabela.

### 5. Instalar as dependências PHP (só na primeira vez)

```bash
docker compose exec -u $(id -u):$(id -g) -e COMPOSER_HOME=/tmp/composer php composer install
```

O `-u` faz a pasta `vendor/` ficar com o seu usuário, e não com o root.

### 6. (Opcional) Gerar imagens ilustrativas

```bash
docker compose exec php php scripts/gerar-imagens-ficticias.php
```

O script só preenche os materiais **sem** imagem. Com `--refazer`, ele regera as que já são fictícias. Imagens reais (`FICTICIA = 'N'`, gravadas pelo script de manutenção ou por SQL) nunca são sobrescritas. Com `--previa=var/previa`, ele só grava os PNGs na pasta, sem mexer no banco.

### 7. Acessar

- Sistema: <http://localhost:8190>
- Com HTTPS (necessário para a câmera fora do `localhost`): <https://localhost:8191>
- Teste rápido: <http://localhost:8190/health>

Se o Docker roda numa VM, troque `localhost` pelo IP da VM. Se o IP da VM mudar, rode `./scripts/gerar-certificado.sh` e `docker compose restart nginx`.

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
- Usuário: `ETIQUETAS_APP` com a senha de `DB_PASS`. Na `ESMATERI` ele só consegue ler. A `MATERIAL_IMAGEM` pertence a ele, então é por esse usuário que se alteram as imagens via SQL.

---

## Manutenção das imagens

O jeito normal é pela tela (câmera ou arquivo). Também dá para incluir, trocar ou remover direto na tabela `MATERIAL_IMAGEM` (schema do usuário da aplicação). Há dois caminhos.

### Pelo script (recomendado: otimiza a imagem)

Coloque o arquivo dentro do projeto (por exemplo, em `var/`) e rode:

```bash
# incluir ou trocar
docker compose exec php php scripts/imagem-material.php importar 2000100039 var/foto.jpg

# remover
docker compose exec php php scripts/imagem-material.php remover 2000100039
```

O script confere se o material existe na `ESMATERI` e se o arquivo é mesmo uma imagem. Depois converte para WebP, gera a miniatura e grava.

### Direto por SQL (DBeaver, SQL Developer)

Basta preencher `CD_MATERIAL` e `IMAGEM`, carregando o arquivo JPG, PNG, WebP ou GIF no campo BLOB. O resto é automático:

- `MINIATURA` é opcional. Sem ela, a tela usa a própria `IMAGEM` reduzida pelo navegador. Funciona, mas a página fica mais pesada se a imagem for grande.
- `HASH`, `BYTES_IMAGEM`, `BYTES_MINIATURA` e `DT_ATUALIZACAO` são preenchidos pelo trigger.
- O tipo do arquivo é detectado pelo conteúdo, então a coluna `MIME` pode ficar vazia.

```sql
-- remover
DELETE FROM MATERIAL_IMAGEM WHERE CD_MATERIAL = '2000100039';
COMMIT;
```

Por esse caminho a imagem vai **sem otimização**. Prefira arquivos já pequenos (até ~1000 px), ou use o script.

---

## Observações

- **Schema:** a tabela pertence ao schema `"db_tst"`, escrito em minúsculas e por isso sempre entre aspas no SQL. Na AWS o nome real pode ser outro: basta mudar `DB_SCHEMA` no `.env`.
- **Oracle 19c:** para validar na versão de produção, troque a imagem do serviço `oracle` no `docker-compose.yml` e o `DB_SERVICE` no `.env` (por exemplo, `ORCLPDB1`).
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
- **Produção (AWS):** o usuário da aplicação precisa de cota num tablespace e da tabela `MATERIAL_IMAGEM` criada no schema dele (`04-material-imagem.sql`). A `ESMATERI` continua só com `SELECT`. A aplicação web não grava nada: quem altera imagens é o script de manutenção ou o SQL.
- **HTTPS em produção:** o certificado autoassinado é só para desenvolvimento. Na AWS o HTTPS deve vir de um certificado válido (no balanceador ou no nginx).
- **Configuração:** dentro do Docker, o PHP recebe as configurações pelo ambiente, definidas no `docker-compose.yml`, e não lendo o `.env`. A senha do SYSTEM (`ORACLE_PASSWORD`) não é repassada à aplicação.
