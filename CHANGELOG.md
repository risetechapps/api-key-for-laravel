# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

## [2.1.0] - 2026-07-24

### Adicionado
- **Validação de API key O(1)**: coluna `lookup_hash` (HMAC-SHA256 com chave) localiza a key com um SELECT indexado + 1 bcrypt, em vez de varrer todas as keys ativas. Migration `add_lookup_hash_to_api_keys_table`; secret configurável via `API_KEY_LOOKUP_SECRET` (padrão `app.key`)
- **Log de requisições assíncrono via fila**: `request_log.queue`/`request_log.connection` (env `API_KEY_LOG_QUEUE`/`API_KEY_LOG_CONNECTION`) descarregam o INSERT do worker web para um worker dedicado; sem fila configurada, mantém a gravação `afterResponse`. O dispatch é best-effort — indisponibilidade da fila (ex.: redis fora) não derruba a requisição
- **Retenção de logs**: comando `api-key:prune-logs` (agendado às 03:30) apaga em lotes os registros além de `request_log.retention_days` (padrão 90); env `API_KEY_LOG_RETENTION_DAYS`, `API_KEY_LOG_PRUNE_ENABLED`, `API_KEY_LOG_PRUNE_CHUNK`
- **Billing por fila**: `ProcessPlanRenewalJob` (1 job por assinatura, `ShouldBeUnique`, `tries=1` para evitar cobrança dupla); `billing:process-renewals` apenas despacha os jobs. Fila configurável via `API_KEY_BILLING_QUEUE`
- **Fila para listeners e notificações (Horizon)**: config `queue.connection`/`queue.name` (env `API_KEY_QUEUE_CONNECTION`/`API_KEY_QUEUE_NAME`, padrão `redis`) roteia os listeners de notificação e as notificações de verificação de e-mail/reset de senha para a conexão observada pelo Horizon
- **Índices de performance**: `user_plans (authentication_id, active, end_date)`, `request_logs (authentication_id, requested_at)`, `user_cards (authentication_id, is_default)` e índices trigram (`pg_trgm`) para a busca de usuários no admin
- **Curinga de subdomínio em origens**: `allowed_origins` agora aceita `*.example.com` (casa qualquer subdomínio e o domínio base), além do curinga à direita
- **Suíte de testes automatizados** (Pest 4) rodando em SQLite: `TestCase` com providers e configuração completos e factories ligadas aos models do pacote

### Alterado
- Notificações não bloqueiam mais a resposta HTTP: o envio de e-mail passou a ser enfileirado (aviso de uso, limite atingido, carência, expiração, plano ativado, verificação de e-mail e reset de senha)
- `CheckRequestLimitMiddleware` deixou de despachar os eventos de aviso/limite a cada requisição depois do primeiro e-mail do período (checa a chave de dedup no cache antes de enfileirar o listener)
- Datas de `user_plans` migradas de `date` para `timestamp` — a truncagem para meia-noite expirava assinaturas até um dia antes

### Corrigido
- Fallback de compatibilidade de keys legadas (sem `lookup_hash`) agora tem orçamento de tempo (`legacy_scan.max_seconds`, env `API_KEY_LEGACY_SCAN_MAX_SECONDS`, padrão 3s): impede que uma migração v1→v2 com muitas keys transforme uma autenticação em um scan de minutos
- `Plan::features` retorna `[]` (em vez de `null`) quando não há features
- `Authentication` e `Coupon`: campos controlados (`email`, `status`, `role`, `locale`, `gateway_coupon_id`) protegidos da normalização em maiúsculo do `HasToUpper`, evitando quebra de enum de status/role e de identificadores externos (ex.: id de cupom do gateway)
- Migrations tornadas compatíveis com SQLite (guards por driver para recursos exclusivos do PostgreSQL: `citext`, `gen_random_uuid()`, `ALTER COLUMN ... USING`), sem alterar o comportamento em PostgreSQL

### Notas de atualização
- Rode as migrations: `php artisan migrate` (adiciona `lookup_hash`, os índices e altera os tipos de data de `user_plans`)
- **Filas**: para os ganhos de latência, mantenha um worker processando as filas configuradas (`queue.name` e `request_log.queue`) na conexão `queue.connection` (padrão `redis`, alinhado ao Horizon). Sem worker, use `QUEUE_CONNECTION=sync` **ou** deixe `request_log.queue` nulo (grava via `afterResponse`)
- **Agendador**: garanta o `schedule:run` no cron para `billing:process-renewals` (08:00) e `api-key:prune-logs` (03:30)

## [2.0.0]

