# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

## [Não lançado]

### Adicionado
- **Cancelamento de assinatura pelo cliente**: `POST /dashboard/signature/cancel` e `POST /dashboard/signature/resume`. Não existia saída nenhuma — sem rota, sem flag, sem coluna: quem assinava ficava preso até vencer e, com cartão salvo, era cobrado de novo a cada `end_date`; o único caminho era pedir estorno manual a um admin. Cancelar **não revoga**: o período pago corre até o fim e só a renovação para. `billing:process-renewals` filtra `cancelled_at IS NULL`, e o `ProcessPlanRenewalJob` refaz a checagem ao executar, antes de falar com o gateway (o job pode ficar na fila enquanto o assinante cancela). `cancel` é idempotente; `resume` só vale enquanto o período não venceu. Nova coluna `user_plans.cancelled_at` (migration `add_cancelled_at_to_user_plans_table`) e `UserPlan::isCancelled()`. **Não** há e-mail de confirmação — se quiser, siga o padrão evento/listener/`notifications` dos outros 7
- **E-mail de confirmação de cancelamento**: evento `PlanCancelled` → `SendPlanCancelledNotification` → `PlanCancelledNotification`, plugável por `config('api-key.notifications.plan_cancelled')` como as outras 7. Responde as três dúvidas de quem acabou de cancelar: funcionou, não perdi o acesso, e não serei cobrado de novo
- **Cancelamento no painel**: seção "Assinatura" em `billing.vue` com botão de cancelar/reativar. A confirmação diz o que **não** vai acontecer ("sua assinatura não será interrompida agora"), porque a dúvida de quem clica ali é se perde o acesso na hora. O card de resumo troca "Próxima Cobrança" por "Acesso até" quando cancelado — mesma data, significados opostos. `UserPlanResource` e `SignatureHistoryResource` passaram a expor o bloco `cancellation`
- **Análise estática**: Larastan no nível 5 (`composer analyse`), rodando no CI antes dos testes. Os 361 erros do código pré-existente ficam registrados em `phpstan-baseline.neon` em vez de silenciados no código, então a checagem já vale para tudo que for escrito daqui em diante. Ela achou logo de cara um tipo errado no `SendsIdempotentPayments` — que acabou sendo docblock incorreto do SDK do Mercado Pago (`setCustomHeaders` anotado como `array<string,string>`, mas o valor termina em `CURLOPT_HTTPHEADER`, que exige lista de `'Nome: valor'`); documentado em `ignoreErrors` com o porquê
- **`.gitattributes`**: normaliza a quebra de linha para LF no repositório e tira `tests/`, `.github/`, `phpunit.xml` e configs de ferramenta do tarball de distribuição. `resources/js`, `resources/css`, `resources/views`, `stubs/` e `dist/` **continuam** no pacote — são publicados pelas tags `api-key-frontend`, `api-key-views`, `api-key-build` e `api-key-assets`
- **`pint.json`** e scripts `composer lint` / `lint-test` / `analyse`. O `composer test` apontava para `vendor/bin/phpunit` enquanto a suíte é Pest; corrigido

- **Idempotência nas cobranças**: `POST /dashboard/checkout/process` e `POST /dashboard/cards` aceitam `idempotency_key` opcional, repassado ao MercadoPago via `X-Idempotency-Key` — chave repetida devolve o pagamento original em vez de criar outro. Sem o campo, o pacote deriva uma chave de pagador + plano + valor + token de cartão. Cobre o caso em que a cobrança é aceita e a resposta se perde num timeout, com o comprador reenviando o formulário. O trait `SendsIdempotentPayments` concentra a lógica. A renovação automática já estava protegida por `ShouldBeUnique` + `tries = 1`
- **Crédito pró-rata na troca de plano**: assinar um plano novo encerra o corrente e abre outro do zero, então quem trocava no dia 3 de 30 perdia 27 dias pagos. O checkout agora credita o valor proporcional do que sobrou, na ordem **preço → cupom → crédito** (cupom percentual incide sobre o preço cheio). Crédito que cobre o total ativa a assinatura sem cobrança. Novo `UserPlan::unusedCredit()`, nova coluna `user_plans.credit_applied` (migration `add_credit_applied_to_user_plans_table`) para distinguir desconto de cupom de crédito de troca. `POST /dashboard/checkout/coupon` passou a devolver `credit` e a considerá-lo no `final_price`, senão a tela cotava um valor e o cartão era debitado outro
- **`api-key:rotate-keys`**: emissão de novas API keys em lote, com `--legacy` (backlog v1 → v2 sem `lookup_hash`), `--user=`, `--all`, `--output=` (CSV), `--dry-run` e `--force`. `ApiKey::resolveLegacyKey()` recomendava rotacionar keys legadas desde a 2.1.0 e a única ferramenta era o botão do painel, um usuário por vez. Exige exatamente um seletor e recusa rotacionar mais de uma key sem `--output` — a key nova existe em texto puro só durante a execução

