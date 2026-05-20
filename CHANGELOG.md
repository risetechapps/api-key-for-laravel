# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

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