### Adicionado
- Notificações de **plano ativado/alterado** (`PlanActivatedNotification` via `PlanChanged`), **aviso de uso** ao atingir o limiar (`UsageThresholdNotification`) e **limite atingido** (`RequestLimitReachedNotification`), com listeners registrados automaticamente
- Novo evento `PlanUsageThresholdReached` e configuração `request_limit.warning_threshold` (env `API_KEY_USAGE_WARNING_THRESHOLD`, padrão `80`%) para o aviso de uso; throttle por período/24h evita e-mails repetidos
- Mapa `notifications` em `config/api-key.php` — permite substituir qualquer uma das 7 notificações por uma classe própria (mantendo a assinatura de construtor)
- Endpoint `GET /api/v1/dashboard/stats` com estatísticas agregadas leves (uso, restantes, hoje) e auto-refresh no dashboard (polling de 5s, com pausa em aba oculta e sem sobreposição)
- Páginas públicas na SPA: Termos de Uso (`/terms`), Política de Privacidade (`/privacy`) e Suporte (`/support`); seção de Documentação (`#docs`) na home
- Helper `FeatureResolver`, reutilizado por `PlansResource` e `UserPlanResource` para resolver features para `{key, name, description, icon}`

### Alterado
- Removido o campo manual `features_description` dos planos — a descrição exibida passa a vir 100% dos metadados das features registradas (`FeatureRegistry`); a key nunca é mostrada como rótulo (model, Resource, regras, formulário admin e migrations atualizados)
- Middleware `feature` agora aplica automaticamente `check.limit.plan` (log + contagem de requisições); `CheckRequestLimitMiddleware` tornou-se idempotente para não contar em dobro quando também presente no grupo `plan`
- `GET /api/v1/dashboard/log` agora retorna os registros ordenados do mais recente para o mais antigo
- Cards de preço da home alinhados ao dashboard (linha de requisições/mês, efeito de elevação no hover e divisória)
- Rodapé da home: "Documentação" → `#docs`, "Suporte" → `/support`, "Termos" → `/terms`, adicionado "Privacidade" e removido "Status"

### Corrigido
- Listeners `SendGracePeriodNotification` e `SendPlanExpiredNotification` nunca disparavam (não eram registrados — listeners de pacote não são auto-descobertos); registrados via `Event::listen`, restabelecendo os e-mails de **carência** e **expiração de plano**
- Contagem de requisições ultrapassava o limite (ex.: `102/100`): requisições bloqueadas (429) ainda incrementavam `requests_used`; agora são registradas no log mas **não contam** na cota, e o uso exibido é limitado ao teto do plano (`/dashboard/stats` e `/auth/me`)
- `RequestLog::requested_at` sem cast era serializado como string sem fuso e exibido com horário deslocado; adicionado cast `datetime` (ISO8601 com timezone)
- Lista de cupons no painel ficava obsoleta até `cache:clear` — o cache do repository não invalida com driver `database`/`file` (sem suporte a tags); `index()` passou a usar `withoutCache()` e `delete()` agora passa pelo repository (`find()->delete()`)
- Dashboard exibia features fictícias ("Módulo A/B/C") no card do plano; `UserPlanResource` agora resolve as features (name/description/icon) em vez de devolver as keys cruas, e o card lê os dados reais
- Card "Requisições usadas" do dashboard só atualizava após F5; agora reflete o polling de stats (a cada 5s)
- Menu "Docs" e links do rodapé eram `href="#"` (sem destino); seção `#docs` criada e links corrigidos

### Removido
- Coluna `features_description` da tabela `plans` (migration `drop_features_description_from_plans_table`) e o campo manual correspondente no formulário de planos do admin

## [1.0.8] - 2026-06-03

### Corrigido
- Criar/atualizar planos falhava com `SQLSTATE[42703]: column "features_description" does not exist` em ambientes que rodaram a migration `create_plans_table` antes de a coluna ser adicionada (11/05/2026); criada migration aditiva `add_features_description_to_plans_table` com guarda `Schema::hasColumn` (segura para instalações novas e existentes)

## [1.0.7] - 2026-05-27

### Corrigido
- Corrigido erro ao atualizar cupom

## [1.0.6] - 2026-05-27

