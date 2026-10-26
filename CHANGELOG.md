# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

## [1.0.1] - 2026-10-25

### Atualização

- Rode `php artisan migrate`: nova coluna `ip` em `request_logs`
- Quem publicou a config: troque `'language'` por `'language:en'` em `middleware_group.plan` (ver abaixo)

### Adicionado

- **IP de cada requisição.** `request_logs` ganha a coluna `ip`, gravada com `$request->ip()` — o mesmo IP que a validação de origem confere, que só segue `X-Forwarded-For` de proxy listado no `TrustProxies`. Vale para o log pela fila (`LogApiRequestJob`) e depois da resposta (`requestUsed()`)
- Painel: coluna IP na lista de requisições, IP de origem nos detalhes e busca por endpoint ou IP. `/dashboard/log` devolve `request.ip`
- A exportação de dados da conta inclui o IP de cada requisição
- Requisições registradas antes da atualização ficam sem IP; job já enfileirado na hora do deploy grava `null`

### Alterado (quebra compatibilidade)

- **Idioma fixo por superfície.** O `Accept-Language` deixou de ser lido: painel e rotas embutidas respondem sempre em português; a API do cliente (grupo `plan`), sempre em inglês. O middleware `language` aceita o idioma como parâmetro (`language:pt`, `language:en`) e a config `default_language` (`API_KEY_DEFAULT_LANGUAGE`) foi removida
- **Formato único nas recusas da API.** `api.key`, `api.key.rate`, `check.active.plan`, `check.limit.plan`, `api.key.origin` e `feature` respondem `{success: false, message, error_code}`, com `error_code` estável (ver "Respostas de erro da API" no README). Sai o campo `error`, o `status` (`forbidden_scope`, `upgrade_required`) e o `grace_period_ended`
- `feature` responde JSON (402) também para cliente que não manda `Accept: application/json`, em vez de página de erro
- Quem publicou a config: troque `'language'` por `'language:en'` em `middleware_group.plan`, senão as rotas do app nesse grupo passam a rodar em português (as recusas do pacote continuam em inglês)

## [1.0.0] - 2026-10-21

Primeiro lançamento oficial.

As versões anteriores (1.0.0 a 2.1.1) serviram ao desenvolvimento e à distribuição de teste, não representam versões publicadas e foram removidas junto com o histórico. O histórico começa aqui.

### Requisitos

- PHP ^8.4, Laravel ^12, Laravel Sanctum ^4.0
- Dependências da RiseTech (`address`, `code-generate`, `form-request`, `media`, `monitoring`, `repository`, `to-upper`, `view-suite`) distribuídas pelo Repman da RiseTech: **o projeto que instala o pacote precisa declarar o repositório do Repman** (ver Instalação no README). `risetools` vem do Packagist

### API keys

- Chaves com hash bcrypt e `lookup_hash` para a busca, nunca armazenadas em texto puro
- Várias chaves por conta, cada uma com nome, permissões por feature, origens permitidas, validade e limite por minuto
- Validação de origem, throttling com contadores atômicos e cache em Redis/Memcached
- `legacy_scan.enabled` (`API_KEY_LEGACY_SCAN_ENABLED`, padrão `true`): fallback para chaves sem `lookup_hash`. Depois de `api-key:rotate-keys --legacy`, desligue; o `api-key:check` diz quando não resta chave legada

### Planos e cobrança

- Planos com ciclos de cobrança, cota de requisições, limite por minuto e feature flags; `FeatureRegistry` para declarar features em código, sincronizadas no banco
- Período de carência, período de teste (um por conta), cobrança por excedente e novas tentativas de renovação com aviso por e-mail
- Cupons com limite de usos, validade e desconto percentual, consumidos de forma atômica
- Gateways Mercado Pago, Stripe, Efí e PagBank (e Pagar.me para contas existentes), um ativo por vez e trocado pelo painel admin
- PIX com QR code e confirmação automática; cartões salvos com cobrança sem CVV
- Webhooks dos gateways conferidos na API do gateway, com idempotência. O webhook do Stripe exige `STRIPE_WEBHOOK_SECRET`; sem ele é recusado com 401, e a tela de saúde e o `api-key:check` acusam a falta
- Pagamentos em análise resolvidos por webhook ou por reconciliação agendada
- Cancelamento pelo assinante sem perder o período pago, com reativação; política de reembolso com janela de arrependimento e teto de consumo
- Recibos de cada pagamento

### Conta e painel

- Painel SPA em Vue 3 com assets pré-compilados em `dist/`: o servidor não precisa de Node.js
- Equipe da conta e membros de integração
- Troca de senha e de e-mail, sessões abertas, verificação em duas etapas (TOTP, obrigatória para admin), redefinição de senha e verificação de e-mail apontando para a SPA
- Webhooks de saída assinados com HMAC, com novas tentativas e histórico
- Painel admin com métricas (MRR, cancelamentos, conversão de teste, receita), auditoria e tela de logs
- Inglês e português (pt-BR), detectado pelo `Accept-Language`

### Operação

- Eventos (`PlanChanged`, `PlanExpired`, `PlanGracePeriodStarted`, `PlanCancelled`, `PlanRefunded`, `PaymentRejected`, `RequestLimitReached`, `PlanUsageThresholdReached`, `UserStatusChanged`) e notificações por e-mail substituíveis por config
- Logs gravados em banco pelo `monitoring-for-laravel`
- `api-key:check` aponta fila sem worker, cron parado, credenciais faltando e chaves legadas
- Rotas restringem o formato dos ids (`whereUuid`, `whereNumber`) e respondem 404 em vez de erro no PostgreSQL
- Models com o `HasUuids` nativo do Laravel (UUID v7)

### Qualidade

- Testes Pest em SQLite e PostgreSQL, Larastan nível 5 sem baseline, Pint, cobertura mínima de 80% no CI
- Testes do painel com Vitest, checagem de tipos com `vue-tsc` e verificação de que o `dist/` commitado bate com o build
