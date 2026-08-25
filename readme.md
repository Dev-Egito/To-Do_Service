# 📋 MicroSaaS To-Do — Serviço Kanban (Laravel)

> **Microsserviço responsável pelo gerenciamento de Quadros (Boards) e Cartões (Cards) no estilo Kanban**, integrante de um ecossistema de microsserviços orientado a eventos.

[![PHP](https://img.shields.io/badge/PHP-8.3-blue?logo=php)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-red?logo=laravel)](https://laravel.com/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-orange?logo=mysql)](https://www.mysql.com/)
[![RabbitMQ](https://img.shields.io/badge/RabbitMQ-3-orange?logo=rabbitmq)](https://www.rabbitmq.com/)
[![Redis](https://img.shields.io/badge/Redis-7-red?logo=redis)](https://redis.io/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue?logo=docker)](https://www.docker.com/)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)

---

## 📖 Sumário

1. [Introdução e Responsabilidades](#1-introdução-e-responsabilidades)
2. [Arquitetura e Integração](#2-arquitetura-e-integração)
3. [Tecnologias Utilizadas](#3-tecnologias-utilizadas)
4. [Estrutura do Projeto](#4-estrutura-do-projeto)
5. [Modelo de Dados](#5-modelo-de-dados)
6. [Endpoints da API REST](#6-endpoints-da-api-rest)
7. [Padrões de Projeto e Decisões de Arquitetura](#7-padrões-de-projeto-e-decisões-de-arquitetura)
8. [Pré-requisitos](#8-pré-requisitos)
9. [Instalação e Execução](#9-instalação-e-execução)
10. [Variáveis de Ambiente](#10-variáveis-de-ambiente)
11. [Observabilidade](#11-observabilidade)
12. [Testes](#12-testes)
13. [Contribuindo](#13-contribuindo)

---

## 1. Introdução e Responsabilidades

Este repositório contém o **Serviço Kanban**, um microsserviço construído com **PHP 8.3 + Laravel 12** que integra o ecossistema **MicroSaaS To-Do**.

Sua responsabilidade dentro do ecossistema é:

- Expor o **Read Model do Kanban** via API REST **somente leitura** (`GET` de Boards e Cards).
- Atuar como **consumidor** dos eventos publicados pelo Booking Service (Node.js) via **RabbitMQ**.
- Projetar reuniões da fila `reuniao_criada` em `reunioes_read` e em cards do quadro `Reuniões`.
- Projetar mutações de Kanban da fila `kanban_events` na tabela `cards` (`source_id` = ID do Write Model).
- Expor endpoints de **health check** e métricas **Prometheus**.

---

## 2. Arquitetura e Integração

O ecossistema é composto por três serviços que se comunicam de forma assíncrona, garantindo desacoplamento, alta disponibilidade e escalabilidade independente.

```
┌─────────────────────────────────────────────────────────────────┐
│                        Ecossistema MicroSaaS                    │
│                                                                 │
│  ┌──────────────┐   REST/JWT   ┌────────────────────────────┐   │
│  │              │ ──────────▶  │  Serviço de Reuniões       │   │
│  │   Frontend   │              │  (Node.js + PostgreSQL)    │   │
│  │   (Flutter)  │   GET only   │                            │   │
│  │              │ ──────────▶  │  Publica: reuniao_criada   │   │
│  └──────────────┘              │  Publica: kanban_events    │   │
│                                └───────────┬────────────────┘   │
│                                            │ Publica evento      │
│                                            ▼                    │
│                                ┌─────────────────────┐         │
│                                │      RabbitMQ        │         │
│                                │  (Mensageria AMQP)   │         │
│                                └───────────┬──────────┘         │
│                                            │ Consome evento      │
│                                            ▼                    │
│  ┌──────────────┐   REST/JWT   ┌────────────────────────────┐   │
│  │   Frontend   │ ──────────▶  │  Serviço Kanban (este)     │   │
│  │   (Flutter)  │              │  (Laravel 12 + MySQL 8.0)  │   │
│  └──────────────┘              │                            │   │
│                                │  ▪ Consumer READ-ONLY      │   │
│                                │  ▪ Filas: reuniao_criada,  │   │
│                                │    kanban_events           │   │
│                                │  ▪ Read Model (CQRS)       │   │
│                                │  ▪ Cache com Redis 7       │   │
│                                └────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

### Fluxo de Comunicação

**1. Frontend → Serviço Kanban (REST/JWT, somente GET)**
O Flutter consulta este serviço (`GET /api/boards`, `GET /api/cards`). Mutações **não** são aceitas aqui: `POST`/`PUT`/`DELETE` de boards e cards foram removidos. O JWT continua sendo validado pelo `JwtMiddleware`.

**2. Booking Service → RabbitMQ → Serviço Kanban (Event-Driven)**
- Fila `reuniao_criada`: comando `php artisan rabbitmq:consume-reuniao-criada` grava em `reunioes_read` e cria um card (idempotência pelo `id` da reunião).
- Fila `kanban_events`: comando `php artisan rabbitmq:consume-kanban-events` aplica `CardCriadoEvent`, `CardAtualizadoEvent`, `CardMovidoEvent` e `CardDeletadoEvent` na tabela `cards` (idempotência pelo `source_id`).

**3. Kanban READ-ONLY (sem producer de cards)**
O `CardService` não publica mais em `cards_queue`. A escrita do Kanban vive no Node.js; o Laravel apenas projeta o estado.

**4. Rastreabilidade Distribuída**
O middleware `CorrelationIdMiddleware` propaga o cabeçalho `X-Correlation-ID` entre requisições, garantindo rastreabilidade de ponta a ponta no log centralizado do ecossistema.

**5. Resiliência com Circuit Breaker**
O `CircuitBreakerService` (implementado com a biblioteca **Ganesha** + Redis) protege a publicação no RabbitMQ: se a taxa de falhas superar 50% em uma janela de 30 segundos, o circuito abre e o serviço retorna um fallback imediatamente, evitando cascata de falhas.

---

## 3. Tecnologias Utilizadas

| Camada | Tecnologia | Versão | Papel |
|---|---|---|---|
| **Linguagem** | PHP | 8.3 | Runtime da aplicação |
| **Framework** | Laravel | 12.x | Core do microsserviço |
| **ORM** | Eloquent | (built-in) | Mapeamento objeto-relacional |
| **Banco de dados** | MySQL | 8.0 | Persistência principal (produção) |
| **Cache / Circuit Breaker** | Redis | 7 | Cache de responses e estado do Circuit Breaker |
| **Mensageria** | RabbitMQ | 3 (management) | Comunicação assíncrona entre microsserviços |
| **Autenticação** | firebase/php-jwt | ^7.0 | Validação de tokens JWT externos |
| **Sessão/API Auth** | Laravel Sanctum | ^4.0 | Suporte a tokens de API internos |
| **Filas (driver)** | vladimir-yuldashev/laravel-queue-rabbitmq | ^14.5 | Integração das filas Laravel com RabbitMQ |
| **Circuit Breaker** | ackintosh/ganesha | (transitive) | Proteção de chamadas externas |
| **Documentação** | darkaonline/l5-swagger | ^11.0 | Geração automática de Swagger/OpenAPI |
| **Health Check** | spatie/laravel-health | * | Endpoints de liveness/readiness |
| **Métricas** | spatie/laravel-prometheus | * | Exposição de métricas para Prometheus |
| **Servidor Web** | Nginx | Alpine | Proxy reverso para o PHP-FPM |
| **Containerização** | Docker + Docker Compose | - | Orquestração do ambiente |
| **Testes** | PHPUnit | ^11.5 | Testes unitários e de feature |
| **Code Style** | Laravel Pint | ^1.24 | Formatação de código (PSR-12) |

---

## 4. Estrutura do Projeto

```
MicroSaas_To_do/
└── php-service/                        # Raiz da aplicação Laravel
    ├── app/
    │   ├── Console/Commands/
    │   │   ├── ConsumeReuniaoCriada.php     # Consumer da fila reuniao_criada
    │   │   └── ConsumeKanbanEvents.php      # Consumer da fila kanban_events
    │   ├── Http/
    │   │   ├── Controllers/
    │   │   │   ├── BoardController.php      # Leitura de Quadros (index/show)
    │   │   │   └── CardController.php       # Leitura de Cartões (index/show)
    │   │   └── Middleware/
    │   │       ├── JwtMiddleware.php         # Validação de tokens JWT externos
    │   │       └── CorrelationIdMiddleware.php # Rastreabilidade distribuída
    │   ├── Jobs/
    │   │   └── ProcessarEventoReuniao.php   # Consumer: persiste eventos do RabbitMQ
    │   ├── Listeners/
    │   │   └── InvalidateBoardCache.php     # Invalidação de cache ao receber evento
    │   ├── Models/
    │   │   ├── Board.php                    # Modelo Eloquent de Quadro
    │   │   └── Card.php                     # Modelo Eloquent de Cartão
    │   ├── Providers/
    │   │   └── AppServiceProvider.php       # IoC: bind das interfaces, health checks e Prometheus
    │   ├── Repositories/
    │   │   ├── Contracts/                   # Interfaces (contratos)
    │   │   │   ├── BoardRepositoryInterface.php
    │   │   │   └── CardRepositoryInterface.php
    │   │   └── Eloquent/                    # Implementações com Eloquent
    │   │       ├── EloquentBoardRepository.php
    │   │       └── EloquentCardRepository.php
    │   └── Service/
    │       ├── CardService.php              # Consultas do Kanban (sem publish)
    │       ├── CircuitBreakerService.php    # Circuit Breaker (Ganesha + Redis)
    │       └── RabbitMQService.php          # Abstração AMQP (publicação legado/resiliência)
    ├── database/
    │   └── migrations/
    │       ├── ..._create_users_table.php
    │       ├── ..._create_boards_table.php
    │       ├── ..._create_cards_table.php
    │       ├── ..._add_kanban_fields_to_cards_table.php  # column_id, priority, assignee, tags, source_id
    │       ├── ..._create_jobs_table.php
    │       ├── ..._create_cache_table.php
    │       ├── ..._create_personal_access_tokens_table.php
    │       └── ..._create_reunioes_read_tables.php
    ├── routes/
    │   ├── api.php                          # Todas as rotas REST da API
    │   └── web.php                          # Rota de smoke test
    ├── config/
    │   ├── l5-swagger.php                   # Configuração do Swagger
    │   └── prometheus.php                   # Configuração das métricas
    ├── Dockerfile                           # Imagem PHP 8.3-FPM
    ├── docker-compose.yaml                  # Orquestração local (app, nginx, mysql, rabbitmq, redis)
    └── composer.json                        # Dependências PHP
```

---

## 5. Modelo de Dados

### Tabela `boards`
| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | BIGINT (PK) | Identificador auto-incremento |
| `title` | VARCHAR | Nome do quadro Kanban |
| `created_at` | TIMESTAMP | Data de criação |
| `updated_at` | TIMESTAMP | Data da última atualização |

### Tabela `cards`
| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | BIGINT (PK) | Identificador auto-incremento |
| `board_id` | BIGINT (FK) | Referência ao quadro pai (cascade delete) |
| `title` | VARCHAR | Título do cartão |
| `description` | TEXT (nullable) | Descrição detalhada |
| `position` | INTEGER | Posição de ordenação dentro do quadro |
| `column_id` | VARCHAR | Coluna do Kanban (`backlog`, `todo`, etc.) |
| `priority` | INTEGER | Prioridade projetada do Write Model |
| `assignee` | VARCHAR (nullable) | Responsável |
| `tags` | JSON (nullable) | Tags do card |
| `source_id` | VARCHAR (unique, nullable) | ID do card no Booking Service (idempotência) |
| `created_at` | TIMESTAMP | Data de criação |
| `updated_at` | TIMESTAMP | Data da última atualização |

### Tabela `reunioes_read` *(Read Model — CQRS)*
| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID (PK) | ID do evento original do serviço de reuniões |
| `titulo` | VARCHAR | Título da reunião |
| `data_reuniao` | DATETIME | Data e hora da reunião |
| `organizador_nome` | VARCHAR | Nome do organizador |
| `created_at` | TIMESTAMP | Data de criação |
| `updated_at` | TIMESTAMP | Data da última atualização |

> **Nota de idempotência:** o consumidor `rabbitmq:consume-reuniao-criada` ignora reuniões cujo `id` já existe em `reunioes_read`. O consumidor `rabbitmq:consume-kanban-events` identifica cards pelo `source_id`.

---

## 6. Endpoints da API REST

A stack unificada (`booking-service/docker-compose.yml`) expõe este serviço na porta **8000**. O `docker-compose.yaml` isolado deste repositório usa a porta **8081**. Todas as rotas de negócio exigem `Authorization: Bearer <JWT_TOKEN>`.

### Health & Observabilidade *(públicas)*

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/api/health/live` | **Liveness probe** — retorna `alive` rapidamente |
| `GET` | `/api/health/ready` | **Readiness probe** — valida MySQL, Redis e disco via Spatie Health |
| `GET` | `/api/metrics` | Métricas no formato Prometheus (boards/cards ativos) |

### Boards *(requer JWT)*

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/api/boards` | Lista todos os quadros *(com cache Redis de 5 min)* |
| `GET` | `/api/boards/{id}` | Exibe um quadro específico *(com cache Redis de 5 min)* |

> Mutações de boards (`POST`/`PUT`/`DELETE`) **não são expostas**. O Kanban neste serviço é READ-ONLY.

### Cards *(requer JWT)*

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/api/cards` | Lista todos os cartões |
| `GET` | `/api/cards/{id}` | Exibe um cartão específico |

> Mutações de cards (`POST`/`PUT`/`DELETE`) **não são expostas**. Criação, atualização, movimentação e exclusão ocorrem no Booking Service e chegam via `kanban_events`.

### Verificação de Token

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/api/user-check` | Valida o JWT e retorna os dados do usuário decodificado |

> **Documentação interativa:** Após subir a aplicação, acesse o Swagger em `http://localhost:8081/api/documentation`.

---

## 7. Padrões de Projeto e Decisões de Arquitetura

### Repository Pattern
Os controllers nunca acessam os Models do Eloquent diretamente. Toda a persistência passa pela interface do repositório (`BoardRepositoryInterface`, `CardRepositoryInterface`), cujas implementações concretas são registradas no `AppServiceProvider` via IoC Container do Laravel. Isso desacopla a lógica de negócio do ORM, facilita a escrita de testes com mocks e permite trocar a fonte de dados sem alterar os controllers.

### CQRS (Command Query Responsibility Segregation)
A tabela `reunioes_read` é uma projeção de leitura (Read Model) alimentada pelo consumo de eventos do RabbitMQ. Consultas sobre reuniões são feitas diretamente nessa tabela desnormalizada, sem precisar consultar o serviço de origem, o que melhora a performance e a resiliência das leituras.

### Circuit Breaker
A publicação no RabbitMQ é protegida pelo `CircuitBreakerService`, configurado com estratégia de taxa de falhas: o circuito abre se ≥ 50% das requisições falharem em uma janela de 30 segundos (com mínimo de 5 requisições). O estado do circuito é armazenado no Redis. Quando aberto, o serviço retorna um fallback imediatamente em vez de acumular timeouts.

### Cache com Redis
Leituras de `boards` (listagem e item) são cacheadas no Redis por 5 minutos. Os consumers invalidam `boards:all` após projetar eventos.

### Rastreabilidade com Correlation ID
O `CorrelationIdMiddleware` lê ou gera um UUID em `X-Correlation-ID` em cada requisição, injeta-o no contexto de log global do Laravel (`Log::shareContext`) e o repassa no cabeçalho da resposta. Isso permite correlacionar logs entre todos os microsserviços do ecossistema em uma ferramenta de log centralizada.

### Autenticação com JWT Externo
A autenticação não é gerenciada por este serviço: o JWT é emitido por outro microsserviço e validado aqui por meio do middleware `JwtMiddleware` com `firebase/php-jwt`. Isso garante stateless authentication e desacoplamento do mecanismo de login do ecossistema.

---

## 8. Pré-requisitos

Para rodar o ambiente completo localmente, você precisará de:

| Ferramenta | Versão mínima | Observação |
|---|---|---|
| **Docker** | 24+ | Necessário para todos os containers |
| **Docker Compose** | v2+ | Incluído no Docker Desktop |
| **Git** | qualquer | Para clonar o repositório |
| **Colima** *(opcional)* | qualquer | Alternativa ao Docker Desktop no macOS |

> Para desenvolvimento local sem Docker, são necessários também **PHP 8.2+**, **Composer 2+**, **Node.js 18+** e acesso a instâncias de MySQL, Redis e RabbitMQ.

---

## 9. Instalação e Execução

### Passo 1 — Clone o repositório

```bash
git clone https://github.com/Murilo11/MicroSaas_To_do.git
cd MicroSaas_To_do/php-service
```

### Passo 2 — Configure as variáveis de ambiente

```bash
cp .env.example .env
```

Edite o arquivo `.env` com as configurações do ambiente Docker. Os valores abaixo correspondem exatamente ao `docker-compose.yaml`:

```env
APP_NAME="MicroSaaS To-Do Kanban"
APP_ENV=local
APP_KEY=           # Será gerado no Passo 4
APP_DEBUG=true
APP_URL=http://localhost:8081

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=microsaas
DB_USERNAME=root
DB_PASSWORD=root

QUEUE_CONNECTION=rabbitmq

RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/

REDIS_HOST=redis
REDIS_PASSWORD=admin123
REDIS_PORT=6379

JWT_SECRET=sua_chave_secreta_jwt_aqui
```

> **Importante:** `JWT_SECRET` deve ser idêntico ao segredo configurado no serviço emissor de tokens (Serviço de Reuniões Node.js).

### Passo 3 — Crie a rede externa do ecossistema

Os containers compartilham a rede `microsaas-net`. Crie-a antes de subir os serviços:

```bash
docker network create microsaas-net
```

> **Usuários de macOS com Colima:** Se o Docker não conectar, execute `docker context use colima` antes dos próximos comandos.

### Passo 4 — Suba os containers

```bash
docker compose up -d --build
```

Isso irá inicializar os seguintes serviços:

| Container | Imagem | Porta |
|---|---|---|
| `micro-app` | PHP 8.3-FPM (Dockerfile local) | — |
| `micro-webserver` | nginx:alpine | `8081:80` |
| `micro-db` | mysql:8.0 | `3306:3306` |
| `micro-rabbitmq` | rabbitmq:3-management-alpine | `5672`, `15672` |
| `cqrs_redis` | redis:7-alpine | `6379:6379` |

### Passo 5 — Instale as dependências PHP

```bash
docker exec -it micro-app composer install
```

### Passo 6 — Gere a chave da aplicação

```bash
docker exec -it micro-app php artisan key:generate
```

### Passo 7 — Execute as migrações

```bash
docker exec -it micro-app php artisan migrate
```

Isso criará todas as tabelas: `users`, `boards`, `cards`, `jobs`, `cache`, `personal_access_tokens` e `reunioes_read`.

### Passo 8 — Ajuste as permissões de escrita

```bash
docker exec -it micro-app chmod -R 777 storage bootstrap/cache
```

### Passo 9 — Gere a documentação Swagger

```bash
docker exec -it micro-app php artisan l5-swagger:generate
```

### Passo 10 — Inicie os consumers RabbitMQ

Na stack unificada os workers sobem automaticamente. Em execução isolada:

```bash
docker exec -it micro-app php artisan rabbitmq:consume-reuniao-criada
docker exec -it micro-app php artisan rabbitmq:consume-kanban-events
```

---

### Verificando os serviços

Após todos os passos, os seguintes endereços devem estar acessíveis:

| Serviço | URL | Credenciais |
|---|---|---|
| **API Kanban (stack unificada)** | `http://localhost:8000` | — |
| **API Kanban (compose isolado)** | `http://localhost:8081` | — |
| **Swagger UI (unificada)** | `http://localhost:8000/api/documentation` | — |
| **Health Check** | `http://localhost:8000/api/health/ready` | — |
| **RabbitMQ Management** | `http://localhost:15672` | `guest` / `guest` |

---

### Execução local sem Docker (alternativa)

```bash
# Dentro de php-service/
composer install
cp .env.example .env

# Ajuste o .env para apontar para suas instâncias locais de MySQL, Redis e RabbitMQ

php artisan key:generate
php artisan migrate

# Em terminais separados:
php artisan serve                  # Servidor HTTP na porta 8000
php artisan queue:work             # Worker de filas
```

---

## 10. Variáveis de Ambiente

As variáveis críticas para o correto funcionamento do microsserviço e sua integração com o ecossistema são:

### Aplicação

| Variável | Exemplo | Descrição |
|---|---|---|
| `APP_KEY` | `base64:...` | Chave de criptografia do Laravel (gerada via `artisan key:generate`) |
| `APP_ENV` | `production` | Ambiente (`local`, `staging`, `production`) |
| `APP_URL` | `http://localhost:8081` | URL base da aplicação |

### Banco de Dados

| Variável | Padrão (Docker) | Descrição |
|---|---|---|
| `DB_CONNECTION` | `mysql` | Driver do banco (`mysql`, `sqlite` para testes) |
| `DB_HOST` | `db` | Hostname do container MySQL |
| `DB_PORT` | `3306` | Porta do MySQL |
| `DB_DATABASE` | `microsaas` | Nome do banco de dados |
| `DB_USERNAME` | `root` | Usuário do banco |
| `DB_PASSWORD` | `root` | Senha do banco |

### RabbitMQ *(integração inter-serviços)*

| Variável | Padrão | Descrição |
|---|---|---|
| `QUEUE_CONNECTION` | `rabbitmq` | Define o driver padrão das filas Laravel |
| `RABBITMQ_HOST` | `rabbitmq` | Hostname do container RabbitMQ |
| `RABBITMQ_PORT` | `5672` | Porta AMQP |
| `RABBITMQ_USER` | `guest` | Usuário do RabbitMQ |
| `RABBITMQ_PASSWORD` | `guest` | Senha do RabbitMQ |
| `RABBITMQ_VHOST` | `/` | Virtual host do RabbitMQ |

### Redis

| Variável | Padrão (Docker) | Descrição |
|---|---|---|
| `REDIS_HOST` | `redis` | Hostname do container Redis |
| `REDIS_PORT` | `6379` | Porta do Redis |
| `REDIS_PASSWORD` | `admin123` | Senha do Redis (conforme `docker-compose.yaml`) |

### Autenticação JWT *(integração inter-serviços)*

| Variável | Descrição |
|---|---|
| `JWT_SECRET` | **Chave compartilhada** entre este serviço e o emissor do JWT. Deve ser idêntica à configurada no Serviço de Reuniões (Node.js). Algoritmo: `HS256`. |

---

## 11. Observabilidade

### Health Checks

O serviço expõe dois endpoints de saúde compatíveis com Kubernetes e outros orquestradores:

- **`GET /api/health/live`** — Responde `200 OK` com `{"status": "alive"}` imediatamente. Ideal para *liveness probes*.
- **`GET /api/health/ready`** — Executa checagens reais via `spatie/laravel-health`: conectividade com **MySQL**, **Redis** e uso de **disco** (avisa acima de 70%, falha acima de 90%). Ideal para *readiness probes*.

### Métricas Prometheus

O endpoint `GET /api/metrics` expõe métricas no formato Prometheus:

| Métrica | Tipo | Descrição |
|---|---|---|
| `boards_active_count` | Gauge | Total de quadros (Boards) no banco |
| `cards_active_count` | Gauge | Total de cartões (Cards) no banco |

### Rastreabilidade de Logs

Todas as requisições têm o `correlation_id` injetado no contexto de log via `CorrelationIdMiddleware`. Ao configurar um sistema de log centralizado (ex: Loki, ELK Stack), é possível rastrear uma requisição do Frontend por todos os microsserviços usando o `X-Correlation-ID`.

---

## 12. Testes

A suíte de testes utiliza **PHPUnit 11** e está configurada para usar **SQLite em memória** no `phpunit.xml`, garantindo isolamento total do banco de dados de desenvolvimento.

```bash
# Executando via Docker
docker exec -it micro-app php artisan test

# Executando localmente
cd php-service
php artisan test

# Com saída detalhada
php artisan test --verbose
```

Para formatar o código antes de abrir um Pull Request:

```bash
# Via Docker
docker exec -it micro-app ./vendor/bin/pint

# Localmente
./vendor/bin/pint
```

---

## 13. Contribuindo

1. Crie uma branch a partir da `main`:
   ```bash
   git checkout -b feature/minha-nova-funcionalidade
   ```

2. Implemente sua funcionalidade seguindo os padrões de arquitetura estabelecidos (Repository Pattern, Services, Jobs para tarefas assíncronas).

3. Formate o código com o Laravel Pint:
   ```bash
   ./vendor/bin/pint
   ```

4. Garanta que todos os testes passam:
   ```bash
   php artisan test
   ```

5. Abra um Pull Request com uma descrição clara das mudanças e seu impacto no ecossistema de microsserviços.