### Corrigido
- `GET /api/v1/auth/me` retornava 401 para a SPA porque estava no grupo `plan` (exigia `X-API-KEY`); movido para `auth:sanctum` — Bearer token do Sanctum agora é aceito corretamente
- `ApiKeyServiceProvider::registerRouter()` envolvia todas as rotas internas no grupo `plan`, fazendo `register` e `login` também exigirem API key; wrapper removido
- `requests.vue` — aba "Histórico" nunca ficava ativa porque `activeTab` era inicializado com `'test'` (chave inexistente); corrigido para usar `tabs[0].key`
- `requests.vue` — histórico de requisições agora ordenado do mais recente para o mais antigo
- `plans.vue` e `price-section.vue` — planos agora ordenados por preço crescente via `raw_price` (o campo `price` é string formatada `"R$ 29,90"` e quebrava o `parseFloat`)
- `coupons.vue` — coluna "Usos" exibia `"0 /"` porque lia `coupon.uses` e `coupon.max_uses` (campos planos inexistentes); corrigido para `coupon.usage.current` e `coupon.usage.max` conforme o `CouponsResource`
- `coupons.vue` — modal de edição carregava `max_uses` como `undefined`; corrigido para `coupon.usage.max`
- Contagem de usos de cupom não atualizava no painel admin após checkout — `increment('uses')` no model bypassava o `BaseRepository`, deixando o cache de 24 h obsoleto; criado `CouponRepository::incrementUses()` que faz o increment e chama `clearCacheForEntity()`

### Adicionado
- `coupons.vue` — botão de atualização manual na listagem de cupons para forçar re-fetch da contagem de usos sem recarregar a página

## [1.0.5] - 2026-05-25
### Corrigido
- Corrigido erros de palavras

## [1.0.4] - 2026-05-25
### Corrigido
- Corrigido axios em price-section

## [1.0.3] - 2026-05-22
### Corrigido
- Corrigido manifest

## [1.0.2] - 2026-05-20
### Corrigido
- Corrigido erro de carregamento dos planos

## [1.0.1] - 2026-05-20

### Corrigido
- `CheckActivePlanMiddleware` trocado `activePlan` por `activePlanWithGracePeriod` — o grace period nunca era alcançado porque a relação anterior já filtrava planos expirados
- `ProcessRenewalsCommand` agora salva `payment_amount` ao criar o novo plano na renovação automática
- `PlanService::subscribe()` delegava para lógica própria divergente; agora chama `Authentication::subscribeToPlan()` garantindo que o evento `PlanChanged` seja sempre disparado
- `ApiKey::clearOriginCache()` era no-op; implementado com versioning de cache — ao alterar `allowed_origins`, um contador de versão é incrementado tornando as entradas antigas imediatamente obsoletas
- `PlansResource::resolveFeatures()` quebrava com `TypeError` quando o campo `features` continha objetos em vez de strings simples; agora aceita ambos os formatos

## [1.0.0] - 2026-05-19

### Adicionado
- Sistema de autenticação com API Key (geração, hash, validação com cache)
- Gerenciamento de planos com ciclos de cobrança configuráveis (`BillingCycle`)
- Assinatura de planos com controle de data de início/fim e período de carência
- Controle de limite de requisições por plano com log por endpoint
- Sistema de cupons de desconto (percentual e valor fixo)
- Integração com MercadoPago (checkout, cartões salvos, webhook com validação HMAC, estornos)
- Campos de cartão via MercadoPago Secure Fields (iframes oficiais), eliminando captura insegura
- Detecção de bandeira do cartão em tempo real via `binChange` e `mp.getPaymentMethods`
- Tokenização de CVV de cartões salvos diretamente no browser via `mp.createCardToken`
- `MpCustomerService::getOrCreateCustomer()` com tratamento do código de causa `101` (cliente duplicado)
- Campo `payment_amount` em `user_plans` para registrar o valor efetivamente cobrado (com desconto aplicado)
- **FeatureRegistry** — `FeatureRegistry::register('key', [...])` registra features em código, sincroniza na tabela `plan_features` e expõe ao painel admin via `GET /dashboard/admin/features`
- Tabela `plan_features` dedicada para o registro de features (sem conflito com `laravel/pennant`)
- Middlewares: `api.key`, `check.active.plan`, `check.limit.plan`, `api.key.origin`, `feature`, `admin`, `language`
- Grupo de middlewares `plan` configurável via `config/api-key.php`
- Eventos: `ApiKeyCreated`, `ApiKeyStatusChanged`, `PlanChanged`, `PlanExpired`, `PlanGracePeriodStarted`, `RequestLimitReached`, `UserStatusChanged`
- Notificações de período de carência e expiração de plano por e-mail
- Painel admin com gestão de planos, usuários, estornos e features dinâmicas
- Suporte a SPA Vue.js com rota catch-all e assets pré-compilados via Vite
- `MP_PUBLIC_KEY` entregue ao frontend via `AuthenticationMeResource` (campo `mp_public_key`)
- Internal token bypass para chamadas servidor-a-servidor (validado por IP loopback)
- Comandos Artisan: `billing:process-renewals` (agendado diariamente às 08:00), `check:expired-plans`, `make:admin`
- Rate limiting configurável para endpoints de autenticação (`auth_throttle`)
- Suporte a origens permitidas por API key com validação via wildcard
- Internacionalização (pt/en) com idioma padrão configurável
- Publicação de config, migrations, rotas, frontend, views e assets via `php artisan vendor:publish`
