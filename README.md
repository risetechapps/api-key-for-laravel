# risetechapps/api-key-for-laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/risetechapps/api-key-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/api-key-for-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/risetechapps/api-key-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/api-key-for-laravel)
[![GitHub Actions](https://github.com/risetechapps/api-key-for-laravel/actions/workflows/main.yml/badge.svg)](https://github.com/risetechapps/api-key-for-laravel/actions)
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
- **Gateways de pagamento** — Mercado Pago, Stripe, Efí e PagBank (e Pagar.me para contas existentes), um ativo por vez e trocado pelo painel admin; cartões salvos com cobrança sem CVV, webhooks assinados conferidos na API do gateway, idempotência e estornos. No Mercado Pago, checkout com Secure Fields, IPN e identificação de dispositivo
- **Pagamentos em análise** — compras que o gateway retém são registradas e resolvidas por webhook ou por reconciliação agendada
- **Logs em banco** — registros gravados pelo monitoring, com tela administrativa de consulta e filtros
- **Diagnóstico da instalação** — `api-key:check` aponta fila sem worker, cron parado e credenciais faltando
- **Painel SPA Vue 3** — assets pré-compilados, sem necessidade de Node.js no servidor host
- **Idioma por superfície** — painel e rotas embutidas sempre em português (pt-BR); API do cliente (grupo `plan`) sempre em inglês, com `error_code` estável
- **Camada de Cache** — suporte a Redis/Memcached para validação de API keys
- **Várias API keys por conta** — nome, permissões por feature, origens, validade e limite por minuto em cada chave
- **Limite por minuto** — por chave ou por plano, além da cota do período
- **Cota visível para o cliente** — headers `X-Quota-*` em toda resposta, `GET /usage` com a própria API key e especificação OpenAPI da API do cliente
- **PIX e cartão** — PIX no checkout com QR code e confirmação automática; renovação e excedente sem cobrança automática são pagos pelo painel, nunca por e-mail
- **Novas tentativas de renovação** — cartão recusado na renovação é cobrado de novo em dias configuráveis, com aviso por e-mail
- **Período de teste** — dias grátis por plano, um teste por conta
- **Cobrança por excedente** — plano pode seguir atendendo além do limite e cobrar o excedente no fim do período
- **Recibos** — comprovante de cada pagamento, pronto para imprimir ou salvar em PDF
- **Webhooks de saída** — eventos da conta assinados com HMAC e enviados ao sistema do cliente, com novas tentativas e histórico
- **Segurança da conta** — troca de senha e de e-mail, sessões abertas, verificação em duas etapas (TOTP, obrigatória para admin)
- **Auditoria e métricas** — trilha de quem fez o quê e painel com MRR, cancelamentos, conversão de teste e receita
- **Suite de Testes** — testes Pest, análise estática com Larastan e estilo com Pint no CI

## Requisitos

- PHP ^8.4
- Laravel ^12
- Laravel Sanctum ^4.0
- `risetechapps/monitoring-for-laravel` ^1.0 — armazena os logs do pacote em banco; instalado automaticamente pelo Composer

---

## Instalação

> Atualizando de uma versão anterior? Siga o [UPGRADE.md](UPGRADE.md).

Parte das dependências (`address-for-laravel`, `code-generate`, `form-request-for-laravel`, `media-for-laravel`, `monitoring-for-laravel`, `repository-for-laravel`, `to-upper-for-laravel`, `view-suite`) é distribuída pelo Repman da RiseTech, não pelo Packagist. O Composer não herda `repositories` de dependências, então o projeto que instala o pacote precisa declarar o repositório e a credencial:

```bash
composer config repositories.risetech composer https://risetechapps-repo-repman.risetech.app.br
composer config --global http-basic.risetechapps-repo-repman.risetech.app.br token <SEU_TOKEN>
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

Isso **copia** os arquivos pré-compilados do `dist/` para `public/vendor/api-key/`.

> **Publicação é cópia, não link.** O blade carrega `asset('vendor/api-key/app.js')`, ou seja, a cópia em `public/` — nunca o `dist/` do pacote. Toda atualização do pacote, e todo rebuild seu do frontend, exige republicar:
>
> ```bash
> php artisan vendor:publish --tag="api-key-assets" --force
> ```
>
> Sem o `--force` o Laravel não sobrescreve o que já existe, e o painel continua o antigo sem nenhum aviso. O sintoma é uma mudança de tela que "não aparece" — item de menu novo que não surge, correção de formulário que não pega — enquanto o backend já responde com o comportamento novo.
>
> Em desenvolvimento, um link simbólico elimina o passo:
>
> ```bash
> # Linux/macOS
> ln -s ../../vendor/risetechapps/api-key-for-laravel/dist public/vendor/api-key
>
> # Windows, com privilégio de criação de link
> mklink /D public\vendor\api-key ..\..\vendor\risetechapps\api-key-for-laravel\dist
> ```
>
> Não use link em produção: `public/` costuma ser servido pelo servidor web com `disable_symlinks` ligado.

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
| `GET` | `api/v1/usage` | Uso do período, autenticado pela API key (ver [Cota nas respostas e `GET /usage`](#cota-nas-respostas-e-get-usage)) |
| `GET` | `api/v1/usage/daily` | Requisições por dia, autenticado pela API key |
| `POST` | `api/v1/register` | Registrar novo usuário |
| `POST` | `api/v1/login` | Login e recebimento do token Sanctum |
| `POST` | `api/v1/logout` | Revogar token atual |
| `GET` | `api/v1/auth/me` | Obter usuário autenticado |
| `GET` | `api/v1/email/verify/{id}/{hash}` | Verificar e-mail |
| `POST` | `api/v1/email/resend` | Reenviar o e-mail de verificação |
| `POST` | `api/v1/login/two-factor` | Segundo passo do login com 2FA (`challenge` + `code` ou `recovery_code`) |
| `GET` | `api/v1/email/change/{id}/{hash}` | Link de confirmação da troca de e-mail |
| `POST` | `api/v1/forgot-password` | Enviar e-mail de redefinição de senha |
| `POST` | `api/v1/reset-password` | Redefinir senha com token |
| `GET` | `api/v1/dashboard/plans` | Listar planos disponíveis |
| `POST` | `api/v1/dashboard/checkout/process` | Processar pagamento |
| `POST` | `api/v1/dashboard/checkout/coupon` | Validar cupom |
| `POST` | `api/v1/dashboard/checkout/webhook` | Webhook do MercadoPago |
| `POST` | `api/v1/dashboard/checkout/webhook/{gateway}` | Webhook de `stripe`, `efi`, `pagbank` e `pagarme` |
| `POST` | `api/v1/dashboard/cards/setup-intent` | Stripe: abre o SetupIntent que o navegador confirma com o cartão |
| `GET` | `api/v1/dashboard/profile` | Obter perfil |
| `PUT` | `api/v1/dashboard/profile` | Atualizar perfil |
| `GET` | `api/v1/dashboard/profile/export` | Baixar todos os dados da conta em JSON (LGPD) |
| `DELETE` | `api/v1/dashboard/profile` | Excluir a própria conta; pede `password` (LGPD) e, com 2FA, `code` ou `recovery_code` |
| `PUT` | `api/v1/dashboard/profile/password` | Trocar a senha (`current_password`, `password`, `password_confirmation`; com 2FA, `code` ou `recovery_code`) |
| `POST` | `api/v1/dashboard/profile/email` | Pedir troca de e-mail (`email`, `password`; com 2FA, `code` ou `recovery_code`); vale após confirmar pelo link |
| `GET/DELETE` | `api/v1/dashboard/sessions` | Listar sessões / encerrar todas as outras |
| `DELETE` | `api/v1/dashboard/sessions/{id}` | Encerrar uma sessão |
| `POST/DELETE` | `api/v1/dashboard/two-factor` | Iniciar ativação (devolve segredo e `otpauth://`) / desativar. Os dois pedem `password`; desativar a 2FA já confirmada pede também `code` ou `recovery_code` |
| `POST` | `api/v1/dashboard/two-factor/confirm` | Confirmar com o código e receber os códigos de recuperação |
| `POST` | `api/v1/dashboard/two-factor/recovery-codes` | Gerar novos códigos de recuperação. Pede `password` e `code` ou `recovery_code` |
| `GET/POST` | `api/v1/dashboard/api-keys` | Listar / criar chave (o texto da chave só vem na criação) |
| `PUT/DELETE` | `api/v1/dashboard/api-keys/{id}` | Editar (nome, `scopes`, origens, validade, limite por minuto) / revogar |
| `POST` | `api/v1/dashboard/api-keys/{id}/regenerate` | Trocar o segredo da chave |
| `GET` | `api/v1/dashboard/api-keys/revoked` | Chaves já excluídas (id, nome, quando), para filtrar o histórico de requisições |
| `GET/POST` | `api/v1/dashboard/webhooks` | Listar / cadastrar webhook (o segredo só vem na criação) |
| `PUT/DELETE` | `api/v1/dashboard/webhooks/{id}` | Editar / excluir |
| `POST` | `api/v1/dashboard/webhooks/{id}/rotate-secret` | Novo segredo de assinatura |
| `POST` | `api/v1/dashboard/webhooks/{id}/test` | Enviar evento de teste |
| `GET` | `api/v1/dashboard/webhooks/{id}/deliveries` | Últimas 50 entregas |
| `POST` | `api/v1/dashboard/webhooks/{id}/deliveries/{deliveryId}/redeliver` | Reenviar uma entrega |
| `GET` | `api/v1/dashboard/checkout/payments/{paymentId}` | Situação de um pagamento pendente (PIX) |
| `GET/PUT` | `api/v1/dashboard/billing/overage` | Ver / alterar a escolha de excedente (`enabled`, `spend_cap` em reais); inclui os excedentes em aberto (`unpaid`) |
| `POST` | `api/v1/dashboard/billing/overage/{id}/pix` | Gerar o PIX de um excedente em aberto |
| `POST` | `api/v1/dashboard/billing/overage/{id}/card` | Pagar um excedente em aberto com um cartão salvo (`card_id`; `token` com o CVV no Mercado Pago) |
| `GET` | `api/v1/dashboard/billing/renewal` | Renovação esperando o assinante (inclusive em carência), com o plano para o checkout |
| `GET` | `api/v1/dashboard/receipts` | Recibos da conta |
| `GET` | `api/v1/dashboard/receipts/{id}` | Recibo em HTML (`?kind=overage` para o de excedente) |
| `POST` | `api/v1/dashboard/profile/regenerate-key` | **Obsoleta** (sai na próxima versão maior): regenera a chave principal. Use `dashboard/api-keys/{id}/regenerate` |
| `GET` | `api/v1/dashboard/cards` | Listar cartões salvos |
| `POST` | `api/v1/dashboard/cards` | Adicionar cartão |
| `DELETE` | `api/v1/dashboard/cards/{id}` | Remover cartão |
| `POST` | `api/v1/dashboard/signature` | Ativar plano de preço zero |
| `POST` | `api/v1/dashboard/signature/cancel` | Cancelar a renovação (estorna se a política conceder) |
| `POST` | `api/v1/dashboard/signature/resume` | Reativar a renovação antes do vencimento |
| `GET` | `api/v1/dashboard/signature/refund-preview` | O que acontece se cancelar agora: estorno, valor e até quando vai o acesso |
| `GET` | `api/v1/dashboard/history` | Histórico de assinaturas |
| `GET` | `api/v1/dashboard/log` | Log de requisições (ordenado do mais recente), com a chave que fez cada uma; `?api_key_id=` filtra por chave, inclusive excluída |
| `GET` | `api/v1/dashboard/stats` | Estatísticas agregadas do dashboard (uso, restantes, hoje) |

Rotas da equipe do painel. O middleware `admin:<permissão>` exige o papel certo (tabela abaixo); `admin` sem parâmetro vale `manage`, que só o papel `admin` tem. Toda a equipe precisa de 2FA quando `API_KEY_2FA_REQUIRED_FOR_ADMINS=true`.

| Papel (`role`) | `view` | `accounts` | `billing` | `manage` |
|---|---|---|---|---|
| `admin` | ✓ | ✓ | ✓ | ✓ |
| `finance` | ✓ | | ✓ | |
| `support` | ✓ | ✓ | | |

- `view`: usuários e ficha, cobranças, inadimplência, estornos (lista), métricas, auditoria, logs e saúde.
- `accounts`: reenviar verificação, encerrar sessões e desativar API keys de um usuário.
- `billing`: estornar, cancelar ou estender assinatura, ajustar consumo, tentar a renovação de novo, perdoar excedente e exportar CSV.
- `manage`: planos, cupons, gateways, papéis, suspender conta, conceder plano e desligar a 2FA de alguém. Desligar 2FA fica só com o admin porque é o pedido que um golpe de engenharia social faria ao suporte.

O papel é trocado em Admin → Usuários → ficha → **Alterar papel**, ou pelo `api-key:admin-make` para o primeiro admin. Ninguém muda o próprio papel, e quem perde permissões tem as sessões encerradas.

| Método | URI | Descrição |
|--------|-----|-----------|
| `GET` | `api/v1/dashboard/admin/users/{id}` | Ficha: assinaturas, pagamentos, cartões, API keys, sessões, excedente em aberto e auditoria |
| `GET` | `api/v1/dashboard/admin/payments` | Cobranças de todos os gateways, com filtros (`status`, `gateway`, `from`, `to`, `search`) e totais |
| `GET` | `api/v1/dashboard/admin/delinquency` | Renovações em nova tentativa, esperando o assinante, e excedente não pago |
| `GET` | `api/v1/dashboard/admin/export/{payments\|refunds\|users}` | CSV (separador `;`, com BOM) |
| `GET` | `api/v1/dashboard/admin/health` | Saúde: gateway, último webhook, rotinas agendadas, fila, pagamentos parados e os itens do app (`SystemHealthService::extend()`) |
| `POST` | `api/v1/dashboard/admin/users/{id}/subscriptions/{planId}/{cancel\|extend\|retry-renewal\|waive-overage}` | Ações sobre a assinatura (`extend` recebe `days`) |
| `PUT` | `api/v1/dashboard/admin/users/{id}/subscriptions/{planId}/usage` | Ajustar `requests_used` do período |
| `POST` | `api/v1/dashboard/admin/users/{id}/{verification\|sessions/revoke\|api-keys/revoke\|two-factor/reset}` | Ações sobre a conta |
| `PUT` | `api/v1/dashboard/admin/users/{id}/role` | Papel: `admin`, `finance`, `support` ou `client` |
| `GET/PUT` | `api/v1/dashboard/admin/payment-settings` | Gateway ativo e credenciais |
| `POST` | `api/v1/dashboard/admin/payment-settings/test/{gateway}` | Testar a conexão com o gateway |
| `POST/PUT/DELETE` | `api/v1/dashboard/plans/{plan}` | Criar / atualizar / excluir planos |
| `POST/PUT/DELETE` | `api/v1/dashboard/coupons/{coupon}` | Criar / atualizar / excluir cupons |
| `GET` | `api/v1/dashboard/coupons[/{coupon}]` | Listar / ver cupons (`admin:view`). O cliente nunca lista: só valida o código que tem, em `checkout/coupon` |
| `GET` | `api/v1/dashboard/admin/plans` | Listar todos os planos (visão admin) |
| `GET` | `api/v1/dashboard/admin/users` | Listar usuários com assinaturas |
| `PUT` | `api/v1/dashboard/admin/users/{id}/status` | Suspender ou reativar (`status`: `enabled`, `disabled`, `blocked`) |
| `POST` | `api/v1/dashboard/admin/users/{id}/plan` | Conceder plano sem cobrança (`plan_id`) |
| `GET` | `api/v1/dashboard/admin/refunds` | Listar pagamentos com opção de estorno |
| `POST` | `api/v1/dashboard/admin/refunds/{id}` | Processar estorno via MercadoPago |
| `GET` | `api/v1/dashboard/admin/features` | Listar features registradas (`FeatureRegistry`) |
| `GET` | `api/v1/dashboard/admin/logs` | Listar logs e exceções, com filtros |
| `GET` | `api/v1/dashboard/admin/logs/{id}` | Detalhe de um registro |
| `GET` | `api/v1/dashboard/admin/metrics` | MRR, assinantes, cancelamento, receita, conversão de teste |
| `GET` | `api/v1/dashboard/admin/audit` | Trilha de auditoria (`action`, `actor_id`, `subject_id`) |

**Itens do app na saúde do sistema.** O app acrescenta as próprias verificações (filas, latência de um canal…) num grupo próprio da mesma tela, sem mexer no pacote:

```php
use RiseTechApps\ApiKey\Services\SystemHealthService;

SystemHealthService::extend(fn () => [
    ['group' => 'WhatsApp', 'label' => 'Fila whatsapp-chat', 'status' => 'ok', 'detail' => 'Vazia.'], // status: ok | warning | error
]);
```

Registre no `boot()` de um service provider. Uma verificação que lança exceção vira um aviso na tela, sem derrubar as outras.

Com `two_factor.required_for_admins` ligado (padrão), as rotas de admin respondem 403 com `code: two_factor_required` até o admin ativar a verificação em duas etapas no perfil.

### Proteções das rotas embutidas

- **Sem enumeração de contas.** Login, esqueci a senha e reenvio de verificação respondem igual para e-mail cadastrado ou não. O login confere a senha antes de dizer que a conta está sem verificação ou suspensa.
- **Limite de tentativas.** As rotas públicas de autenticação (`register`, `login`, `forgot-password`, `reset-password`, `email/resend`) dividem o limite `auth_throttle`, por IP. Checkout, validação de cupom e cadastro de cartão dividem o `payment_throttle`, por usuário: sem ele, o cadastro de cartão, que faz uma cobrança de validação, serve para testar cartões roubados em massa. A exclusão de conta tem contador próprio. Teste e reenvio de webhook pelo painel dividem o `webhook_test_throttle` (10 por minuto, por usuário), porque cada chamada dispara uma requisição para a URL do cliente. A exportação dos dados da conta tem o `export_throttle` (5 por hora, por usuário), porque cada chamada lê a conta inteira.
- **Contas suspensas.** Com `status` diferente de `enabled`, a conta não entra no painel (middleware `active.user`, aplicado às rotas `auth:sanctum` do pacote) nem usa a API key. Suspender revoga os tokens; a chave continua a mesma e volta a funcionar ao reativar.

### Exclusão de conta e exportação de dados

`DELETE api/v1/dashboard/profile` anonimiza a conta em vez de apagar a linha: nome, e-mail, documentos, telefones, endereço, foto, cartões salvos, log de requisições, API key e tokens saem; a assinatura em curso é encerrada na hora, sem estorno. As assinaturas pagas ficam, ligadas à conta anonimizada, porque são registro de pagamento que a lei manda guardar e o que sustenta uma contestação. O e-mail original fica livre para um cadastro novo. Contas de admin não podem se excluir pelo painel.

`GET api/v1/dashboard/profile/export` devolve, em JSON, perfil, endereço, configuração da API key (sem a chave), assinaturas, cartões (só bandeira e últimos dígitos) e o log de requisições, escrito por cursor para não montar tudo na memória.

### Segurança da conta

- **Troca de senha** pede a senha atual e encerra as outras sessões; a atual continua.
- **Troca de e-mail** grava o endereço novo em `pending_email` e só troca quando o dono dele clica no link (60 minutos). O endereço antigo é avisado.
- **Sessões**: cada login grava IP e navegador no token (`personal_access_tokens.device`), e o painel lista e encerra sessões.
- **Verificação em duas etapas (TOTP)**: compatível com Google Authenticator, Authy e afins, sem dependência extra. Com 2FA, `POST login` devolve `{ two_factor: true, challenge }` em vez do token; o token vem de `POST login/two-factor`. O desafio vale 5 minutos, o mesmo código não passa duas vezes, e cada um dos 8 códigos de recuperação vale uma vez. Com a 2FA confirmada, desativá-la, gerar códigos novos, trocar senha, trocar e-mail e excluir a conta pedem `code` ou `recovery_code` além da senha. Códigos errados contam por conta, de qualquer IP, no login e nessas ações: 10 bloqueiam por 15 minutos e o dono recebe um e-mail. Segredo e códigos ficam cifrados com a `APP_KEY`.
- Toda alteração de segurança manda e-mail ao dono e entra na auditoria.

### Várias API keys

Cada conta pode ter até `api_keys.max_per_user` chaves (padrão 5), cada uma com nome, `scopes` (features que ela pode chamar; vazio = todas as do plano), origens permitidas, validade e limite por minuto próprio. `apiKey()` continua sendo a chave principal (a mais antiga) e `apiKeys()` traz todas. O campo `active` acompanha o plano: vencer desativa todas, assinar reativa todas. Revogar apaga a chave, e a última não pode ser revogada.

Chave com validade avisa o dono antes de vencer: `api-key:notify-expiring`, agendado todo dia às 07:00, manda e-mail e o webhook `api_key.expiring` (id, nome, `expires_at`, `days_left`, nunca o segredo) para cada chave ativa que vence dentro de `api_keys.expiry_warning_days` (padrão 7). Sai uma vez por chave e por data de validade: estender a validade arma o aviso de novo. Contas suspensas não recebem. `API_KEY_EXPIRY_WARNING_DAYS=0` desliga o aviso e o agendamento.

### Limite por minuto

O middleware `api.key.rate`, no grupo `plan` logo depois do `api.key`, limita requisições por minuto. O limite do plano (`rate_limit_per_minute`, ou `rate_limit.per_minute` sem ele) vale para a **conta**, somando todas as chaves. A chave pode ter limite próprio, que só aperta: acima do plano é recusado no painel e cortado no plano pelo middleware. Sem nenhum, não limita. A resposta traz `X-RateLimit-Limit` e `X-RateLimit-Remaining`; acima do limite, 429 com `Retry-After`, sem gastar a cota. Se você define `middleware_group.plan` na config, inclua `api.key.rate` na lista.

### Webhooks de saída

O cliente cadastra URLs HTTPS que recebem eventos da conta: `subscription.activated`, `subscription.changed`, `subscription.cancelled`, `subscription.expired`, `subscription.grace_period_started`, `subscription.refunded`, `subscription.renewal_failed`, `subscription.payment_required`, `payment.rejected`, `billing.overage_charged`, `usage.threshold_reached`, `usage.limit_reached`, `usage.overage_started` e `api_key.expiring`. Os de uso saem uma vez por período.

**Eventos do seu app.** Todos os webhooks do sistema passam pelo pacote, os dele e os do seu app: o cliente escolhe no mesmo painel o que quer receber, e todos saem com o mesmo envelope, a mesma assinatura, as mesmas novas tentativas e o mesmo histórico. Quem recebe valida tudo do mesmo jeito (ver "Validando as entregas", abaixo), sem precisar deste pacote.

1. **Registre** os eventos, no formato `recurso.acao` em minúsculas. A descrição é opcional e aparece no painel ao lado do nome:

```php
// AppServiceProvider::boot()
use RiseTechApps\ApiKey\Services\WebhookDispatcher;

WebhookDispatcher::registerEvents([
    'pedido.criado' => 'Pedido criado',
    'nota.emitida' => 'Nota fiscal emitida',
]);
```

Também vale listar em `config('api-key.webhooks.events')`, com ou sem descrição: `['pedido.criado' => 'Pedido criado', 'nota.emitida']`.

2. **Dispare** quando acontecer, para a conta dona do dado:

```php
WebhookDispatcher::dispatch($pedido->conta, 'pedido.criado', ['id' => $pedido->id, 'total' => $pedido->total]);
```

Só evento registrado sai. Um nome que não está no registro (um erro de digitação, um evento esquecido) não é enviado a ninguém, nem a quem marcou "Todos os eventos": o `dispatch()` devolve 0 e o erro vai para o log (`report()`). Assim o cliente só recebe o que pôde escolher. No cadastro do endpoint, um evento não registrado é recusado com 422.

3. **Documente o `data`** dos seus eventos. O envelope, os headers e a assinatura são do pacote e estão em [`docs/openapi.yaml`](docs/openapi.yaml); o conteúdo de `data` é do seu app. No OpenAPI do seu app, reaproveite as partes do pacote:

```yaml
# openapi.yaml do seu app (OpenAPI 3.1)
webhooks:
  pedido.criado:
    post:
      summary: Pedido criado
      parameters:
        - $ref: 'vendor/risetechapps/api-key-for-laravel/docs/openapi.yaml#/components/parameters/WebhookSignature'
        - $ref: 'vendor/risetechapps/api-key-for-laravel/docs/openapi.yaml#/components/parameters/WebhookTimestamp'
        - $ref: 'vendor/risetechapps/api-key-for-laravel/docs/openapi.yaml#/components/parameters/WebhookId'
        - $ref: 'vendor/risetechapps/api-key-for-laravel/docs/openapi.yaml#/components/parameters/WebhookEvent'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              allOf:
                - $ref: 'vendor/risetechapps/api-key-for-laravel/docs/openapi.yaml#/components/schemas/WebhookEnvelope'
                - type: object
                  properties:
                    event: { const: pedido.criado }
                    data:
                      type: object
                      required: [id, total]
                      properties:
                        id: { type: integer }
                        total: { type: number }
      responses:
        '2XX': { $ref: 'vendor/risetechapps/api-key-for-laravel/docs/openapi.yaml#/components/responses/WebhookAccepted' }
        default: { $ref: 'vendor/risetechapps/api-key-for-laravel/docs/openapi.yaml#/components/responses/WebhookRetried' }
```

Trate o `data` como contrato: acrescentar campo é seguro; renomear ou tirar quebra quem já recebe. Para uma mudança grande, crie um evento novo (`pedido.criado_v2`) e mantenha o antigo por um tempo.

Endereços privados, de loopback e reservados são recusados, inclusive CGNAT (`100.64.0.0/10`), NAT64/6to4/Teredo, faixas de teste e documentação e multicast, no cadastro e de novo a cada entrega (proteção contra SSRF), e redirecionamentos não são seguidos. Os eventos, os headers e o `data` de cada um estão em [`docs/openapi.yaml`](docs/openapi.yaml), na seção `webhooks`.

#### Validando as entregas (para quem recebe)

Esta parte é para o sistema do cliente, o que recebe os webhooks. Cada entrega é um `POST` com corpo JSON e estes headers:

| Header | Exemplo | Para quê |
|---|---|---|
| `X-Webhook-Signature` | `sha256=5f2b…` | Assinatura HMAC-SHA256 |
| `X-Webhook-Timestamp` | `1793276848` | Hora desta tentativa, em segundos (epoch) |
| `X-Webhook-Id` | `evt_01j…` | Id do evento, igual em todas as tentativas |
| `X-Webhook-Event` | `subscription.activated` | Nome do evento |

```json
{ "id": "evt_01j…", "event": "subscription.activated", "created_at": "2026-10-29T12:00:00-03:00", "data": { … } }
```

A assinatura é `HMAC-SHA256(segredo, timestamp + "." + corpo_bruto)`, em hexadecimal, com o prefixo `sha256=`. O segredo é o `whsec_…` mostrado uma única vez no cadastro do endpoint (ou ao girar o segredo), e entra inteiro, com o prefixo.

Para validar:

1. **Leia o corpo bruto**, exatamente como chegou. Decodificar o JSON e codificar de novo muda espaços e escapes, e a assinatura deixa de bater.
2. **Recalcule a assinatura** e compare com `X-Webhook-Signature` em tempo constante (`hash_equals`, `crypto.timingSafeEqual`), nunca com `==`.
3. **Recuse timestamp com mais de 5 minutos** de diferença: quem capturar uma entrega não consegue reenviá-la depois. Funciona porque o timestamp faz parte do que é assinado.
4. **Ignore `X-Webhook-Id` já processado.** Novas tentativas e o "Reenviar" do painel mandam o mesmo id.

Se algo falhar, responda `401` e não processe.

```php
// Laravel
public function __invoke(Request $request)
{
    $body      = $request->getContent();                                // corpo bruto
    $timestamp = (string) $request->header('X-Webhook-Timestamp');
    $received  = (string) $request->header('X-Webhook-Signature');

    $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, config('services.parceiro.webhook_secret'));

    abort_unless(hash_equals($expected, $received), 401);
    abort_if(abs(time() - (int) $timestamp) > 300, 401);                 // mais de 5 min

    // O mesmo evento pode chegar de novo.
    if (! Cache::add('webhook:'.$request->header('X-Webhook-Id'), true, now()->addDays(2))) {
        return response()->noContent();
    }

    ProcessarWebhook::dispatch($request->json()->all());                  // trabalho pesado na fila
    return response()->noContent();                                       // 2xx rápido
}
```

```js
// Node / Express: express.raw, e não express.json, para ter o corpo bruto
app.post('/webhooks', express.raw({ type: 'application/json' }), (req, res) => {
  const ts  = req.get('X-Webhook-Timestamp');
  const sig = req.get('X-Webhook-Signature') || '';
  const expected = 'sha256=' + crypto.createHmac('sha256', process.env.WEBHOOK_SECRET)
    .update(`${ts}.${req.body}`).digest('hex');

  const ok = sig.length === expected.length
    && crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected))
    && Math.abs(Date.now() / 1000 - Number(ts)) <= 300;

  if (!ok) return res.sendStatus(401);
  // conferir X-Webhook-Id já processado, enfileirar o trabalho e responder
  res.sendStatus(204);
});
```

O que mais o integrador precisa saber:

- **Responda rápido.** Só um `2xx` em até 10 segundos (`webhooks.timeout`) conta como entregue. Valide, enfileire e responda; o processamento vem depois.
- **Novas tentativas.** Qualquer outra resposta, ou falta de resposta, volta para a fila em 1 min, 5 min, 15 min, 1 h e 6 h, com o mesmo `X-Webhook-Id` e timestamp e assinatura novos. Depois de `webhooks.disable_after_failures` (20) entregas perdidas seguidas, o endpoint é desligado.
- **Girar o segredo vale na hora.** O antigo para de funcionar já na próxima entrega, sem período de convivência. Atualize o segredo no seu sistema logo depois de girar.
- **Relógio sincronizado** (NTP) no servidor que recebe, por causa da tolerância de 5 minutos.
- **Para testar**, o botão "Enviar teste" do painel manda um `webhook.test` assinado do mesmo jeito. O histórico de entregas do endpoint mostra, para cada entrega, a última resposta HTTP e o erro, se houver.

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

### Respostas de erro da API

A API do cliente responde sempre em inglês, qualquer que seja o `Accept-Language`, e com um `error_code` estável — decida pelo código, não pelo texto:

```json
{
    "success": false,
    "message": "Request limit reached for the current billing period.",
    "error_code": "REQUEST_LIMIT_REACHED"
}
```

| `error_code` | HTTP | Quando | Campos extras |
|--------------|------|--------|---------------|
| `MISSING_API_KEY` | 401 | Sem o header da chave | |
| `INVALID_API_KEY` | 401 | Chave inexistente, inativa, expirada ou sem dono | |
| `UNAUTHENTICATED` | 401 | `check.active.plan` sem usuário autenticado | |
| `FEATURE_NOT_IN_PLAN` | 402 | Plano sem nenhuma das features da rota | `features` |
| `ACCOUNT_DISABLED` | 403 | Conta suspensa | |
| `PLAN_INACTIVE` | 403 | Sem plano ativo | |
| `PLAN_EXPIRED` | 403 | Plano expirado e carência encerrada | |
| `ORIGIN_NOT_ALLOWED` | 403 | Origem/IP fora da lista da chave | |
| `API_KEY_SCOPE_DENIED` | 403 | Chave sem permissão para a feature da rota | `features` |
| `RATE_LIMITED` | 429 | Acima do limite por minuto | `retry_after` (+ header `Retry-After`) |
| `REQUEST_LIMIT_REACHED` | 429 | Cota do período esgotada | |

Os textos ficam em `lang/en/api.php` do pacote; para trocar, publique `lang/vendor/api-key/en/api.php`. O painel (SPA e rotas embutidas) responde sempre em português.

### Cota nas respostas e `GET /usage`

Toda resposta do grupo `plan`, inclusive o 429 de cota esgotada, informa a cota do período:

| Header | Valor |
|---|---|
| `X-Quota-Limit` | Requisições do período. Ausente em plano sem limite |
| `X-Quota-Remaining` | Quanto resta, já contando esta requisição. `0` no excedente. Ausente em plano sem limite |
| `X-Quota-Used` | Quanto foi usado, esta incluída. Passa do limite no excedente |
| `X-Quota-Reset` | Fim do período em Unix timestamp (segundos): o contador zera na renovação |

Os números saem depois da reserva ou da devolução da vaga, então batem com o painel: um 5xx devolvido não aparece em `X-Quota-Used`. Desligue com `API_KEY_QUOTA_HEADERS=false`.

O cliente também consulta o uso com a própria API key, sem login no painel:

```bash
curl -H "X-API-KEY: sua-api-key-aqui" https://api.seuapp.com/api/v1/usage
```

A resposta traz plano, período (`active` ou `grace_period`), requisições (`limit`, `used`, `remaining`, `resets_at`, com `null` em plano sem limite), excedente, limite por minuto e a chave usada (sem o segredo). A rota passa por `api.key`, `api.key.rate`, `check.active.plan` e `api.key.origin`, mas **não** pelo `check.limit.plan`: consultar não gasta a cota e continua respondendo com a cota esgotada. O limite por minuto vale normalmente.

O histórico por dia fica em `GET /api/v1/usage/daily?days=30` (de 1 a 90; fora disso, o valor é trazido para dentro da faixa), nas mesmas condições: não gasta a cota. Cada dia da janela aparece, os vazios com zero, dividido em `success` (2xx/3xx), `client_error` (4xx) e `server_error` (5xx), no fuso da aplicação. Conta o que foi **registrado**, e o registro inclui chamadas que não consumiram cota (o 429 de cota esgotada, os 5xx): somado, não dá o `requests.used` do `/usage`. O gráfico do painel usa a mesma consulta.

### Especificação OpenAPI

[`docs/openapi.yaml`](docs/openapi.yaml) (OpenAPI 3.1) descreve a API do cliente: autenticação por API key, `GET /usage`, os headers de cota e de limite por minuto e cada `error_code` com o status HTTP e um exemplo. As rotas do painel (`/dashboard/*`, login, checkout) ficam de fora: são o contrato interno da SPA.

O arquivo vai junto no pacote. Para documentar as suas rotas protegidas pelo `plan`, referencie os componentes a partir da sua própria especificação, sem copiá-los:

```yaml
responses:
  '429': { $ref: 'vendor/risetechapps/api-key-for-laravel/docs/openapi.yaml#/components/responses/TooManyRequests' }
```

Os testes do pacote conferem o documento contra o código: um `error_code` novo em `ApiError`, ou um campo novo no `/usage`, quebra a suíte enquanto não for documentado.

### Middleware `feature`

Restrinja uma rota a planos que tenham uma feature específica habilitada:

```php
Route::middleware(['api', 'plan', 'feature:relatorios_avancados'])->group(function () {
    Route::get('/api/v1/relatorios', ReportController::class);
});
```

---

## Equipe da conta

O dono da conta (quem assina o plano) pode ter pessoas com login próprio trabalhando na conta dele.

| Papel | Pode |
|---|---|
| `owner` (dono) | tudo, inclusive plano, cobrança e excluir a conta |
| `admin` | tudo menos plano, cobrança e excluir a conta; convida e gerencia atendentes |
| `agent` (atendente) | só as rotas que o app libera com `team.role:agent` |

O plano define quantos membros inclui além do dono (`team_members_limit`, Admin → Planos; 0 = só o dono). Convites pendentes contam no limite.

**Convite.** `POST /dashboard/team/invitations` (`name`, `email`, `role`) manda um e-mail com o link `/invite/{token}`, válido por 7 dias. A pessoa abre, define a senha e entra (`POST /team/invitations/{token}/accept` devolve o token de sessão). O e-mail não pode ter conta. Admin convida, altera e remove atendentes; admins, só o dono. Remover um membro exclui a conta dele.

```http
GET    /api/v1/dashboard/team                          # membros, convites, limite e uso
POST   /api/v1/dashboard/team/invitations
POST   /api/v1/dashboard/team/invitations/{id}/resend
DELETE /api/v1/dashboard/team/invitations/{id}
PUT    /api/v1/dashboard/team/members/{id}             # { "role": "admin" | "agent" }
DELETE /api/v1/dashboard/team/members/{id}
GET    /api/v1/dashboard/team/members                  # nome e papel, liberado a atendentes
```

**Como o membro age.** Nas rotas do painel, o membro age como o dono: `auth()->user()` é o dono, então o código do app que separa os dados por `auth()->user()->id` não muda. Quem de fato está logado:

```php
use RiseTechApps\ApiKey\Support\Team;

Team::actor();   // o membro (ou o dono, se for ele)
Team::role();    // 'owner' | 'admin' | 'agent'
Team::member();  // o membro, ou null quando é o próprio dono
```

**Membros de integração (sem login).** Quando quem atende vive no sistema do cliente, o app cria o membro pelo id dele lá, sem convite nem senha:

```php
app(\RiseTechApps\ApiKey\Services\TeamService::class)
    ->provisionExternal($dono, externalId: 'user-123', name: 'Ana Souza', role: 'agent');
```

Cria na primeira vez e atualiza nome e papel nas seguintes (`authentications.external_id`, único por conta). Conta no limite de equipe do plano. O e-mail é fictício (`.invalid`), a senha é aleatória e o login recusa o membro mesmo com a senha certa. Quem age por ele é o sistema do cliente, pela API do app, com `Team::enter($membro, $dono)`. Na tela Equipe ele aparece com o selo "Integração" e o `external_id`, sem e-mail; `/dashboard/team` devolve `external_id` e `email: null` para ele.

**Marcando as rotas do app.** Toda rota do painel passa pelo `active.user`, que aplica a regra:

| Marcação | Quem entra |
|---|---|
| (nenhuma) | dono e admin da equipe |
| `team.role:agent` | dono, admin e atendente |
| `team.role:owner` | só o dono |
| `team.self` | o membro como ele mesmo (perfil, senha, 2FA, sessões) |

```php
Route::middleware(['auth:sanctum', 'active.user', 'team.role:agent'])
    ->get('/api/v1/dashboard/atendimentos', AtendimentosController::class);
```

As rotas do pacote já vêm marcadas: perfil, senha, 2FA, sessões e `/auth/me` são pessoais; checkout, cartões, assinatura, recibos e excedente são do dono; rotas do admin da plataforma nunca valem para membros.

**No SPA.** Cada tela declara o papel mínimo em `meta.teamRole` (padrão `admin`); o menu esconde e o router redireciona para `app.teamHome` (em `api-key.config.ts`, padrão `/dashboard/profile`) quem não alcança. `useAuthStore()` expõe `teamRole`, `isTeamMember` e `teamCan('agent' | 'admin' | 'owner')`.

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

- `api-key:billing-process-renewals` filtra `cancelled_at IS NULL` e nem enfileira o job
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

O crédito é proporcional à duração real da assinatura corrente (`start_date` → `end_date`), não ao ciclo nominal do plano — um período que já tinha sido encurtado por uma troca anterior é medido corretamente. A base é o que o período **custou de fato** (`payment_amount` + `credit_applied`, até o preço do plano), não o preço de tabela: quem pagou com cupom de 60% leva crédito sobre os 40% pagos. Vale zero para plano gratuito, período de teste, plano concedido pelo admin, assinatura inativa ou período já vencido (inclusive dentro da carência). Assinaturas antigas, sem `payment_amount`, valem o preço do plano.

O crédito que a compra usou fica registrado em `user_plans.credit_applied`; o que sobra, quando o crédito passa do preço do plano novo, não vira saldo. Sem essa coluna, um `payment_amount` abaixo do preço do plano seria indistinguível entre desconto de cupom e crédito de troca.

```php
$credito = $user->activePlan()->with('plan')->first()?->unusedCredit();
```

### Período de teste

Com `trial_days` no plano, a primeira assinatura de uma conta começa em teste: sem cobrança, com `is_trial = true` e vencimento em `trial_days`. No fim, a renovação normal cobra o cartão salvo (ou manda o PIX). Vale uma vez por conta (`trial_used_at`) e não vale para quem já pagou alguma assinatura. Sem cartão salvo, o teste só é oferecido com o PIX ligado.

### Cobrança por excedente

Com `overage_price` no plano, o assinante **pode** ligar o excedente em Painel → Faturamento. Ele vem **desligado por padrão**: sem a escolha do cliente, o limite do plano bloqueia como sempre. Ligado, a cota não bloqueia no limite e continua atendendo até o menor entre `overage_max_requests` do plano e o teto de gasto que o próprio assinante definir (`overage_spend_cap`, em reais), ou sem teto se nenhum dos dois existir. Ao passar do limite, o assinante recebe um aviso (e-mail e webhook `usage.overage_started`) uma vez por período, enquanto ainda pode desligar. O excedente é cobrado depois que o período termina, pelo `api-key:billing-charge-overage`: no cartão salvo, pelo perfil de Pagamentos Automáticos ou quando a conta permite cobrar sem CVV. Senão (sem cartão, ou conta que exige CVV), o assinante é avisado e paga pelo painel em Faturamento → "Excedente a pagar", escolhendo um cartão salvo ou PIX. No cartão do Mercado Pago sem renovação automática, o painel pede o CVV (que vira token no navegador); nos outros gateways o cartão salvo é cobrado direto. Valores abaixo de `billing.overage_minimum_charge` são perdoados. Sem `overage_price`, o limite bloqueia como antes.

### Novas tentativas de renovação

Quando o cartão é recusado na renovação, o pacote tenta de novo em `billing.retry_days` (padrão `1,3,5` dias depois do vencimento) e avisa o assinante a cada falha. Enquanto houver tentativa marcada, o `check-expired-plans` não encerra a assinatura; o acesso segue o período de carência. Cadastrar um cartão novo nesse meio-tempo dispara a cobrança na hora. Depois da última tentativa, a assinatura expira normalmente.

### Recibos

`GET dashboard/receipts/{id}` devolve o recibo de um pagamento em HTML, pronto para imprimir ou salvar em PDF pelo navegador. Os dados do emissor vêm de `receipt.*`. O recibo comprova o pagamento e não substitui a nota fiscal.

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

Duas rotinas agendadas cuidam do ciclo, sem depender de o cliente fazer requisições:

- **Aviso de carência** — `api-key:check-expired-plans --grace-only`, todo dia às 10:00 (depois das renovações das 08:00). Avisa cada assinatura que venceu nas últimas 24 horas e continua ativa, ou seja, que não foi renovada: dispara `PlanGracePeriodStarted`, com o e-mail `GracePeriodStartedNotification` e o webhook `subscription.grace_period_started`. Sai uma vez por assinatura. Com `grace_period_days = 0`, não avisa nada.
- **Encerramento** — `api-key:check-expired-plans`, de hora em hora. Desativa a assinatura e todas as chaves da conta quando a carência termina, e dispara `PlanExpired` (e-mail e webhook `subscription.expired`). Uma renovação recusada com nova tentativa marcada espera a tentativa.

O `check.active.plan` continua encerrando na hora uma assinatura vencida que chega numa requisição; a rotina alcança quem parou de chamar a API.

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

## Gateways de pagamento

O pacote fala com **Mercado Pago**, **Stripe**, **Efí** e **PagBank** (e **Pagar.me**, que fica fora do seletor: o Pagar.me não aceita mais cadastro novo). Um gateway fica ativo por vez e atende tudo: checkout, cadastro de cartão e PIX. A escolha e as credenciais ficam em **Painel → Admin → Gateways**:

- O que for salvo lá vale por cima do `.env`, criptografado no banco (`api_key_settings`) com a `APP_KEY`. Uma chave sem valor salvo continua vindo do `.env`, então instalações configuradas só por `.env` não mudam nada.
- Credenciais nunca voltam para a tela, que mostra só se há valor e os últimos caracteres. Deixar um segredo em branco ao salvar mantém o atual.
- Cada gateway tem **Testar conexão**. No Mercado Pago, o teste também diz se a conta tem os Pagamentos Automáticos liberados.
- Toda alteração entra na auditoria (`admin.payment_settings_updated`), só com o nome das chaves.
- Os workers da fila reaplicam as configurações antes de cada job, sem precisar reiniciar.

**Trocar de gateway** muda tudo de uma vez: só o gateway ativo cobra, no checkout, na renovação e no excedente. Cartões salvos em outro gateway ficam **fora de uso** (`usable: false` em `/dashboard/cards`, marcados assim no painel) até o assinante cadastrar um cartão no gateway atual. Nesse intervalo a renovação pede o pagamento pelo painel com o motivo `card_out_of_use`, e o e-mail pede um cartão novo. A cobrança automática usa o cartão padrão quando ele é do gateway ativo, ou o mais recente do gateway ativo. Pagamentos já feitos continuam ligados ao gateway de origem para estorno e reconciliação, pelo prefixo do id.

O id do pagamento diz de onde ele veio, e é por ele que o estorno, a reconciliação e o webhook escolhem o gateway:

| Prefixo | Gateway |
|---|---|
| numérico | Mercado Pago (pagamento) |
| `ORD…` | Mercado Pago (order dos Pagamentos Automáticos) |
| `pi_…` | Stripe |
| `efi_…` / `efipix_…` | Efí (cartão / PIX) |
| `ORDE_…` | PagBank |
| `or_…` | Pagar.me |

Nos gateways além do Mercado Pago, todo cartão salvo já sai com **renovação automática sem CVV**. Webhook nunca decide nada sozinho: o corpo diz só qual pagamento mudou, e o pacote consulta o pagamento na API do gateway antes de agir.

### Stripe

- **Cartão:** Stripe Elements no navegador confirma um SetupIntent (`usage: off_session`), aberto por `POST dashboard/cards/setup-intent`. O servidor recebe só o id do SetupIntent e confere que ele foi aberto para o mesmo usuário. O cartão fica no cliente do Stripe como PaymentMethod.
- **Cobrança:** PaymentIntent. Na compra vai `on_session`: se o banco pedir 3DS, o checkout responde `pending` com `client_action` e o painel abre o desafio (`stripe.handleNextAction`). Renovação e excedente vão `off_session`, sem CVV; pedido de autenticação ali vira recusa, e o assinante paga pelo painel.
- **PIX:** só em conta Stripe brasileira, de R$ 0,50 a R$ 3.000,00 por pagamento. Ative o PIX em *Settings → Payment methods*.
- **Webhook:** cadastre `api/v1/dashboard/checkout/webhook/stripe` com `payment_intent.succeeded`, `payment_intent.payment_failed` e `payment_intent.canceled`, e copie o signing secret (`whsec_…`). O secret é obrigatório: a assinatura `Stripe-Signature` é conferida (janela de 5 minutos), e sem `STRIPE_WEBHOOK_SECRET` todo webhook do Stripe é recusado com 401.

```env
API_KEY_PAYMENT_GATEWAY=stripe
STRIPE_PUBLISHABLE_KEY=pk_...
STRIPE_SECRET_KEY=sk_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PIX_ENABLED=true
```

### Efí

- **Cartão:** a biblioteca da Efí (`payment-token-efi`) gera no navegador um `payment_token` reutilizável (`reuse: true`), com o identificador de conta. O token fica salvo e cobra as próximas vezes sem CVV pela API de Cobranças (`/v1/charge/one-step`).
- **Perfil completo:** a Efí exige na cobrança de cartão **CPF, celular, data de nascimento e endereço** do titular. O cadastro do cartão já confere isso e pede para completar o perfil, para a renovação não falhar depois. A Efí não valida o cartão sem cobrar: ele é conferido na primeira cobrança.
- **PIX:** pela API Pix, que exige o **certificado da aplicação** (`.p12`) em toda chamada. Envie o certificado pelo painel (fica criptografado no banco) ou ponha o conteúdo em base64 em `EFI_PIX_CERTIFICATE`. O pacote grava o certificado num arquivo temporário (0600) só durante a chamada. O QR leva o CPF do cliente.
- **Notificações:** a do cartão vai em cada cobrança (`notification_url`), para `api/v1/dashboard/checkout/webhook/efi`, sem cadastro; só funciona com a aplicação em HTTPS. O webhook do PIX da Efí exige mTLS no seu servidor, e o pacote não depende dele: o painel consulta a cobrança enquanto mostra o QR, e o `api-key:reconcile-payments` cobre o resto.
- `EFI_SANDBOX=true` usa as APIs de homologação, com credenciais e certificado de homologação.
- **Cuidados na configuração** (todos apareceram em teste real; o **Testar conexão** do painel acusa os dois primeiros):
  - O **identificador de conta** é o código de 32 caracteres de *API → Introdução → Identificador de conta*, não o número da conta (`123456-7`). Com o número, a Efí recusa o token do cartão.
  - A **chave PIX** precisa estar cadastrada na **mesma conta** das credenciais. Chave aleatória é conferida no teste de conexão; CPF, e-mail ou telefone, só na primeira cobrança.
  - A aplicação precisa dos escopos da **API de Cobranças** e da **API Pix**, e a conta precisa do **ramo de atividade** cadastrado para cobrar cartão.

```env
API_KEY_PAYMENT_GATEWAY=efi
EFI_CLIENT_ID=Client_Id_...
EFI_CLIENT_SECRET=Client_Secret_...
EFI_ACCOUNT_ID=...
EFI_PIX_ENABLED=true
EFI_PIX_KEY=sua-chave-pix
```

### PagBank

- **Cartão:** criptografado no navegador com a chave pública da conta (`PagSeguro.encryptCard`) e trocado por um cartão salvo (`CARD_…`). Sem `PAGBANK_PUBLIC_KEY`, o pacote cria a chave pública na primeira vez e guarda em cache.
- **Cobrança sem CVV:** a compra com cartão novo abre a recorrência (`recurring: INITIAL`) com o CVV digitado, que só passa pelo servidor e nunca é gravado nem registrado. Renovação e excedente vão como `SUBSEQUENT`, pelo id do cartão.
- **Referência:** o PagBank aceita até 64 caracteres em `reference_id`. Renovação e excedente vão na forma curta (`rn-<assinatura>`, `ov-<assinatura>`); a compra é achada pelo registro de espera.
- **Notificação:** vai em cada pedido (`notification_urls`), para `api/v1/dashboard/checkout/webhook/pagbank`, e é conferida pelo `x-authenticity-token` (sha256 do token da conta com o corpo). Só funciona com a aplicação em HTTPS.

```env
API_KEY_PAYMENT_GATEWAY=pagbank
PAGBANK_TOKEN=...
PAGBANK_PIX_ENABLED=true
```

### Pagar.me

Fica no pacote para quem já tem conta, mas fora do seletor do painel (`payment.hidden_gateways`): o cadastro novo no Pagar.me vai para a Stone. Para usar, ative pelo `.env` (`API_KEY_PAYMENT_GATEWAY=pagarme`), e ele volta a aparecer no painel.

- **Cartão:** tokenizado no navegador, direto na API do Pagar.me (`POST /core/v5/tokens?appId=<chave pública>`), e salvo com validação de valor zero (`verify_card`). Cadastre o domínio do painel no Pagar.me, senão a tokenização é recusada.
- **Cobrança sem CVV:** a compra vai como `recurrence_cycle: first`; renovação e excedente como `subsequent`, com `payment_origin.charge_id` da primeira cobrança.
- **PIX:** exige **CPF e celular** do cliente.
- **Webhook:** `api/v1/dashboard/checkout/webhook/pagarme`, com os eventos de pedido. O Pagar.me não assina a notificação; com usuário e senha configurados, a URL exige Basic Auth.

### Outro gateway

Cada gateway é um driver que implementa `RiseTechApps\ApiKey\Contracts\CardGateway` (salvar e remover cartão, cobrar, PIX, consultar, estornar e ler o webhook), registrado em `GatewayRegistry::DRIVERS`. Os drivers devolvem os pagamentos num formato único (`id`, `status`, `status_detail`, `external_reference`, `transaction_amount`, `payment_method_id`), e é isso que mantém renovação, excedente, webhook, reconciliação e estorno iguais para todos.

**Mensagens de erro:** só o texto de `RiseTechApps\ApiKey\Exceptions\PaymentMessageException` chega à tela. Use-a para o que o cliente precisa ler ("cartão recusado na validação", "complete o CPF no perfil"). Qualquer outra exceção (HTTP 500 do gateway, resposta inesperada) aparece como "Erro ao processar pagamento", com o detalhe só no log.

**No front-end:** `/auth/me` traz `payment_gateway` (o gateway ativo), `payment_gateway_config` (o que o navegador precisa para tokenizar o cartão: chave pública, identificador de conta, ambiente; nunca segredos) e `pix_enabled`. Os cartões de `/dashboard/cards` trazem `gateway`, `usable` e `automatic_billing`. A tokenização de cada gateway está em `resources/js/utils/gateways.ts`, e o formulário em `GatewayCardFields.vue`.

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

Além de `payment`, o webhook trata `order` (cobranças dos Pagamentos Automáticos, consultadas na Orders API e aplicadas pelo mesmo serviço dos pagamentos comuns) e `payment_profile` (atualiza o status do perfil do cartão). Outros tópicos respondem 200 e são ignorados.

O IPN chega justamente porque o pagamento carrega `notification_url`, e **não tem HMAC a conferir**. Quem verifica é o pacote, indo buscar o pagamento na API com o seu access token — só pagamentos da sua própria conta produzem efeito. Como é verificação mais fraca, dá para recusar o formato com `MP_ACCEPT_IPN=false`; nesse caso remova também o `notification_url`, senão o gateway reentrega indefinidamente contra um 400.

Como qualquer um consegue mandar um IPN, cada consulta à API que ele dispara tem teto: `MP_IPN_LOOKUPS_PER_MINUTE` (padrão 60; 0 desliga). Acima dele o pacote responde 503 e o Mercado Pago reentrega depois. O mesmo pagamento não é consultado de novo por IPN dentro de um minuto. O webhook assinado não entra nessa conta.

Para um flood de avisos falsos não barrar os verdadeiros, cada IP tem um teto próprio dentro do geral (`API_KEY_UNSIGNED_NOTIFICATION_LOOKUPS_PER_IP_PER_MINUTE`, padrão 20), e IPs listados em `API_KEY_NOTIFICATION_TRUSTED_IPS` (IPs ou faixas CIDR, separados por vírgula) não entram em teto nenhum. Vale para o IPN e para as notificações sem assinatura da Efí e do Pagar.me. O IP confiável só pula o teto: o pagamento continua sendo consultado na API antes de valer. Atrás de proxy, configure o TrustProxies do Laravel, senão todo aviso parece vir do mesmo IP.

O HMAC prova que o webhook veio do Mercado Pago, não que é recente. Por isso o `ts` assinado tem que estar a no máximo `MP_WEBHOOK_TOLERANCE_SECONDS` (padrão 300) do relógio do servidor, ou a notificação é recusada: uma notificação capturada não serve para ser reenviada depois. Mantenha o relógio sincronizado (NTP). Uma recusa por relógio errado não perde pagamento, porque o Mercado Pago reentrega e a reconciliação agendada alcança o que ficou para trás, mas aparece no log como `signature timestamp outside tolerance`. `0` desliga a checagem.

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

### PIX

Com `MP_PIX_ENABLED=true` (e uma chave PIX cadastrada na conta do Mercado Pago), o checkout aceita `payment_method: "pix"`: a resposta traz `qr_code`, `qr_code_base64`, `ticket_url` e `payment_id`, e o QR vale `MP_PIX_EXPIRATION_MINUTES` (mínimo 30). O plano é ativado pelo webhook quando o pagamento cai; o painel consulta `dashboard/checkout/payments/{paymentId}` enquanto mostra o QR. Cupom e crédito de troca de plano funcionam como no cartão.

O QR do PIX é **sempre gerado no painel**, a pedido do assinante, e nunca vai por e-mail: cobrança dentro de e-mail é o formato que filtros de spam e golpes exploram, e uma entrega falha derrubaria a cobrança junto.

### Renovação que o assinante conclui pelo painel

A renovação só é automática quando o cartão salvo pode ser cobrado sem o cliente presente. Quando não pode, o pacote não tenta de novo nem envia cobrança: marca a assinatura (`renewal_requires_payment`), avisa por e-mail e pelo webhook `subscription.payment_required`, e o painel mostra **"Renovar agora"**, que abre o checkout do mesmo plano (PIX ou cartão com CVV). O acesso segue até o fim da carência. Os motivos são:

- `no_card`: não há cartão salvo;
- `pix`: o assinante paga com PIX;
- `cvv_required`: a conta do Mercado Pago exige o CVV em toda cobrança com cartão salvo (`security_code_id can't be null`).

### Cobrança sem CVV (Pagamentos Automáticos)

O fluxo comum de cartão salvo (`/v1/payments`) exige um token novo a cada cobrança, e a conta do Mercado Pago pode exigir o CVV para gerá-lo, o que é impossível numa renovação sem o titular presente. Para isso o pacote integra os **Pagamentos Automáticos** do Mercado Pago:

1. **Perfil de pagamento.** Quando o titular digita o CVV (ao cadastrar o cartão, ou ao pagar com um cartão salvo que ainda não tem perfil), o painel gera um segundo token do mesmo cartão, e o pacote cria o perfil em `POST /v1/customers/{id}/payment-profiles`. O gateway valida o cartão sozinho, com uma autorização de R$ 0,00. O cartão passa a mostrar "Renovação automática ativa" em Faturamento (`automatic_billing` na listagem de cartões).
2. **Cobrança.** A renovação e o excedente de um cartão com perfil vão pela Orders API (`POST /v1/orders`) com o perfil e `stored_credential` iniciado pelo vendedor, sem token e sem CVV. A cobrança fica registrada pelo id da order (`ORD…`) em `payment_id`, e é por ele que o webhook, a reconciliação e o estorno a encontram.

A solução **precisa de autorização da equipe Comercial do Mercado Pago**. Enquanto a conta não for autorizada, o gateway responde 403 e o pacote segue pelo caminho antigo (cartão tokenizado sem CVV e, se a conta recusar, `cvv_required` e pagamento pelo painel). Por isso fica ligado por padrão: a cobrança sem CVV começa sozinha no dia em que a conta for liberada, sem deploy. Para desligar, use `MP_AUTOMATIC_PAYMENTS_ENABLED=false`.

Cartões sem perfil (salvos antes desta versão, ou cadastrados direto no checkout, já que o Card Payment Brick gera um único token por pagamento) mostram **"Ativar renovação automática"** em Faturamento, que pede só o CVV (`POST dashboard/cards/{id}/automatic-billing` com `profile_token`). Depois de uma compra que salvou um cartão novo, a resposta do checkout traz `automatic_billing_offer` e o painel oferece a ativação na hora. Ativar destrava também uma renovação parada em `cvv_required`.

Remover um cartão, ou excluir a conta, apaga também o cartão e o perfil no Mercado Pago (melhor esforço: falha no gateway fica no log e não impede a remoção local).

> No Mercado Pago, habilite também as notificações de **Order** e de **Perfis de pagamento** para a URL do webhook. A cobrança pela Orders API é confirmada por elas, e não pela notificação `payment`.

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

`max_uses` conta todos os usos do cupom. Cada conta usa o mesmo cupom no máximo `coupons.max_uses_per_user` vezes (padrão 1, `API_KEY_COUPON_MAX_USES_PER_USER`; 0 desliga). O uso é reservado no checkout e devolvido se a compra não vinga, como o `max_uses`, e fica em `coupon_redemptions`.

---

## Logs

O pacote registra pelo `risetechapps/monitoring-for-laravel`, que persiste em banco em vez de arquivo. Todo log interno usa os helpers `loggly*()`:

```php
logglyError()->withContext(['payment_id' => $id])->log('Refund failed');
logglyError()->exception($e)->log('Checkout process error');
```

Rode as migrations do monitoring — o `php artisan migrate` já as inclui — senão a tela responde 503 dizendo que o armazenamento não está disponível.

### Tela administrativa

`/dashboard/admin/logs` no painel, restrita a admin. Filtra por tipo, nível e período, e busca em mensagem e contexto.

Ela mostra **dois tipos**, e a distinção importa:

| Tipo | Origem |
|------|--------|
| `log` | O que o pacote grava de propósito, pelos helpers `loggly*()` |
| `exception` | O que o handler da aplicação captura via `report()` |

Um estorno que falha produz os dois: o log com o código de correlação que aparece na resposta ao operador, e a exceção com o detalhe completo. Só o par conta a história inteira — por isso a tela não se limita a um deles.

A listagem traz apenas nível, mensagem e origem. Contexto, trace e tags ficam no detalhe, porque em log de exceção passam de alguns KB por entrada.

### O que entra no registro

Trace de exceção é gravado de forma **estruturada** — arquivo, linha, classe e função por frame, com teto de 20 — e não como o texto de `getTraceAsString()`, que inclui os argumentos de cada chamada. É por eles que passariam token de cartão e dados do pagador, agora legíveis numa tela do painel.

> Alguns registros de erro carregam a mensagem da exceção no contexto, e uma `QueryException` traz o SQL com os valores bindados. Isso vive no banco e é visível para admin. Se no seu produto "admin" for um time de suporte amplo, vale revisar esses pontos ou usar `->to('file')` neles, que grava fora do banco.

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
| `ApiKeyExpiring` | API key vence dentro de `api_keys.expiry_warning_days` |
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
| `ApiKeyExpiringNotification` | `ApiKeyExpiring` | 1 por chave e data de validade |

> O aviso de uso dispara no limiar definido por `API_KEY_USAGE_WARNING_THRESHOLD` (padrão `80`%). Requisições bloqueadas por limite (429) são **registradas no log, mas não contam** na cota.

**Avisos informativos opcionais.** `UsageThresholdNotification`, `ApiKeyExpiringNotification` e `OverageStartedNotification` podem ser desligados pelo dono da conta em Perfil → Notificações (`PUT /api/v1/dashboard/profile/notifications` com `{"usage_notices": false}`; coluna `authentications.usage_notices_enabled`, ligada por padrão). Cobrança, segurança, limite atingido, carência e expiração saem sempre. O evento e o webhook continuam disparando; só o e-mail é suprimido. A regra mora no trait `Notifications\Concerns\InformationalNotice` (`shouldSend()`): uma classe sua que estende a do pacote herda a preferência, e uma escrita do zero pode usar o trait.

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
| `renewal_failed` | `($plan, $amount, $attempt, $nextAttemptAt, $reason = null)` — `$nextAttemptAt` null na última tentativa |
| `renewal_payment_required` | `($plan, $amount, $accessUntil, $reason)` — `$reason`: `no_card`, `pix` ou `cvv_required` |
| `overage_charge` | `($plan, $state, $amount, $requests)` — `$state`: `paid`, `payment_required` ou `rejected` |
| `overage_started` | `($plan, $userPlan, $extraAllowed)` — `$extraAllowed` null quando não há teto |
| `account_security` | `($event, $context = [])` — `$event`: `password_changed`, `email_changed` (vai ao e-mail antigo, com `new_email` no contexto), `two_factor_enabled`, `two_factor_disabled` |
| `email_change` | `($user, $newEmail)` — vai ao endereço novo; use `$this->confirmationUrl()` para o link |

#### Só o visual

Para manter os textos e mudar apenas a aparência (logo, cores, rodapé) de **todos** os e-mails, inclusive os do pacote, publique os templates de e-mail do próprio Laravel e edite-os no app:

```bash
php artisan vendor:publish --tag=laravel-mail
# resources/views/vendor/mail/html/ (layout, header, footer, button, themes/default.css)
```

O nome exibido e o remetente vêm de `APP_NAME` e `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`.

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
| `api.key` | `AuthenticateApiKey` | Valida a API key do header `X-API-KEY`; recusa (403) conta suspensa |
| `api.key.rate` | `ApiKeyRateLimitMiddleware` | Limite de requisições por minuto da chave/plano |
| `check.active.plan` | `CheckActivePlanMiddleware` | Exige assinatura ativa ou em período de carência |
| `check.limit.plan` | `CheckRequestLimitMiddleware` | Rejeita requisições acima do limite do plano |
| `api.key.origin` | `ApiKeyOriginValidatorMiddleware` | Valida o header `Origin` contra as origens permitidas (ver nota abaixo) |
| `language` | `LanguageMiddleware` | Fixa o locale, sem olhar o `Accept-Language`: `language` / `language:pt` (painel) ou `language:en` (API) |
| `admin` | `AdminMiddleware` | Exige `role = admin` |
| `active.user` | `EnsureUserIsEnabled` | Recusa (403, `code: account_disabled`) usuário autenticado com conta suspensa |
| `feature` | `CheckPlanFeatureMiddleware` | Exige feature específica no plano atual; aplica automaticamente `check.limit.plan` (log + contagem) |
| `plan` | *(grupo)* | Combina `api.key + api.key.rate + check.active.plan + check.limit.plan + api.key.origin + language:en` |

> **Origens permitidas não são autenticação.** O header `Origin` é preenchido pelo navegador, mas qualquer cliente fora dele (curl, servidor, script) envia o valor que quiser. A lista de origens impede que outro *site* use a chave a partir do navegador de um visitante; quem tem a chave em mãos passa por ela. Quem protege é a API key.
>
> A lista aceita dois tipos de entrada, e cada tipo só é conferido contra a sua fonte. A requisição passa se qualquer um casar:
>
> | Entrada | Exemplos | Conferida contra |
> |---|---|---|
> | Domínio | `app.example.com`, `*.example.com`, `app.example.*` | header `Origin` |
> | IP | `203.0.113.5`, `10.0.0.0/8`, `2001:db8::/32`, `192.168.*` | IP da conexão, de `$request->ip()` |
>
> Por isso um IP na lista restringe de verdade: o `Origin` nunca é comparado com entradas de IP, e um `Origin` cujo host é um IP não casa com nada. Use IP (ou faixa CIDR) para chamadas de servidor e domínio para chamadas do navegador. Atrás de proxy ou Cloudflare, configure o `TrustProxies` para o IP real chegar; headers como `X-Forwarded-For` vindos de um cliente não confiável são ignorados.
>
> Curingas de domínio: `*.example.com` (qualquer subdomínio e o próprio domínio) e `app.example.*` (o `*` vale por **um** rótulo: casa `app.example.com`, não `app.example.evil.com`). Em prefixos de IP, como `192.168.*`, o curinga cobre o restante do endereço.

---

## Comandos Artisan

```bash
# Encerrar assinaturas cuja carência terminou (roda automaticamente de hora em hora)
php artisan api-key:check-expired-plans

# Avisar as assinaturas que entraram em carência nas últimas 24h (roda automaticamente às 10:00)
php artisan api-key:check-expired-plans --grace-only

# Processar renovações agendadas (roda automaticamente todo dia às 08:00)
# Cada assinatura vira um ProcessPlanRenewalJob; use --dry-run para listar sem cobrar
php artisan api-key:billing-process-renewals

# Cobrar o excedente dos períodos encerrados (roda automaticamente às 09:00)
php artisan api-key:billing-charge-overage
php artisan api-key:billing-charge-overage --dry-run

# Apagar logs de requisição além da janela de retenção (roda automaticamente às 03:30)
php artisan api-key:prune-logs
php artisan api-key:prune-logs --dry-run          # apenas relata quantas linhas seriam apagadas
php artisan api-key:prune-logs --days=30          # sobrescreve a retenção configurada

# Avisar sobre chaves que vencem em breve (roda automaticamente às 07:00)
php artisan api-key:notify-expiring
php artisan api-key:notify-expiring --dry-run     # lista as chaves, sem avisar
php artisan api-key:notify-expiring --days=14     # sobrescreve a antecedência configurada

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
| `api-key:billing-process-renewals` | diária, 08:00 | — |
| `api-key:billing-charge-overage` | diária, 09:00 | — |
| `api-key:check-expired-plans --grace-only` | diária, 10:00 | — |
| `api-key:check-expired-plans` | de hora em hora | — |
| `api-key:prune-logs` | diária, 03:30 | `request_log.prune_enabled` |
| `api-key:notify-expiring` | diária, 07:00 | `api_keys.expiry_warning_days = 0` |
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
        'headers' => true,           // X-Quota-* nas respostas do grupo `plan`
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

    // Fallback de keys legadas (sem lookup_hash). Desligue depois de rotacionar as legadas;
    // sai do pacote na próxima major. max_seconds: orçamento de tempo, 0 = sem limite.
    'legacy_scan' => [
        'enabled'     => true,
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

    // Fila do comando api-key:billing-process-renewals (null = fila default).
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

    'payment_throttle' => [
        'enabled'       => true,
        'attempts'      => 10,
        'decay_minutes' => 10,
    ],

    'header_name'      => 'X-API-KEY',

    'routes' => [
        'enabled' => true,
        'prefix'  => '',
    ],

    'middleware_group' => [
        'plan' => [
            'api.key',
            'api.key.rate',
            'check.active.plan',
            'check.limit.plan',
            'api.key.origin',
            'language:en',
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

        // Idade máxima do `ts` de um webhook assinado (anti-replay). 0 desliga.
        'webhook_tolerance_seconds' => env('MP_WEBHOOK_TOLERANCE_SECONDS', 300),
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
| `API_KEY_QUOTA_HEADERS` | Headers `X-Quota-*` nas respostas do grupo `plan` | `true` |
| `API_KEY_LOOKUP_SECRET` | Secret p/ derivar o `lookup_hash` (busca O(1) de API key) | `app.key` |
| `API_KEY_LEGACY_SCAN_ENABLED` | Liga o fallback de keys legadas; desligue depois de `api-key:rotate-keys --legacy` | `true` |
| `API_KEY_LEGACY_SCAN_MAX_SECONDS` | Orçamento do fallback de keys legadas (`0` = sem limite) | `3` |
| `API_KEY_LOG_RETENTION_DAYS` | Dias de retenção do log de requisições (`0` = manter tudo) | `90` |
| `API_KEY_LOG_PRUNE_ENABLED` | Agendar `api-key:prune-logs` automaticamente | `true` |
| `API_KEY_LOG_PRUNE_CHUNK` | Tamanho do lote de exclusão do prune | `5000` |
| `API_KEY_LOG_QUEUE` | Fila do log de requisições. **Exige worker consumindo essa fila**; vazio = grava `afterResponse` | `logs` |
| `API_KEY_LOG_CONNECTION` | Conexão da fila do log (Horizon observa `redis`) | `redis` |
| `API_KEY_QUEUE_CONNECTION` | Conexão dos listeners/notificações enfileirados | `redis` |
| `API_KEY_QUEUE_NAME` | Fila dos listeners/notificações (`null` = default da conexão) | — |
| `API_KEY_BILLING_QUEUE` | Fila do comando `api-key:billing-process-renewals` | — |
| `API_KEY_DISABLE_WEB_MIDDLEWARE` | Anexar `DisableRouteWebMiddleware` ao grupo `web` | `true` |
| `API_KEY_AUTH_THROTTLE_ENABLED` | Habilitar throttle nos endpoints de autenticação | `true` |
| `API_KEY_AUTH_THROTTLE_ATTEMPTS` | Máximo de tentativas nas rotas públicas de autenticação | `5` |
| `API_KEY_AUTH_THROTTLE_DECAY` | Janela de decaimento do throttle (minutos) | `1` |
| `API_KEY_PAYMENT_THROTTLE_ENABLED` | Limitar checkout, cupom e cadastro de cartão | `true` |
| `API_KEY_PAYMENT_THROTTLE_ATTEMPTS` | Tentativas por usuário na janela | `10` |
| `API_KEY_PAYMENT_THROTTLE_DECAY` | Janela do limite de pagamento (minutos) | `10` |
| `API_KEY_WEBHOOK_TEST_THROTTLE_ATTEMPTS` | Testes e reenvios de webhook por janela, por usuário | `10` |
| `API_KEY_WEBHOOK_TEST_THROTTLE_DECAY` | Janela do limite de teste de webhook (minutos) | `1` |
| `API_KEY_EXPORT_THROTTLE_ENABLED` | Limitar a exportação dos dados da conta | `true` |
| `API_KEY_EXPORT_THROTTLE_ATTEMPTS` | Exportações por janela, por usuário | `5` |
| `API_KEY_EXPORT_THROTTLE_DECAY` | Janela do limite de exportação (minutos) | `60` |
| `API_KEY_SUPPORT_EMAIL` | E-mail exibido na página de suporte da SPA | `MAIL_FROM_ADDRESS` |
| `API_KEY_HEADER_NAME` | Header HTTP que carrega a API key | `X-API-KEY` |
| `API_KEY_ROUTES_ENABLED` | Registrar rotas do pacote automaticamente | `true` |
| `API_KEY_ROUTES_PREFIX` | Prefixo das rotas | `''` |
| `API_KEY_SPA_ENABLED` | Servir o painel SPA Vue | `false` |
| `API_KEY_DEMO_USER_ID` | `authentication.id` para o endpoint de demonstração pública | — |
| `API_INTERNAL_TOKEN` | Token secreto para chamadas servidor-a-servidor. Aceito só de loopback (`REMOTE_ADDR` e `$request->ip()`); atrás de proxy local, configure o TrustProxies ou o token passa a ser a única barreira | — |
| `MP_PUBLIC_KEY` | Chave pública do MercadoPago | — |
| `MP_ACCESS_TOKEN` | Access token do MercadoPago | — |
| `MP_WEBHOOK_SECRET` | Secret HMAC do webhook do MercadoPago | — |
| `MP_NOTIFICATION_URL` | URL enviada em `notification_url` a cada pagamento. Vazia em desenvolvimento | — |
| `MP_ACCEPT_IPN` | Aceitar notificações IPN, que chegam sem assinatura | `true` |
| `MP_IPN_LOOKUPS_PER_MINUTE` | Teto de consultas ao MP disparadas por IPN sem assinatura; 0 desliga | `60` |
| `API_KEY_UNSIGNED_NOTIFICATION_LOOKUPS_PER_IP_PER_MINUTE` | Teto de cada IP dentro dos tetos de avisos sem assinatura; 0 desliga | `20` |
| `API_KEY_NOTIFICATION_TRUSTED_IPS` | IPs ou faixas dos gateways, separados por vírgula, que não entram nos tetos | — |
| `MP_WEBHOOK_TOLERANCE_SECONDS` | Idade máxima do `ts` de um webhook assinado; 0 desliga | `300` |
| `API_KEY_REFUND_WINDOW_DAYS` | Dias de janela para estorno no cancelamento (`0` = desliga) | `0` |
| `API_KEY_REFUND_MAX_USAGE_PERCENT` | Teto de consumo do ciclo que ainda dá direito a estorno | `50` |
| `API_KEY_RECONCILE_PAYMENTS_ENABLED` | Agendar a reconciliação de pagamentos em análise | `true` |
| `API_KEY_RETRY_VALIDATION_REFUNDS_ENABLED` | Agendar o reprocessamento de estornos de validação | `true` |
| `MP_PIX_ENABLED` | Aceitar PIX no checkout e na renovação | `false` |
| `MP_PIX_EXPIRATION_MINUTES` | Validade do QR PIX do checkout (mínimo 30) | `30` |
| `API_KEY_PAYMENT_GATEWAY` | Gateway ativo: `mercadopago`, `stripe`, `efi`, `pagbank` ou `pagarme` (o painel admin vale por cima) | `mercadopago` |
| `STRIPE_PUBLISHABLE_KEY` / `STRIPE_SECRET_KEY` | Credenciais do Stripe | — |
| `STRIPE_WEBHOOK_SECRET` | Signing secret do webhook do Stripe (`whsec_…`) | — |
| `STRIPE_STATEMENT_DESCRIPTOR` | Complemento na fatura do cartão (até 22 caracteres) | — |
| `STRIPE_PIX_ENABLED` / `STRIPE_PIX_EXPIRATION_MINUTES` | PIX pelo Stripe e validade do QR | `false` / `30` |
| `EFI_CLIENT_ID` / `EFI_CLIENT_SECRET` | Credenciais da aplicação Efí | — |
| `EFI_ACCOUNT_ID` | Identificador de conta (payee code), para o token do cartão | — |
| `EFI_SANDBOX` | Usar as APIs de homologação da Efí | `false` |
| `EFI_PIX_ENABLED` / `EFI_PIX_KEY` | PIX pela Efí e a chave PIX da conta | `false` / — |
| `EFI_PIX_CERTIFICATE` / `EFI_PIX_CERTIFICATE_PASSWORD` | Certificado `.p12` da aplicação em base64, e a senha dele | — |
| `EFI_PIX_EXPIRATION_MINUTES` | Validade do QR PIX da Efí (mínimo 30) | `30` |
| `PAGBANK_TOKEN` | Token da conta PagBank | — |
| `PAGBANK_PUBLIC_KEY` | Chave pública para criptografar o cartão (vazia: criada pelo pacote) | — |
| `PAGBANK_SANDBOX` | Usar o sandbox do PagBank | `false` |
| `PAGBANK_STATEMENT_DESCRIPTOR` | Nome na fatura do cartão (até 17 caracteres) | `APP_NAME` |
| `PAGBANK_PIX_ENABLED` / `PAGBANK_PIX_EXPIRATION_MINUTES` | PIX pelo PagBank e validade do QR | `false` / `30` |
| `PAGARME_PUBLIC_KEY` / `PAGARME_SECRET_KEY` | Credenciais do Pagar.me | — |
| `PAGARME_WEBHOOK_USER` / `PAGARME_WEBHOOK_PASSWORD` | Basic Auth exigido no webhook do Pagar.me | — |
| `PAGARME_STATEMENT_DESCRIPTOR` | Nome na fatura do cartão (até 13 caracteres) | `APP_NAME` |
| `PAGARME_PIX_ENABLED` | Aceitar PIX pelo Pagar.me | `false` |
| `PAGARME_PIX_EXPIRATION_MINUTES` | Validade do QR PIX do Pagar.me (mínimo 30) | `30` |
| `API_KEY_SPA_BRAND_NAME` | Nome do painel (padrão: `APP_NAME`) | — |
| `API_KEY_SPA_BRAND_COLOR` | Cor da marca em hexadecimal; gera a paleta `brand-*` | `#6366f1` |
| `API_KEY_SPA_BRAND_LOGO` | URL HTTPS do logo | — |
| `MP_AUTOMATIC_PAYMENTS_ENABLED` | Cobrar renovação e excedente pelo perfil de Pagamentos Automáticos, sem CVV | `true` |
| `API_KEY_RENEWAL_RETRY_DAYS` | Dias após o vencimento para novas tentativas da renovação recusada | `1,3,5` |
| `API_KEY_OVERAGE_MINIMUM_CHARGE` | Excedente abaixo deste valor é perdoado | `1.00` |
| `API_KEY_RATE_LIMIT_PER_MINUTE` | Limite por minuto padrão, quando chave e plano não definem | — |
| `API_KEY_MAX_KEYS_PER_USER` | Máximo de API keys por conta | `5` |
| `API_KEY_COUPON_MAX_USES_PER_USER` | Vezes que a mesma conta usa o mesmo cupom; 0 = sem limite por conta | `1` |
| `API_KEY_EXPIRY_WARNING_DAYS` | Antecedência, em dias, do aviso de chave vencendo (`0` desliga) | `7` |
| `API_KEY_2FA_REQUIRED_FOR_ADMINS` | Exigir 2FA para as rotas de admin | `true` |
| `API_KEY_SESSION_LIFETIME_DAYS` | Validade de cada login no painel, em dias; 0 = sem validade | `30` |
| `API_KEY_WEBHOOKS_ENABLED` | Enviar webhooks de saída | `true` |
| `API_KEY_WEBHOOKS_QUEUE` | Fila das entregas de webhook | — |
| `API_KEY_WEBHOOKS_TIMEOUT` | Timeout de cada entrega (segundos) | `10` |
| `API_KEY_WEBHOOKS_MAX_PER_USER` | Máximo de webhooks por conta | `5` |
| `API_KEY_WEBHOOKS_DISABLE_AFTER` | Entregas perdidas seguidas que desligam o endpoint | `20` |
| `API_KEY_WEBHOOKS_ALLOW_HTTP` | Aceitar URL `http://` (só desenvolvimento) | `false` |
| `API_KEY_WEBHOOKS_BLOCK_PRIVATE` | Recusar IPs privados/loopback (proteção SSRF) | `true` |
| `API_KEY_RECEIPT_ISSUER_NAME` | Nome do emissor no recibo | `APP_NAME` |
| `API_KEY_RECEIPT_ISSUER_DOCUMENT` | CNPJ/CPF do emissor | — |
| `API_KEY_RECEIPT_ISSUER_ADDRESS` | Endereço do emissor | — |
| `API_KEY_RECEIPT_ISSUER_EMAIL` | E-mail do emissor | `MAIL_FROM_ADDRESS` |

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
- **Marca do painel** — nome, cor e logo em **Admin → Aparência**, ou no `.env` (`API_KEY_SPA_BRAND_NAME`, `API_KEY_SPA_BRAND_COLOR`, `API_KEY_SPA_BRAND_LOGO`). Vale também para o painel pré-compilado: o shell Blade entrega a cor como a variável CSS `--brand`, e a paleta inteira (`brand-50` a `brand-950`) sai dela

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

### Tema e componentes (`@/ui`)

O painel é montado com a biblioteca em `resources/js/ui`, que os seus módulos podem usar para ficar com o mesmo visual. Todos os componentes seguem a cor da marca, funcionam nos temas claro e escuro, são acessíveis por teclado e respeitam "reduzir movimento" do sistema operacional.

```ts
import { PageHeader, Card, Button, DataTable, Badge, Tabs, Modal, Drawer, TextInput, toast, confirm, prompt } from '@/ui';
```

| Grupo | Componentes |
|---|---|
| Layout | `PageHeader`, `Card`, `Tabs`, `Modal`, `Drawer`, `EmptyState`, `Alert` |
| Dados | `DataTable` (ordenação, carregamento, estado vazio e cartões no celular), `Pagination`, `StatCard`, `AnimatedNumber`, `Badge`, `Progress`, `Avatar`, `CopyField` |
| Formulário | `TextInput`, `Select`, `Textarea`, `Switch`, `Checkbox`, `Field` (rótulo, dica e erro para um campo próprio) |
| Ações | `Button` (vira link com `to` ou `href`), `Dropdown`, `Tooltip` |
| Retorno | `toast.success/error/info/warning(título, { description })`, `confirm({ title, tone: 'danger', onConfirm })`, `prompt({ title, input: { type: 'number' } })` |
| Utilitários | `format.money`, `format.date`, `format.relative`, `chartOptions()` (ApexCharts no tema), `inputClasses()` |

`confirm` e `prompt` devolvem uma promessa. Com `onConfirm`, a ação roda dentro do diálogo: se ela lançar um erro, a mensagem aparece na própria janela e o diálogo continua aberto.

```ts
const ok = await confirm({
    title: 'Excluir relatório?',
    tone: 'danger',
    confirmText: 'Excluir',
    onConfirm: () => axios.delete(`/relatorios/${id}`),
});
if (ok) toast.success('Relatório excluído');
```

Use as cores `brand-*` (e não `indigo-*`) para o seu módulo acompanhar a marca configurada. Os componentes antigos em `views/componentes/` continuam funcionando, como adaptadores dos novos.

### Encaixando seus módulos

Tudo é declarado em `resources/js/api-key.config.ts`:

```ts
import { PhChartBar } from '@phosphor-icons/vue';

export default {
    app: { logoText: 'Minha Empresa', logoHref: '/' },
    menu: {
        // `section` cria um cabeçalho próprio no menu; `badge` mostra um selo.
        extraItems: [{ name: 'Relatórios', to: '/dashboard/relatorios', icon: PhChartBar, section: 'Análises', badge: 'Novo' }],
        // `ability` restringe pela permissão da equipe: view, accounts, billing ou manage.
        extraAdminItems: [{ name: 'Faturas fiscais', to: '/dashboard/admin/fiscal', icon: PhChartBar, ability: 'billing' }],
    },
    routes: {
        extraDashboardRoutes: [
            { path: 'relatorios', name: 'relatorios', component: () => import('@/views/relatorios.vue') },
            { path: 'admin/fiscal', name: 'admin.fiscal', component: () => import('@/views/fiscal.vue'), meta: { requiresAdmin: true, ability: 'billing' } },
        ],
        extraPublicRoutes: [],
    },
    // Aparecem na busca rápida (Ctrl+K), junto das páginas do menu.
    commands: [{ id: 'relatorios.novo', label: 'Novo relatório', group: 'Relatórios', to: '/dashboard/relatorios/novo' }],
    // Texto do painel da marca nas telas de login e cadastro.
    auth: { headline: 'Os dados da sua empresa, num lugar só.', bullets: ['…'] },
    // Cartões do app no perfil, depois dos do pacote. Só o componente vai para a
    // aba "Dados pessoais"; `tab` escolhe outra: personal, address, notifications (só o dono vê) ou privacy. (`key` é obsoleto e cai em personal.)
    profile: {
        extraCards: [
            defineAsyncComponent(() => import('@/views/profile/DocumentosCard.vue')),
            { component: defineAsyncComponent(() => import('@/views/profile/ConsentCard.vue')), tab: 'privacy' },
        ],
    },
};
```

As páginas novas entram dentro do layout do painel, com transição, menu lateral recolhível e busca rápida. Comandos também podem ser registrados em tempo de execução com `registerCommands([...])`, que devolve uma função para removê-los.

---

## Testes

```bash
# Rodar todos os testes
vendor/bin/pest

# Filtrar por suite
vendor/bin/pest --filter="UserPlan"

# Relatório de cobertura
vendor/bin/pest --coverage

# Análise estática e estilo
composer analyse
composer lint-test

# Frontend: checagem de tipos e build do dist/ (o CI confere se o dist/
# commitado bate com o build)
npm run typecheck
npm run build
```

A suíte roda em **PostgreSQL**, o banco de produção, e precisa da extensão `pdo_pgsql`. As migrations têm caminhos por driver e várias consultas dependem do banco; o SQLite em memória, usado antes, deixava passar o que só quebrava em produção.

Por padrão (`phpunit.xml`) os testes conectam em `127.0.0.1:5432`, banco `testing`, usuário e senha `postgres`. Variáveis `DB_*` já definidas no ambiente prevalecem. O jeito mais simples é um contêiner descartável, em memória:

```bash
docker run -d --rm --name api-key-test -p 127.0.0.1:5432:5432 \
  -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=testing \
  --tmpfs /var/lib/postgresql/data postgres:17

vendor/bin/pest

docker stop api-key-test
```

> **Não aponte os testes para um banco com dados.** O `RefreshDatabase` apaga e recria todas as tabelas do banco configurado. Se a porta 5432 já tem um PostgreSQL de verdade, suba o contêiner em outra porta (`-p 127.0.0.1:55432:5432`) e rode `DB_PORT=55432 vendor/bin/pest`.

---

## Segurança

- API keys são armazenadas como hashes bcrypt
- Endpoints de autenticação têm rate limiting por padrão
- Header de origem é validado por API key
- Redefinição de senha usa o mecanismo de URL assinada do Laravel
- Assinaturas de webhook são verificadas via HMAC

Reporte problemas de segurança pelo relato privado do GitHub (aba Security) ou para apps@risetech.com.br, nunca numa issue pública. Detalhes em [SECURITY.md](SECURITY.md).

---

## Créditos

- [Rise Tech](https://github.com/risetechapps)
- [Todos os contribuidores](../../contributors)

## Licença

The MIT License (MIT). Consulte o [arquivo de licença](LICENSE.md) para mais informações.