- **Testes das correções de cobrança e cota** (30 casos novos, suíte de 94 → 124): crédito pró-rata (`UserPlanCreditTest`), reserva/devolução de cupom (`CouponClaimTest`), endpoint de assinatura gratuita (`SignatureControllerTest`) e contabilidade de cota (`RequestQuotaTest`). O `TestCase` passou a carregar o `RepositoryServiceProvider` — sem ele o binding de `PlanRepository`/`CouponRepository` não é registrado e qualquer controller que receba um repository por injeção era irresolvível nos testes, o que explicava a ausência de cobertura nessa camada

### Removido
- **BREAKING**: casos `PIX`, `BANK_SLIP` e `BANK_TRANSFER` de `BillingMethod`. Nada no pacote os implementava — `CheckoutController::process()` só monta pagamento com token de cartão —, então eram opções que a validação aceitava e o checkout não sabia cobrar. Voltam junto com o fluxo assíncrono (QR code / linha digitável, expiração, ativação no webhook), se ele for implementado
- **BREAKING**: `method` e `method_data` das `SignatureRules`. O controller nunca os leu, e o endpoint hoje só ativa plano de preço zero — exigir forma de pagamento para assinatura que não cobra nada obrigava o cliente a inventar valor só para passar na validação

### Corrigido
- **Assinatura gratuita de plano pago (crítico)**: `POST /api/v1/dashboard/signature` validava `method`/`method_data` mas os ignorava e chamava `subscribeToPlan()` direto — qualquer usuário autenticado ativava o plano mais caro sem pagar. O endpoint agora só ativa planos de preço zero; plano com preço responde 422 e exige o checkout (que cobra antes de assinar). A tentativa fica registrada em log
- **Plano desativado continuava vendável**: `is_active` era filtrado apenas na listagem do catálogo, mas o `plan_id` viaja no corpo da requisição — quem soubesse o id assinava um plano fora de venda. Checkout e assinatura passaram a resolver o plano por `PlanRepository::findActiveById()`. O webhook segue usando `findById()` de propósito: pagamento já aprovado tem que ser entregue mesmo se o plano saiu de venda no meio do caminho
- **Race no uso de cupom**: `isValid()` lia `uses` e `incrementUses()` incrementava só depois da cobrança, então N checkouts simultâneos passavam do `max_uses` — e, como o incremento vinha após o pagamento, não havia como recusar os excedentes. `CouponRepository::claimUse()` faz validade + incremento em um único UPDATE condicional (serializado pelo banco na linha) e é chamado **antes** de acionar o gateway; `releaseUse()` devolve a reserva quando o pagamento não se concretiza (cartão recusado, dados inválidos, exceção). Pagamento `pending`/`in_process` mantém a reserva, pois o webhook ainda pode confirmá-lo

- **`verifyEmail` gravava a URL assinada inteira no log**: o contexto de erro incluía `fullUrl()` (com `signature`) e um fingerprint da `APP_KEY` — instrumentação usada para caçar a falha de assinatura atrás de proxy reverso, corrigida no próprio 2.2.2 com `trustProxies()`, mas que continuou rodando a cada verificação falha. Passou a logar `path`, `id`, `expires` e `has_signature`, que é o que serve para diagnosticar. Replay da URL vazada já era inerte (a assinatura cobre `{id}`/`{hash}`, então a requisição repetida cai no mesmo `hash_equals` que a rejeitou), então isto é higiene e não correção de vulnerabilidade
- **`UserCard.is_default` sem cast**: o model não declarava `$casts`, e `GET /dashboard/cards` serializa o model cru — o JSON devolvia o que o driver entregasse (bool no PostgreSQL, inteiro no SQLite dos testes), fazendo o contrato da API variar com o banco. Adicionado `'is_default' => 'boolean'`
- `forgotPassword` lia `email` antes de chamar `validate()`. Sem impacto (a validação lança e interrompe o método), mas a ordem invertida exigia conferir o comportamento do `validate()` para concluir que as linhas de log eram seguras

- **Estorno vazava a mensagem crua da exceção no painel admin**: `AdminController::processRefund()` concatenava `$e->getMessage()` na resposta. O `catch` é genérico, então o que chegava à tela podia ser o detalhe interno do Mercado Pago ou uma `QueryException` — que carrega o SQL com os valores bindados — e de lá seguia para screenshot e ticket de suporte. Agora a resposta traz um código de correlação de 8 caracteres; o detalhe completo fica no log via `Log::error` (com `error_id`, `user_plan_id`, `payment_id`, `admin_id`) e no `report()`. A mensagem também deixou de ser um texto solto do SDK em inglês: passou a ser traduzida, diz que o pagamento não foi alterado e aponta o código para consulta

