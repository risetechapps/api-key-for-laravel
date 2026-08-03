# risetechapps/api-key-for-laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/risetechapps/api-key-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/api-key-for-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/risetechapps/api-key-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/api-key-for-laravel)
[![GitHub Actions](https://github.com/risetechapps/api-key-for-laravel/actions/workflows/main.yml/badge.svg)](https://github.com/risetechapps/api-key-for-laravel/actions)
[![Tests](https://img.shields.io/badge/tests-63%20passing-green.svg)](tests)
[![PHP Version](https://img.shields.io/badge/php-%5E8.4-blue.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E12-red.svg)](https://laravel.com)

Gerenciamento de API keys, planos de assinatura e um painel SPA Vue 3 pronto para uso — tudo em um único pacote Laravel.

## Funcionalidades

- **Gerenciamento Seguro de API Keys** — hash bcrypt, nunca armazenado em texto puro
- **Planos de Assinatura** — ciclos de cobrança, limites de requisições, feature flags
- **FeatureRegistry** — registre features dos planos em código, sincronização automática no banco, exposto ao painel admin
- **Período de Carência** — janela de tolerância configurável após expiração do plano
- **Sistema de Cupons** — limite de usos, data de expiração, descontos percentuais
- **Validação de Origem** — proteção por API key similar ao CORS
- **Throttling e Rate Limiting** — contadores atômicos por usuário
- **Cancelamento pelo assinante** — interrompe a renovação sem revogar o período já pago, com reativação enquanto o ciclo corre
- **Política de reembolso** — janela de arrependimento configurável, com teto de consumo, estorno integral e revogação imediata
- **Sistema de Eventos** — `PlanChanged`, `PlanExpired`, `PlanGracePeriodStarted`, `PlanCancelled`, `PlanRefunded`, `PaymentRejected`, `RequestLimitReached`, `PlanUsageThresholdReached`, `UserStatusChanged`
- **Notificações por E-mail** — plano ativado, aviso de uso (80%), limite atingido, carência, expiração, cancelamento, estorno, pagamento recusado, verificação de e-mail e redefinição de senha (pt-BR) — todas substituíveis por config
- **Fluxo de Redefinição de Senha** — ciclo completo de recuperação com URLs assinadas apontando para a SPA
- **Verificação de E-mail** — redireciona para a SPA após o clique
- **Integração MercadoPago** — checkout com Secure Fields, cartões salvos com pagamento em um clique, webhook assinado e IPN, idempotência, identificação de dispositivo, estornos
- **Pagamentos em análise** — compras que o gateway retém são registradas e resolvidas por webhook ou por reconciliação agendada
- **Painel SPA Vue 3** — assets pré-compilados, sem necessidade de Node.js no servidor host
- **Internacionalização** — inglês e português (pt-BR), detectado automaticamente via `Accept-Language`
- **Camada de Cache** — suporte a Redis/Memcached para validação de API keys
- **Suite de Testes** — 351 testes Pest, análise estática com Larastan e estilo com Pint no CI

## Requisitos

- PHP ^8.4
- Laravel ^12
- Laravel Sanctum ^4.0

---

## Instalação

```bash
composer require risetechapps/api-key-for-laravel
```

### 1. Publicar e executar as migrations

```bash
php artisan vendor:publish --tag="api-key-migrations"
php artisan migrate
```

### 2. Publicar a configuração

```bash
php artisan vendor:publish --tag="api-key-config"
```

---

## Modos de operação

### Modo A — Somente API (padrão)

O pacote expõe endpoints REST sob `api/v1/`. O painel SPA fica desativado. Use este modo quando tiver seu próprio frontend ou precisar apenas da API.

`.env`:
```
API_KEY_SPA_ENABLED=false
```

### Modo B — API + Painel SPA

O pacote também serve um painel Vue 3 pré-compilado. Não é necessário Node.js no servidor host — os assets são distribuídos junto com o pacote, como o Laravel Horizon ou o Telescope.

**Passo 1 — publicar os assets:**

```bash
php artisan vendor:publish --tag="api-key-assets"
```

Isso copia os arquivos pré-compilados do `dist/` para `public/vendor/api-key/`.

**Passo 2 — habilitar a SPA no `.env`:**

```
API_KEY_SPA_ENABLED=true
```

Quando habilitada:
- Uma rota catch-all `/{any}` serve `resources/views/vendor/api-key/app.blade.php` para todos os caminhos que não sejam da API.
- O `DisableRouteWebMiddleware` é desabilitado automaticamente para que o navegador alcance o frontend.

**Para customizar o shell Blade** (título, meta tags, scripts de analytics):

```bash
php artisan vendor:publish --tag="api-key-views"
```

Isso copia o `app.blade.php` para `resources/views/vendor/api-key/app.blade.php`.

---

## Rotas

### Rotas automáticas (embutidas)

Quando `API_KEY_ROUTES_ENABLED=true` (padrão), o pacote registra as rotas automaticamente sob `api/v1/`:

| Método | URI | Descrição |
|--------|-----|-----------|
| `POST` | `api/v1/register` | Registrar novo usuário |
| `POST` | `api/v1/login` | Login e recebimento do token Sanctum |
| `POST` | `api/v1/logout` | Revogar token atual |
| `GET` | `api/v1/auth/me` | Obter usuário autenticado |
| `GET` | `api/v1/email/verify/{id}/{hash}` | Verificar e-mail |
| `POST` | `api/v1/forgot-password` | Enviar e-mail de redefinição de senha |
| `POST` | `api/v1/reset-password` | Redefinir senha com token |
| `GET` | `api/v1/dashboard/plans` | Listar planos disponíveis |
| `POST` | `api/v1/dashboard/checkout/process` | Processar pagamento |
| `POST` | `api/v1/dashboard/checkout/coupon` | Validar cupom |
| `POST` | `api/v1/dashboard/checkout/webhook` | Webhook do MercadoPago |
| `GET` | `api/v1/dashboard/profile` | Obter perfil |
| `PUT` | `api/v1/dashboard/profile` | Atualizar perfil |
| `POST` | `api/v1/dashboard/profile/regenerate-key` | Regenerar API key |
| `GET` | `api/v1/dashboard/cards` | Listar cartões salvos |
| `POST` | `api/v1/dashboard/cards` | Adicionar cartão |
| `DELETE` | `api/v1/dashboard/cards/{id}` | Remover cartão |
| `POST` | `api/v1/dashboard/signature` | Ativar plano de preço zero |
| `POST` | `api/v1/dashboard/signature/cancel` | Cancelar a renovação (estorna se a política conceder) |
| `POST` | `api/v1/dashboard/signature/resume` | Reativar a renovação antes do vencimento |
| `GET` | `api/v1/dashboard/signature/refund-preview` | O que acontece se cancelar agora: estorno, valor e até quando vai o acesso |
| `GET` | `api/v1/dashboard/history` | Histórico de assinaturas |
| `GET` | `api/v1/dashboard/log` | Log de requisições (ordenado do mais recente) |
| `GET` | `api/v1/dashboard/stats` | Estatísticas agregadas do dashboard (uso, restantes, hoje) |

Rotas exclusivas de admin (requerem middleware `admin`):

| Método | URI | Descrição |
|--------|-----|-----------|
| `POST/PUT/DELETE` | `api/v1/dashboard/plans/{plan}` | Criar / atualizar / excluir planos |
| `POST/PUT/DELETE` | `api/v1/dashboard/coupons/{coupon}` | Criar / atualizar / excluir cupons |
| `GET` | `api/v1/dashboard/admin/plans` | Listar todos os planos (visão admin) |
| `GET` | `api/v1/dashboard/admin/users` | Listar usuários com assinaturas |
| `GET` | `api/v1/dashboard/admin/refunds` | Listar pagamentos com opção de estorno |
| `POST` | `api/v1/dashboard/admin/refunds/{id}` | Processar estorno via MercadoPago |
| `GET` | `api/v1/dashboard/admin/features` | Listar features registradas (`FeatureRegistry`) |

### Registro manual de rotas com `RoutesApiKey`

Use quando precisar montar as rotas do pacote dentro do seu próprio arquivo de rotas com opções específicas (prefixo, middlewares, etc.):

```php
// routes/api.php

use RiseTechApps\ApiKey\RoutesApiKey;

RoutesApiKey::register([
    'prefix'     => 'api/v1',
    'middleware' => ['api'],
]);
```

> Desabilite as rotas automáticas primeiro: `API_KEY_ROUTES_ENABLED=false`

---

## Protegendo suas próprias rotas

Use o grupo de middleware `plan` para proteger qualquer rota. Ele valida a API key, garante uma assinatura ativa, verifica os limites de requisição e valida a origem da requisição:

```php
Route::middleware(['api', 'plan'])->group(function () {
    Route::get('/api/v1/data', fn() => response()->json(['ok' => true]));
});
```

Envie a API key no header da requisição:

```bash
curl -H "X-API-KEY: sua-api-key-aqui" \
     -H "Origin: https://seudominio.com" \
     https://api.seuapp.com/api/v1/data
```

### Middleware `feature`

Restrinja uma rota a planos que tenham uma feature específica habilitada:

```php
Route::middleware(['api', 'plan', 'feature:relatorios_avancados'])->group(function () {
    Route::get('/api/v1/relatorios', ReportController::class);
});
```

---

## Planos de Assinatura

```php
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Enums\BillingCycle;

$plan = Plan::create([
    'name'          => 'Premium',
    'description'   => 'Plano premium com 10 mil requisições/mês',
    'request_limit' => 10000,
    'price'         => 29.99,
    'billing_cycle' => BillingCycle::MONTHLY,
    'features'      => ['relatorios_avancados', 'exportar_csv'],
]);

// Assinar um usuário
$user->subscribeToPlan($plan);
```

### Cancelamento

```
POST /api/v1/dashboard/signature/cancel    # para de renovar
POST /api/v1/dashboard/signature/resume    # desfaz, enquanto o período corre
```

**Cancelar não é revogar.** O período já pago continua valendo até o `end_date` e a API key segue funcionando — quem cancela no dia 2 de 30 não perde os outros 28. O que para é a próxima cobrança:

- `billing:process-renewals` filtra `cancelled_at IS NULL` e nem enfileira o job
- `ProcessPlanRenewalJob` refaz a checagem ao executar, **antes** de falar com o gateway: o job pode ter ficado na fila enquanto o assinante cancelava
- no vencimento a assinatura expira pelo caminho normal (carência inclusive)

`cancel` é idempotente — um retry pede o estado que já foi pedido. `resume` só funciona enquanto o período não venceu; depois disso não há o que renovar e o cliente contrata de novo pelo checkout.

Uma coluna só (`user_plans.cancelled_at`) em vez de um par `cancelled_at` / `auto_renew`: dois campos carregando o mesmo fato acabam discordando, e a renovação precisa de uma fonte única. Null renova; timestamp é ao mesmo tempo a decisão e o registro de quando ela foi tomada.

O cancelamento dispara `PlanCancelled`, que envia o e-mail de confirmação.

### Reembolso no cancelamento

Janela de arrependimento, **desligada por padrão**. Mover dinheiro sozinho não pode ser comportamento herdado por quem apenas atualizou o pacote:

```env
API_KEY_REFUND_WINDOW_DAYS=7          # 0 desliga
API_KEY_REFUND_MAX_USAGE_PERCENT=50
```

Com a política ligada, cancelar concede estorno quando **as duas** comportas passam:

1. **Dentro da janela** — contada da *primeira* vez que o cliente contratou aquele plano, não de cada renovação. Contar do ciclo corrente reabriria o direito todo mês, e daria para assinar, usar abaixo do teto e cancelar no último dia, indefinidamente.
2. **Abaixo do teto de consumo** — `requests_used` sobre o `request_limit` do plano. Sem essa comporta a janela sozinha entrega o produto de graça: bastaria esgotar a cota nos primeiros dias e pedir o dinheiro de volta. Plano ilimitado (`request_limit = 0`) não tem percentual a exceder, então a comporta não se aplica.

Concedido o estorno, o valor devolvido é o `payment_amount` — o que saiu do cartão, já considerando cupom e crédito de troca — e **o acesso é encerrado na hora**. Manter o período depois de devolver o dinheiro seria entregá-lo de graça.

Se o gateway recusar o estorno, o cancelamento continua valendo e o acesso **não** é revogado: tirar o acesso sem ter devolvido o dinheiro é o pior desfecho possível. A devolução vira tarefa do painel admin, e o log carrega o que o operador precisa.

A recusa devolve o motivo, porque `window_expired` e `usage_exceeded` levam o cliente a conclusões diferentes:

```
GET /api/v1/dashboard/signature/refund-preview
{ "eligible": true, "reason": "eligible", "amount": 149.9, "access_until": null }
```

Consulte antes de pedir confirmação. Sem isso a tela só descobre que houve estorno — e que o acesso acabou — depois do fato, e a única saída seria prometer "você não perde o acesso agora" para todo mundo, o que é falso justamente para quem tem direito à devolução.

Estorno concedido dispara `PlanRefunded`, com e-mail próprio.

### Troca de plano no meio do ciclo

Assinar um plano novo encerra a assinatura corrente e abre outra do zero. Para que os dias já pagos não sejam perdidos, o checkout credita o valor proporcional do que sobrou:

```
Plano A: R$29,90/mês, 27 de 30 dias restantes
crédito = 29,90 x 27/30 = R$26,91

Upgrade para o Plano B (R$99,00):
  cobrado    = 99,00 - 26,91 = R$72,09
  novo ciclo = 30 dias cheios
```

A ordem de aplicação é **preço → cupom → crédito**, então um cupom percentual incide sobre o preço cheio do plano, não sobre o valor já creditado. Se o crédito cobrir o total, a assinatura é ativada sem cobrança.

O crédito é proporcional à duração real da assinatura corrente (`start_date` → `end_date`), não ao ciclo nominal do plano — um período que já tinha sido encurtado por uma troca anterior é medido corretamente. Vale zero para plano gratuito, assinatura inativa ou período já vencido (inclusive dentro da carência).

O valor creditado fica registrado em `user_plans.credit_applied`. Sem essa coluna, um `payment_amount` abaixo do preço do plano seria indistinguível entre desconto de cupom e crédito de troca.

```php
$credito = $user->activePlan()->with('plan')->first()?->unusedCredit();
```

### Como a cota é contada

O `CheckRequestLimitMiddleware` **reserva** uma requisição da cota antes de repassar o request adiante, com um `UPDATE` condicional que o banco serializa. Reservar antes é o que garante que exatamente `request_limit` requisições passem, mesmo com dezenas de chamadas concorrentes — ler o contador e incrementar depois deixava todas elas enxergarem o mesmo valor e estourarem o limite.

O que conta e o que não conta:

| Resultado | Consome cota? |
|---|---|
| `2xx` / `3xx` | sim |
| `4xx` (requisição malformada do cliente) | **sim** — refundar tornaria a cota burlável enviando lixo |
| `429` (cota esgotada) | não — a reserva não chega a acontecer; a requisição só entra no log |
| `5xx` ou exceção não tratada | não — a reserva é devolvida |

Ou seja: falha de servidor não é cobrada do cliente, erro do cliente é.

### Período de carência

Assinaturas expiradas entram automaticamente no período de carência. O usuário mantém o acesso enquanto o prazo corre:

```php
$userPlan = $user->activePlanWithGracePeriod()->first();

if ($userPlan?->isInGracePeriod()) {
    $dias = $userPlan->getGracePeriodRemainingDays();
}

// Ou simplesmente:
$user->hasActivePlan();     // true durante o período de carência
$user->isInGracePeriod();   // true somente durante o período de carência
```

---

## FeatureRegistry

O `FeatureRegistry` é uma forma de declarar em código quais features existem na sua aplicação. As features são registradas em PHP, persistidas automaticamente na tabela `plan_features` e expostas ao painel admin para configuração dos planos.

### Registrando features

Registre as features no `AppServiceProvider::boot()` da sua aplicação:

```php
use RiseTechApps\ApiKey\Facades\FeatureRegistry;

public function boot(): void
{
    FeatureRegistry::register('api_requests', [
        'name'        => 'Requisições via API',
        'description' => 'Permite consumo via chave de API',
        'icon'        => 'ph-key',
    ]);

    FeatureRegistry::register('exportar_csv', [
        'name'        => 'Exportar CSV',
        'description' => 'Exportação de dados em formato CSV',
        'icon'        => 'ph-file-csv',
    ]);
}
```

### Como funciona

1. `register()` armazena os metadados em memória e auto-define um resolver no `FeatureManager`, fazendo o middleware `feature:key` funcionar imediatamente.
2. A feature é inserida/atualizada na tabela `plan_features` (falha silenciosa se a tabela ainda não existir — seguro chamar antes das migrations rodarem).
3. O painel admin busca as features em `GET /dashboard/admin/features` e as renderiza como checkboxes ao criar ou editar um plano.

### Protegendo rotas por feature

```php
// Exige que o plano ativo tenha 'exportar_csv' no array de features
Route::middleware(['api', 'plan', 'feature:exportar_csv'])->group(function () {
    Route::get('/api/v1/export', ExportController::class);
});
```

### Sincronização manual com o banco

Se as migrations rodarem depois que as features já foram registradas (ex: em um comando Artisan), force a sincronização:

```php
FeatureRegistry::sync();
```

### Métodos disponíveis

```php
FeatureRegistry::all();         // array com todas as features registradas
FeatureRegistry::get('key');    // metadados de uma feature específica (ou null)
FeatureRegistry::keys();        // array com todas as chaves registradas
FeatureRegistry::has('key');    // bool
FeatureRegistry::sync();        // upsert de todas as features no banco
```

> **Atenção:** O `FeatureRegistry` usa sua própria tabela `plan_features` e não conflita com o `laravel/pennant`, que utiliza a tabela `features`.

---

## MercadoPago

### Configuração

Adicione ao `.env` da aplicação:

```env
MP_PUBLIC_KEY=APP_USR-...
MP_ACCESS_TOKEN=APP_USR-...
MP_WEBHOOK_SECRET=seu-webhook-secret
```

> **Não adicione** `VITE_MP_PUBLIC_KEY`. A chave pública é entregue ao frontend pelo endpoint autenticado `/auth/me` (campo `mp_public_key`), funcionando corretamente com os assets pré-compilados da SPA sem precisar de variável de build.

### Webhook e IPN

Cadastre a URL na sua conta do MercadoPago e informe a mesma em `MP_NOTIFICATION_URL`:

```
https://seudominio.com/api/v1/dashboard/checkout/webhook
```

```env
MP_WEBHOOK_SECRET=seu-webhook-secret
MP_NOTIFICATION_URL=https://seudominio.com/api/v1/dashboard/checkout/webhook
MP_ACCEPT_IPN=true
```

`MP_NOTIFICATION_URL` vai no corpo de **cada pagamento**, no campo `notification_url`. A revisão de qualidade da integração do MercadoPago cobra esse campo na requisição — cadastrar a URL apenas no painel não satisfaz a checagem.

> **Deixe vazio em desenvolvimento.** O gateway valida a URL e recusa a criação do pagamento se ela não for HTTPS acessível publicamente, então apontar para `localhost` derruba todo o checkout local. O campo só é enviado quando definido.

O MercadoPago entrega em **dois formatos**, e o endpoint aceita os dois:

| | Webhook | IPN |
|---|---|---|
| Corpo | `{type, data:{id}}` | `{resource, topic}` |
| Query | — | `?topic=payment&id=…` |
| Assinatura | `x-signature` + `x-request-id` | nenhuma, por definição |

O caminho é escolhido pela presença do header `x-signature`. Assinado segue com validação HMAC, e segredo ausente fecha a porta: um endpoint que aceita qualquer assinatura é o mesmo que não ter assinatura. Assinatura presente e inválida continua sendo recusa.

O IPN chega justamente porque o pagamento carrega `notification_url`, e **não tem HMAC a conferir**. Quem verifica é o pacote, indo buscar o pagamento na API com o seu access token — só pagamentos da sua própria conta produzem efeito. Como é verificação mais fraca, dá para recusar o formato com `MP_ACCEPT_IPN=false`; nesse caso remova também o `notification_url`, senão o gateway reentrega indefinidamente contra um 400.

### Pagamentos em análise

Quando o gateway responde `pending` ou `in_process`, não há assinatura ainda: ele decide depois e avisa pelo webhook. O pacote grava a espera em `pending_payments` — usuário, plano, valor, cupom reservado e `payment_id`.

- **Aprovado depois** → assina o plano e registra o rastro do pagamento
- **Recusado depois** → devolve a reserva do cupom e dispara `PaymentRejected`, cumprindo o "você será notificado em breve" que o checkout prometeu

Sem esse registro a recusa não teria a quem se referir: o comprador não seria avisado e a vaga do cupom, tomada antes de falar com o gateway, ficaria queimada sem venda.

O webhook falha de formas fora do controle do pacote — servidor fora do ar na entrega, segredo trocado, `notification_url` ausente. Por isso `api-key:reconcile-payments` roda a cada 15 minutos, consulta o gateway sobre esperas mais antigas que isso e aplica **o mesmo desfecho** que o webhook aplicaria. Sem ele, um pagamento pendente sem webhook ficaria pendente para sempre — dinheiro cobrado sem serviço entregue.

Renovações automáticas que caem em análise também são registradas, e a notificação de recusa usa texto próprio: ali a assinatura existe e apenas não vai continuar.

### Identificação de dispositivo

`POST /dashboard/checkout/process` e `POST /dashboard/cards` aceitam `device_id`, repassado ao gateway no header `X-meli-session-id`. É sinal de antifraude: sem ele a análise de risco decide com menos informação e recusas `cc_rejected_high_risk` em cartão legítimo ficam mais frequentes.

A SPA do pacote já coleta: `resources/js/mercadopago-device.ts` injeta o `security.js` do MercadoPago e lê `window.MP_DEVICE_SESSION_ID`. É melhor esforço com limite de 5 segundos — bloqueador de script ou rede lenta não podem impedir alguém de pagar.

Em frontend próprio, colete e envie o campo:

```html
<script src="https://www.mercadopago.com/v2/security.js" view="checkout" output="MP_DEVICE_SESSION_ID"></script>
```

### Idempotência das cobranças

`POST /dashboard/checkout/process` e `POST /dashboard/cards` aceitam um campo opcional `idempotency_key`, repassado ao MercadoPago no header `X-Idempotency-Key`. Chave repetida devolve o pagamento original em vez de criar um segundo.

**Envie o seu.** Gere um valor uma única vez por tentativa de compra e reutilize em toda re-submissão:

```js
const idempotencyKey = crypto.randomUUID();   // uma vez, ao abrir o checkout

await axios.post('/dashboard/checkout/process', { plan_id, token, idempotency_key: idempotencyKey, ... });
```

O caso perigoso não é o usuário desatento, é a rede: a cobrança é aceita, a resposta se perde num timeout, e ninguém consegue distinguir "não aconteceu" de "aconteceu e a resposta sumiu". O comprador olhando o spinner clica de novo e paga duas vezes.

Sem o campo, o pacote deriva uma chave de quem paga, o quê, quanto e com qual token de cartão. Como o token do MercadoPago é de uso único, um retry que reaproveita o mesmo token é reconhecido como a mesma tentativa — mas uma re-tokenização vira cobrança nova. Por isso o cliente deve mandar a sua.

### Cartões salvos

Os dados do cartão são tokenizados via MercadoPago Secure Fields (iframes) diretamente no navegador — números de cartão nunca chegam ao seu servidor. A tokenização do CVV de cartões salvos também ocorre no frontend via `mp.createCardToken()`.

---

## Sistema de Cupons

```php
use RiseTechApps\ApiKey\Models\Coupon\Coupon;

$coupon = Coupon::create([
    'code'       => 'LANCAMENTO50',
    'type'       => 'percentage', // 'percentage' ou 'fixed'
    'value'      => 50,
    'max_uses'   => 200,
    'expires_at' => now()->addMonth(),
]);

if ($coupon->isValid()) {
    // aplicar desconto no checkout
}
```

---

## Eventos e Notificações

O pacote dispara eventos automaticamente e **já registra os listeners** que enviam notificações por e-mail em português (pt-BR). Não é preciso configurar nada para recebê-las.

### Eventos disponíveis

| Evento | Quando dispara |
|--------|----------------|
| `PlanChanged` | Assinatura ativada ou plano alterado |
| `PlanGracePeriodStarted` | Plano expira e entra no período de carência |
| `PlanExpired` | Período de carência encerrado (acesso suspenso) |
| `PlanUsageThresholdReached` | Uso atinge o limiar de aviso (padrão 80%) |
| `RequestLimitReached` | Limite de requisições do plano atingido (100%) |
| `UserStatusChanged` | Status do usuário alterado |
| `ApiKeyCreated` / `ApiKeyStatusChanged` | API key criada / ativada-desativada |
| `PlanCancelled` | Assinante cancelou a renovação (nada foi retirado ainda) |
| `PlanRefunded` | Valor devolvido e acesso encerrado no mesmo ato |
| `PaymentRejected` | Compra que estava em análise acabou recusada |

### Notificações embutidas (e-mail, pt-BR)

| Notificação | Gatilho | Throttle |
|-------------|---------|----------|
| `EmailVerifyNotification` | Registro / login sem verificação | — |
| `ResetPasswordNotification` | `forgot-password` | — |
| `PlanActivatedNotification` | `PlanChanged` | 1 por assinatura |
| `UsageThresholdNotification` | `PlanUsageThresholdReached` | 1 por período do plano |
| `RequestLimitReachedNotification` | `RequestLimitReached` | 1 a cada 24h |
| `GracePeriodStartedNotification` | `PlanGracePeriodStarted` | — |
| `PlanExpiredNotification` | `PlanExpired` | — |

> O aviso de uso dispara no limiar definido por `API_KEY_USAGE_WARNING_THRESHOLD` (padrão `80`%). Requisições bloqueadas por limite (429) são **registradas no log, mas não contam** na cota.

### Personalizando notificações

Para trocar qualquer notificação pela sua, aponte a chave correspondente no mapa `notifications` da config para a **sua** classe. Ela deve manter a mesma assinatura de construtor (pode estender a do pacote e sobrescrever apenas o `toMail()`):

```php
// config/api-key.php
'notifications' => [
    'plan_expired' => \App\Notifications\MeuPlanoExpirou::class,
    // as demais permanecem com o padrão do pacote
],
```

```php
namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use RiseTechApps\ApiKey\Notifications\PlanExpiredNotification as Base;

class MeuPlanoExpirou extends Base
{
    #[\Override]
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Seu plano acabou')
            ->line("O plano {$this->plan->name} expirou.")   // args herdados do construtor
            ->action('Renovar', url('/planos'));
    }
}
```

Assinaturas de construtor de cada chave:

| Chave | Construtor |
|-------|------------|
| `email_verify` | `()` |
| `reset_password` | `($token)` |
| `plan_activated` | `($plan, $userPlan, $previousPlan = null)` |
| `usage_threshold` | `($plan, $userPlan, $used, $limit, $threshold)` |
| `limit_reached` | `($plan, $userPlan, $used, $limit)` |
| `grace_period` | `($plan, $userPlan, $graceDays, $graceEndDate)` |
| `plan_expired` | `($plan, $userPlan)` |
| `plan_cancelled` | `($plan, $userPlan)` |
| `plan_refunded` | `($plan, $userPlan, $amount)` |
| `payment_rejected` | `($plan, $amount, $reason = null, $isRenewal = false)` |

### Ouvindo eventos você mesmo

Você também pode adicionar seus próprios listeners (rodam **junto** com os do pacote):

```php
// app/Providers/EventServiceProvider.php

use RiseTechApps\ApiKey\Events\PlanChanged;
use RiseTechApps\ApiKey\Events\UserStatusChanged;

protected $listen = [
    PlanChanged::class => [
        \App\Listeners\BoasVindasNovoAssinante::class,
    ],
    UserStatusChanged::class => [
        \App\Listeners\AuditoriaStatusUsuario::class,
    ],
];
```

---

## Referência de Middlewares

| Alias | Classe | Descrição |
|-------|--------|-----------|
| `api.key` | `AuthenticateApiKey` | Valida a API key do header `X-API-KEY` |
| `check.active.plan` | `CheckActivePlanMiddleware` | Exige assinatura ativa ou em período de carência |
| `check.limit.plan` | `CheckRequestLimitMiddleware` | Rejeita requisições acima do limite do plano |
| `api.key.origin` | `ApiKeyOriginValidatorMiddleware` | Valida o header `Origin` contra as origens permitidas |
| `language` | `LanguageMiddleware` | Define o locale a partir do `Accept-Language` (`pt-BR` → `pt`) |
| `admin` | `AdminMiddleware` | Exige `role = admin` |
| `feature` | `CheckPlanFeatureMiddleware` | Exige feature específica no plano atual; aplica automaticamente `check.limit.plan` (log + contagem) |
| `plan` | *(grupo)* | Combina `api.key + check.active.plan + check.limit.plan + api.key.origin + language` |

---

## Comandos Artisan

```bash
# Verificar todos os planos e disparar eventos de expiração/carência
php artisan apikey:check-expired

# Verificar apenas planos em período de carência
php artisan apikey:check-expired --grace-only

# Processar renovações agendadas (roda automaticamente todo dia às 08:00)
# Cada assinatura vira um ProcessPlanRenewalJob; use --dry-run para listar sem cobrar
php artisan billing:process-renewals

# Apagar logs de requisição além da janela de retenção (roda automaticamente às 03:30)
php artisan api-key:prune-logs
php artisan api-key:prune-logs --dry-run          # apenas relata quantas linhas seriam apagadas
php artisan api-key:prune-logs --days=30          # sobrescreve a retenção configurada

# Diagnosticar a instalação: tabelas, credenciais do gateway, fila do log,
# supervisors do Horizon, agendador e chaves legadas
php artisan api-key:check

# Promover um usuário a admin
php artisan apikey:make-admin {email}

# Resolver compras que ficaram em análise e cujo webhook não chegou
# (roda automaticamente a cada 15 minutos)
php artisan api-key:reconcile-payments
php artisan api-key:reconcile-payments --dry-run          # consulta o gateway e relata, sem aplicar
php artisan api-key:reconcile-payments --minutes=30       # só esperas mais antigas que isso
php artisan api-key:reconcile-payments --limit=50         # teto por execução

# Reprocessar estornos pendentes da validação de cartão
# (roda automaticamente de hora em hora)
php artisan api-key:retry-validation-refunds
php artisan api-key:retry-validation-refunds --dry-run    # lista as pendências
php artisan api-key:retry-validation-refunds --amount=5.00

# Emitir novas API keys em lote
php artisan api-key:rotate-keys --legacy --output=keys.csv   # só o backlog v1 -> v2 (lookup_hash NULL)
php artisan api-key:rotate-keys --user=cliente@exemplo.com   # um dono só, imprime a key no console
php artisan api-key:rotate-keys --all --output=keys.csv      # todas as keys ativas
php artisan api-key:rotate-keys --legacy --dry-run           # apenas conta quantas seriam trocadas
```

> **Rotação é destrutiva.** No instante em que a key é trocada, todo cliente que
> ainda usa a antiga passa a receber 401. A key nova existe em texto puro uma única
> vez, durante a execução do comando — por isso ele **recusa** rotacionar mais de uma
> key sem `--output`, e exige exatamente um seletor (`--legacy`, `--user=` ou `--all`),
> para que uma flag digitada errado não revogue a instalação inteira. O CSV gerado dá
> acesso total à API: distribua e apague. O pacote **não notifica** ninguém sobre a
> troca — avisar os donos é com você.

> **Filas e agendamento.** Listeners de notificação, o log de requisições (quando
> `request_log.queue` está definido) e o billing rodam em fila. Para os ganhos de
> latência, mantenha um worker ativo (`php artisan queue:work` / Horizon) na
> conexão `queue.connection` (padrão `redis`). Sem worker de fila: use
> `QUEUE_CONNECTION=sync` **ou** deixe `API_KEY_LOG_QUEUE` nulo (o log volta a
> gravar via `afterResponse`).
>
> **O log de requisições exige worker por padrão.** `API_KEY_LOG_QUEUE` vem com
> `logs` e `API_KEY_LOG_CONNECTION` com `redis`, então sem um worker consumindo
> essa fila os jobs se acumulam e o histórico fica vazio. A falha é silenciosa:
> a contagem de cota é síncrona e continua correta, então o dashboard mostra o
> consumo certo enquanto a tabela `request_logs` não recebe nada.
>
> ```bash
> php artisan queue:work redis --queue=logs   # ou Horizon observando a fila
> php artisan queue:monitor logs              # conferir acúmulo
> ```
>
> Sem worker, defina `API_KEY_LOG_QUEUE=` (vazio) e o log passa a gravar no
> próprio processo após a resposta.
>
> **O `schedule:run` no cron não é opcional.** Sem ele a reconciliação de
> pagamentos não roda, e uma compra que ficar em análise sem receber webhook fica
> pendente indefinidamente — dinheiro cobrado sem assinatura entregue. Também
> param a cobrança de renovações, a poda de logs e o reprocessamento de estornos.

### Rotinas agendadas

| Comando | Frequência | Desligável por |
|---------|------------|----------------|
| `billing:process-renewals` | diária, 08:00 | — |
| `api-key:prune-logs` | diária, 03:30 | `request_log.prune_enabled` |
| `api-key:reconcile-payments` | a cada 15 min | `reconciliation.payments_enabled` |
| `api-key:retry-validation-refunds` | de hora em hora | `reconciliation.validation_refunds_enabled` |

Todas com `withoutOverlapping()` e `onOneServer()`, com saída em log próprio dentro de `storage/logs`.

### Filas usadas pelo pacote

Três destinos diferentes, e é importante saber quais são porque **um worker só na fila `default` não cobre todos**:

| O que é enfileirado | Conexão | Fila | Padrão |
|---------------------|---------|------|--------|
| Log de cada requisição | `API_KEY_LOG_CONNECTION` | `API_KEY_LOG_QUEUE` | `redis` / **`logs`** |
| Listeners de notificação | `API_KEY_QUEUE_CONNECTION` | `API_KEY_QUEUE_NAME` | `redis` / `default` |
| Renovação de assinatura | conexão default do app | `API_KEY_BILLING_QUEUE` | default / `default` |

### Usando com Horizon

O pacote funciona com Horizon sem adaptação, **desde que os supervisors observem as filas acima**. O Horizon processa apenas o que está declarado em `config/horizon.php`; jobs enviados a uma fila não declarada ficam acumulados sem que nada acuse erro.

O caso que morde na prática é o log: ele vai para `logs`, que a configuração padrão do Horizon **não** observa. O resultado é o dashboard mostrando o consumo correto — a contagem é síncrona — enquanto o histórico de requisições fica permanentemente vazio.

```php
// config/horizon.php

'defaults' => [
    'supervisor-1' => [
        'connection' => 'redis',
        'queue'      => ['default', 'logs'],   // 'logs' precisa estar aqui
        'balance'    => 'auto',
        // ...
    ],
],
```

Se preferir isolar o log, o que faz sentido porque ele é alto volume e baixa prioridade, use um supervisor próprio:

```php
'supervisor-logs' => [
    'connection' => 'redis',
    'queue'      => ['logs'],
    'balance'    => 'simple',
    'maxProcesses' => 2,
],
```

Para conferir se há acúmulo:

```bash
php artisan queue:monitor logs
```

Quem não usa Horizon nem worker algum: defina `API_KEY_LOG_QUEUE=` (vazio) e o log passa a gravar no próprio processo, após a resposta.

---

## Referência de Configuração

```php
// config/api-key.php

return [
    'grace_period_days' => 3,

    'rate_limit' => [
        'cache_ttl' => 3600,
    ],

    'request_limit' => [
        'warning_threshold' => 80,   // % de uso que dispara o e-mail de aviso (0 = desliga)
    ],

    // Janela de arrependimento no cancelamento. window_days = 0 DESLIGA o
    // estorno automático (padrão). Veja "Reembolso no cancelamento".
    'refund' => [
        'window_days'       => 0,
        'max_usage_percent' => 50,
    ],

    // Rotinas que fecham o que o gateway deveria ter avisado e não avisou.
    // Dependem do schedule:run da aplicação estar ativo.
    'reconciliation' => [
        'payments_enabled'            => true,   // api-key:reconcile-payments, a cada 15 min
        'validation_refunds_enabled'  => true,   // api-key:retry-validation-refunds, de hora em hora
    ],

    'cache' => [
        'enabled' => true,
        'ttl'     => 300,       // segundos — cache geral de API key
        'prefix'  => 'api_key_',
    ],

    'cache_ttl' => [
        'validation' => 300,    // cache de validação de API key
        'origin'     => 60,     // cache de validação de origem
        'invalid'    => 30,     // cache negativo p/ keys rejeitadas
        'stats'      => 30,     // endpoint /dashboard/stats
    ],

    // Secret p/ derivar o lookup_hash (busca O(1) de API key). Padrão: app.key.
    'lookup_secret' => env('API_KEY_LOOKUP_SECRET'),

    // Orçamento de tempo do fallback de keys legadas (sem lookup_hash). 0 = sem limite.
    'legacy_scan' => [
        'max_seconds' => 3,
    ],

    // Retenção e gravação do log de requisições.
    'request_log' => [
        'retention_days' => 90,     // api-key:prune-logs apaga registros mais antigos (0 = manter tudo)
        'prune_enabled'  => true,
        'prune_chunk'    => 5000,
        'queue'          => env('API_KEY_LOG_QUEUE', 'logs'),        // EXIGE worker nesta fila; vazio = grava afterResponse
        'connection'     => env('API_KEY_LOG_CONNECTION', 'redis'),  // conexão da fila (Horizon observa redis)
    ],

    // Conexão/fila dos listeners e notificações enfileirados (Horizon).
    'queue' => [
        'connection' => env('API_KEY_QUEUE_CONNECTION', 'redis'),
        'name'       => env('API_KEY_QUEUE_NAME'),   // null = fila default da conexão
    ],

    // Fila do comando billing:process-renewals (null = fila default).
    'billing' => [
        'queue' => env('API_KEY_BILLING_QUEUE'),
    ],

    'disable_web_middleware' => [
        'enabled' => true,      // desabilitado automaticamente quando spa.enabled = true
    ],

    'auth_throttle' => [
        'enabled'       => true,
        'attempts'      => 5,
        'decay_minutes' => 1,
    ],

    'header_name'      => 'X-API-KEY',
    'default_language' => 'pt',     // 'pt' ou 'en'

    'routes' => [
        'enabled' => true,
        'prefix'  => '',
    ],

    'middleware_group' => [
        'plan' => [
            'api.key',
            'check.active.plan',
            'check.limit.plan',
            'api.key.origin',
            'language',
        ],
    ],

    'mercadopago' => [
        'public_key'     => env('MP_PUBLIC_KEY'),
        'access_token'   => env('MP_ACCESS_TOKEN'),
        'webhook_secret' => env('MP_WEBHOOK_SECRET'),

        // Enviada em notification_url a cada pagamento. Deixe NULA em
        // desenvolvimento: o gateway recusa URL que não seja HTTPS pública.
        'notification_url' => env('MP_NOTIFICATION_URL'),

        // Aceitar o formato IPN, que chega sem assinatura. Desligue apenas se
        // você também remover o notification_url.
        'accept_ipn' => env('MP_ACCEPT_IPN', true),
    ],

    'demo_user_id'   => env('API_KEY_DEMO_USER_ID'),
    'internal_token' => env('API_INTERNAL_TOKEN'),

    // Mapa de notificações — aponte qualquer chave para a sua classe para
    // personalizar (mantendo a assinatura de construtor). Veja "Eventos e Notificações".
    'notifications' => [
        'email_verify'    => \RiseTechApps\ApiKey\Notifications\EmailVerifyNotification::class,
        'reset_password'  => \RiseTechApps\ApiKey\Notifications\ResetPasswordNotification::class,
        'plan_activated'  => \RiseTechApps\ApiKey\Notifications\PlanActivatedNotification::class,
        'usage_threshold' => \RiseTechApps\ApiKey\Notifications\UsageThresholdNotification::class,
        'limit_reached'   => \RiseTechApps\ApiKey\Notifications\RequestLimitReachedNotification::class,
        'grace_period'    => \RiseTechApps\ApiKey\Notifications\GracePeriodStartedNotification::class,
        'plan_expired'    => \RiseTechApps\ApiKey\Notifications\PlanExpiredNotification::class,
        'plan_cancelled'  => \RiseTechApps\ApiKey\Notifications\PlanCancelledNotification::class,
        'plan_refunded'   => \RiseTechApps\ApiKey\Notifications\PlanRefundedNotification::class,
        'payment_rejected' => \RiseTechApps\ApiKey\Notifications\PaymentRejectedNotification::class,
    ],

    'spa' => [
        'enabled' => false,
    ],
];
```

### Variáveis de Ambiente

| Variável | Descrição | Padrão |
|----------|-----------|--------|
| `API_KEY_GRACE_PERIOD_DAYS` | Dias de carência após expiração do plano | `3` |
| `API_KEY_CACHE_ENABLED` | Habilitar cache de API key | `true` |
| `API_KEY_CACHE_TTL` | TTL do cache geral (segundos) | `300` |
| `API_KEY_CACHE_TTL_VALIDATION` | TTL do cache de validação (segundos) | `300` |
| `API_KEY_CACHE_TTL_ORIGIN` | TTL do cache de origem (segundos) | `60` |
| `API_KEY_CACHE_TTL_INVALID` | TTL do cache negativo p/ keys rejeitadas (segundos) | `30` |
| `API_KEY_CACHE_TTL_STATS` | TTL do cache do endpoint `/dashboard/stats` (segundos) | `30` |
| `API_KEY_RATE_LIMIT_CACHE_TTL` | TTL do contador de rate limit (segundos) | `3600` |
| `API_KEY_USAGE_WARNING_THRESHOLD` | % de uso que dispara o e-mail de aviso (`0` desliga) | `80` |
| `API_KEY_LOOKUP_SECRET` | Secret p/ derivar o `lookup_hash` (busca O(1) de API key) | `app.key` |
| `API_KEY_LEGACY_SCAN_MAX_SECONDS` | Orçamento do fallback de keys legadas (`0` = sem limite) | `3` |
| `API_KEY_LOG_RETENTION_DAYS` | Dias de retenção do log de requisições (`0` = manter tudo) | `90` |
| `API_KEY_LOG_PRUNE_ENABLED` | Agendar `api-key:prune-logs` automaticamente | `true` |
| `API_KEY_LOG_PRUNE_CHUNK` | Tamanho do lote de exclusão do prune | `5000` |
| `API_KEY_LOG_QUEUE` | Fila do log de requisições. **Exige worker consumindo essa fila**; vazio = grava `afterResponse` | `logs` |
| `API_KEY_LOG_CONNECTION` | Conexão da fila do log (Horizon observa `redis`) | `redis` |
| `API_KEY_QUEUE_CONNECTION` | Conexão dos listeners/notificações enfileirados | `redis` |
| `API_KEY_QUEUE_NAME` | Fila dos listeners/notificações (`null` = default da conexão) | — |
| `API_KEY_BILLING_QUEUE` | Fila do comando `billing:process-renewals` | — |
| `API_KEY_DISABLE_WEB_MIDDLEWARE` | Anexar `DisableRouteWebMiddleware` ao grupo `web` | `true` |
| `API_KEY_AUTH_THROTTLE_ENABLED` | Habilitar throttle nos endpoints de autenticação | `true` |
| `API_KEY_AUTH_THROTTLE_ATTEMPTS` | Máximo de tentativas de login/registro | `5` |
| `API_KEY_AUTH_THROTTLE_DECAY` | Janela de decaimento do throttle (minutos) | `1` |
| `API_KEY_HEADER_NAME` | Header HTTP que carrega a API key | `X-API-KEY` |
| `API_KEY_DEFAULT_LANGUAGE` | Locale padrão | `pt` |
| `API_KEY_ROUTES_ENABLED` | Registrar rotas do pacote automaticamente | `true` |
| `API_KEY_ROUTES_PREFIX` | Prefixo das rotas | `''` |
| `API_KEY_SPA_ENABLED` | Servir o painel SPA Vue | `false` |
| `API_KEY_DEMO_USER_ID` | `authentication.id` para o endpoint de demonstração pública | — |
| `API_INTERNAL_TOKEN` | Token secreto para chamadas servidor-a-servidor | — |
| `MP_PUBLIC_KEY` | Chave pública do MercadoPago | — |
| `MP_ACCESS_TOKEN` | Access token do MercadoPago | — |
| `MP_WEBHOOK_SECRET` | Secret HMAC do webhook do MercadoPago | — |
| `MP_NOTIFICATION_URL` | URL enviada em `notification_url` a cada pagamento. Vazia em desenvolvimento | — |
| `MP_ACCEPT_IPN` | Aceitar notificações IPN, que chegam sem assinatura | `true` |
| `API_KEY_REFUND_WINDOW_DAYS` | Dias de janela para estorno no cancelamento (`0` = desliga) | `0` |
| `API_KEY_REFUND_MAX_USAGE_PERCENT` | Teto de consumo do ciclo que ainda dá direito a estorno | `50` |
| `API_KEY_RECONCILE_PAYMENTS_ENABLED` | Agendar a reconciliação de pagamentos em análise | `true` |
| `API_KEY_RETRY_VALIDATION_REFUNDS_ENABLED` | Agendar o reprocessamento de estornos de validação | `true` |

---

## Referência de Tags de Publicação

| Tag | O que publica | Quando usar |
|-----|---------------|-------------|
| `api-key-migrations` | Migrations do banco de dados | Sempre |
| `api-key-config` | `config/api-key.php` | Para alterar valores de configuração |
| `api-key-lang` | Arquivos de tradução em `resources/lang/vendor/api-key/` | Para sobrescrever mensagens |
| `api-key-assets` | SPA pré-compilada em `public/vendor/api-key/` | Modo B (SPA habilitada) |
| `api-key-views` | Shell Blade `app.blade.php` | Para customizar o `<head>` HTML |
| `api-key-frontend` | Arquivos-fonte Vue em `resources/js/` e `resources/css/` | Customização nível 2 |
| `api-key-build` | `package.json`, `vite.config.ts`, `tsconfig.json` | Customização nível 2 |

---

## Customização

### Nível 1 — Configuração e sobrescritas (sem Node.js)

Tudo que pode ser alterado sem tocar no código Vue ou Blade:

- **Valores de config** — publique `api-key-config` e edite `config/api-key.php`
- **Mensagens de tradução** — publique `api-key-lang` e edite os arquivos PHP/JSON
- **Shell Blade** — publique `api-key-views` para alterar título, meta tags, fontes ou injetar scripts
- **Grupo de middlewares** — reordene ou substitua middlewares em `middleware_group.plan`
- **Notificações** — substitua qualquer notificação pela sua no mapa `notifications` da config (mantendo a assinatura de construtor)
- **Eventos** — registre seus próprios listeners para `PlanChanged`, `UserStatusChanged`, etc.

### Nível 2 — Customização completa do frontend (Node.js necessário)

Publique o código-fonte Vue e o build config, depois trabalhe diretamente no frontend:

```bash
# 1. Publicar os arquivos-fonte Vue
php artisan vendor:publish --tag="api-key-frontend"

# 2. Publicar o build config (package.json, vite.config.ts, tsconfig.json)
php artisan vendor:publish --tag="api-key-build"

# 3. Instalar dependências
npm install

# 4. Iniciar o servidor de desenvolvimento
npm run dev

# 5. Build para produção (publica em public/vendor/api-key/ automaticamente via vite.config.ts)
npm run build
```

Após executar `npm run build`, rode `php artisan view:clear` se o painel não refletir as mudanças imediatamente.

---

## Testes

```bash
# Rodar todos os testes
vendor/bin/pest

# Filtrar por suite
vendor/bin/pest --filter="UserPlan"

# Relatório de cobertura
vendor/bin/pest --coverage
```

Habilite o SQLite no `php.ini` para o banco de testes:

```ini
extension=pdo_sqlite
extension=sqlite3
```

---

## Segurança

- API keys são armazenadas como hashes bcrypt
- Endpoints de autenticação têm rate limiting por padrão
- Header de origem é validado por API key
- Redefinição de senha usa o mecanismo de URL assinada do Laravel
- Assinaturas de webhook são verificadas via HMAC

Reporte problemas de segurança para apps@risetech.com.br em vez do issue tracker público.

---

## Créditos

- [Rise Tech](https://github.com/risetechapps)
- [Todos os contribuidores](../../contributors)

## Licença

The MIT License (MIT). Consulte o [arquivo de licença](LICENSE.md) para mais informações.