- **Cadastro quebrado na SPA (crítico)**: `register()` chamava `setAuth(data, data.token)`, mas a API responde `{ message, api_key }` — sem token e sem usuário. Resultado: a string `"undefined"` gravada no `localStorage`, `Authorization: Bearer undefined` em toda requisição seguinte, e `router.push('/dashboard')` para uma sessão que nunca existiu (tudo 401). Pior: a `api_key` era descartada — é a única vez em que a chave existe em texto puro. Agora o registro devolve a chave, a tela a exibe uma vez com opção de copiar (modal bloqueante, já que ela é irrecuperável) e encaminha para o login, que é o fluxo correto porque a entrada exige e-mail verificado
- **Métrica inventada no painel**: o card "Cache Hit Rate" lia `stats.cache_hit_rate`, fixado em `85` no store — número apresentado ao usuário como se fosse medição. O pacote não expõe métrica de cache; o card passou a mostrar o limite do plano, que é dado real
- **Sessão expirada travava a SPA**: sem interceptor de 401, um token inválido fazia toda chamada falhar em silêncio com o usuário preso numa tela quebrada e ainda "logado" para o front. Adicionado interceptor que limpa a credencial e redireciona para `/login?error=session_expired`, ignorando as rotas públicas de autenticação para não criar laço
- **`//@ts-nocheck` removido de `dashboard.ts`**: o store com a lógica de cobrança rodava sem checagem de tipo nenhuma. Tipado por completo (interfaces de stats, log, plano, assinatura e cartão) e verificado com `tsc --strict`
- **`testRequest()` chamava rota inexistente** (`/dashboard/test-request`, ausente do `RoutesApiKey`); removido junto com a URL sem barra inicial em `dashboard/cards`
- **Promessa de reembolso sem implementação**: o FAQ de planos dizia "cancele dentro deste período para reembolso integral", como se cancelar devolvesse dinheiro. Cancelar interrompe a renovação e nada mais — estorno é operação manual do admin. Texto corrigido para descrever o que o sistema faz
- **SPA mandava a chave errada ao assinar plano gratuito**: `dashboard.ts` postava `{ plan_id }` em `/dashboard/signature`, que espera `plan` — toda chamada respondia 422. Nenhuma view chamava a função, então o defeito nunca apareceu
- **Total gasto no painel somava preço de tabela**: `billing.vue` acumulava `plan.raw_price`, ignorando cupom e crédito de troca, e exibia um total que o cliente nunca pagou. Passou a somar `payment.amount` (com fallback para o preço em assinaturas anteriores ao registro de `payment_amount`), exposto agora pelo `SignatureHistoryResource`
- **Identificadores do gateway expostos ao navegador**: `GET /dashboard/cards` serializa o model cru, então `mp_customer_id` e `mp_card_id` iam na resposta de toda listagem de cartões. Não são credenciais — mover dinheiro exige o access token, que fica no servidor —, mas são as referências usadas para tokenizar e cobrar o cartão salvo, e nenhuma tela precisa delas. Adicionado `$hidden` no `UserCard`
- **Renovação podia cobrar duas vezes**: `ProcessPlanRenewalJob` tinha `ShouldBeUnique` (impede duas execuções simultâneas) e `tries = 1` (impede retry após timeout), mas nenhum dos dois cobre a janela em que a cobrança é aceita, a escrita que a registra falha, e o `uniqueFor` de 1h depois permite uma nova execução despachar a mesma renovação. Agora manda `X-Idempotency-Key` derivado do período sendo renovado — nunca só da assinatura, senão a renovação do mês seguinte seria recusada como duplicata

- **Cota consumida por falha de servidor**: `CheckRequestLimitMiddleware` reserva a requisição antes de processá-la (é o que garante o limite sob concorrência), mas um `5xx` ou uma exceção não tratada da aplicação deixavam o cliente pagando por uma requisição que não entregou nada. A reserva agora é devolvida nesses dois casos. `4xx` continua consumindo de propósito — é requisição malformada do próprio cliente, e estornar tornaria a cota burlável. Comportamento documentado no README

### Alterado
- **BREAKING (interface interna)**: `CouponRepository::incrementUses()` foi substituído por `claimUse()`/`releaseUse()`. Implementações próprias do contrato precisam ser atualizadas
- `PlanRepository` ganhou `findActiveById()`

## [2.2.2] - 2026-07-30

### Adicionado
- **Logs estratégicos** em todo o pacote: `AuthController` (register, login, logout, forgot/reset password), `UserRegistrationService`, `AuthenticateApiKey` (key missing/invalid/sem dono), `CheckActivePlanMiddleware` (plano expirado, sem plano, grace period), `CheckRequestLimitMiddleware` (limite e threshold atingidos)
- Log detalhado no `verifyEmail` com contexto completo (URL, app_url, app_key_hash, expires) para debug de falhas de assinatura

### Corrigido
- **Verificação de email quebrada atrás de proxy reverso**: `URL::hasValidSignature` falhava porque `$request->url()` retornava `http://` (proxy termina SSL) enquanto a URL assinada usava `https://` do `APP_URL`. Adicionado `middleware->trustProxies()` no `bootstrap/app.php` para confiar nos headers `X-Forwarded-*`

## [2.2.1] - 2026-07-28
- Atualizado packages

## [2.2.0] - 2026-07-24
- Atualizado packages

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
