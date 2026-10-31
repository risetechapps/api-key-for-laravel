# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

## [1.2.0] - 2026-10-30

### Atualização

Passo a passo em [UPGRADE.md](UPGRADE.md).

- **Antes do `migrate`, confira e-mails e CPFs repetidos** (consultas no UPGRADE.md). Há índices únicos novos no e-mail e no CPF de `authentications`; com repetidos no banco, a migration para sem mexer em nada e diz quantos são
- Rode `php artisan migrate`: nova tabela `coupon_redemptions` e os dois índices
- **Com 2FA, as ações sensíveis do painel pedem um código.** Desligar a 2FA, gerar códigos de recuperação, trocar senha, trocar e-mail e excluir a conta passam a pedir `code` ou `recovery_code` junto da senha quando a conta tem 2FA. Quem chama essas rotas por conta própria precisa mandar o campo
- **Status HTTP do painel.** As rotas do painel e de login deixam de responder 410 (ver "Alterado"). Se o seu front tratava 410, confira
- **Login no painel vence em 30 dias** (`API_KEY_SESSION_LIFETIME_DAYS`; 0 = sem validade). Vale para os logins novos; os tokens de antes seguem sem validade
- **Cada conta usa o mesmo cupom uma vez** (`API_KEY_COUPON_MAX_USES_PER_USER`, padrão 1). Os usos anteriores à atualização não foram registrados e não contam. Para manter o comportamento antigo, `0`
- **Limite por minuto por conta.** Com várias chaves, o limite do plano passa a valer para a soma delas
- **Teto por IP nos avisos de pagamento sem assinatura** (20 por minuto). Atrás de proxy ou load balancer, configure o TrustProxies, senão todos os avisos parecem vir do mesmo IP
- Se você publicou os assets do painel, publique de novo: `php artisan vendor:publish --tag=api-key-assets --force`

### Corrigido (segurança)

Chaves e limites

- **Chave regenerada continuava valendo por até 5 minutos.** A regeneração mantém o id da chave, e o cache de validação (`cache_ttl.validation`, padrão 300s) ligava o segredo ao id: o segredo antigo seguia aceito até o cache expirar. O cache agora confere também o `lookup_hash`, e o segredo antigo para de valer na hora
- **Limite por minuto contado por chave.** O contador era por chave: com 5 chaves, o cliente tinha 5 vezes o limite do plano. O limite do plano agora conta para a conta inteira; o da chave, quando menor, conta à parte
- **Limite por minuto da chave acima do plano.** A chave aceitava qualquer `rate_limit_per_minute` e ele vencia o do plano. Agora o painel recusa valor acima do plano, e o middleware corta no plano
- **`POST /dashboard/profile/allowed` gravava sem validar** (aceitava array aninhado de qualquer tamanho). Valida como a tela de API keys: lista de textos de até 255 caracteres, no máximo 50 (`ApiKey::MAX_ALLOWED_ORIGINS`, que passa a valer também em `/dashboard/api-keys`)

Conta e 2FA

- **Com 2FA, senha e sessão bastavam para contornar a 2FA ou tomar a conta.** Desligar a 2FA, gerar códigos de recuperação novos (que entram no login sem o aplicativo), trocar o e-mail (e, com ele, a recuperação da conta), trocar a senha e excluir a conta pediam só a senha. Com a 2FA confirmada, pedem também `code` ou `recovery_code`; a configuração ainda não confirmada continua saindo só com a senha. O painel ganhou o campo do código nessas telas. Nova `Services\SecondFactorService`, que o `TwoFactorController` também passa a usar
- **Código da 2FA adivinhável com muitos IPs.** O segundo passo do login só tinha o limite por IP (5 por minuto): quem já tinha a senha e muitos IPs conseguia testar códigos o bastante para acertar. Agora há também um limite por conta, somando qualquer IP e valendo para o login e para as ações do painel acima: 10 códigos errados bloqueiam por 15 minutos, inclusive o código certo (`two_factor.max_attempts` e `lockout_minutes`, `API_KEY_2FA_MAX_ATTEMPTS` e `API_KEY_2FA_LOCKOUT_MINUTES`; 0 desliga). Bloqueado, responde 429 com `Retry-After`. O dono recebe um e-mail (`AccountSecurityNotification::TWO_FACTOR_LOCKED`), porque só chega ali quem acertou a senha, e a auditoria registra `account.two_factor_locked`
- **Mesmo código de recuperação aceito duas vezes em paralelo.** Duas requisições simultâneas liam a lista antes de uma delas riscar o código. A leitura agora trava a conta
- **Login no painel não vencia.** O token Sanctum saía sem validade, e um token vazado valia até alguém revogá-lo na tela de sessões. Agora vence em `sessions.lifetime_days` (padrão 30, `API_KEY_SESSION_LIFETIME_DAYS`; 0 = sem validade), a tela de sessões não lista os vencidos e o `sanctum:prune-expired` roda todo dia às 03:15
- **Duas contas com o mesmo e-mail.** A unicidade era só da validação: dois cadastros ao mesmo tempo passavam os dois. Novo índice único em `LOWER(email)`; a corrida que sobra responde 422, não 500
- **Dois perfis com o mesmo CPF.** Também só a validação conferia, e comparando o texto: "529.982.247-25" e "52998224725" passavam como CPFs diferentes. O perfil agora grava só os dígitos, e um índice único (só dígitos, CPF vazio fora) recusa o repetido com 422. Perfil e endereço passam a ser gravados numa transação
- **Mesmo convite de equipe aceito duas vezes.** Dois aceites simultâneos viravam dois membros. O aceite agora marca o convite antes de criar a conta, e só se ninguém marcou
- **Exportação de dados sem limite.** `GET /dashboard/profile/export` lê a conta inteira, logs inclusive, a cada chamada. Passa pelo `export_throttle` (5 por hora, por usuário; `API_KEY_EXPORT_THROTTLE_ATTEMPTS` e `API_KEY_EXPORT_THROTTLE_DECAY`)

Cupons e planos

- **Cupons visíveis para qualquer cliente.** `GET /dashboard/coupons` e `/dashboard/coupons/{id}` respondiam a qualquer conta logada, o que entregava os códigos (inclusive de 100%). Passam a exigir `admin:view`
- **Mesmo cupom reusado pela mesma conta.** Só o `max_uses` total era conferido. Novo limite por conta (`coupons.max_uses_per_user`), reservado e devolvido junto com o uso do cupom. `CouponRepository::claimUse()` e `releaseUse()` ganham o parâmetro opcional `$authenticationId`, e o contrato ganha `usedUpBy()`
- **Plano inativo visível por id.** `GET /dashboard/plans/{plan}` mostra plano inativo só para a equipe do painel

Webhooks e gateways

- **Filtro de rede interna do webhook.** Além das faixas privadas e reservadas, recusa CGNAT (`100.64.0.0/10`), NAT64, SIIT, 6to4 e Teredo (que embutem um IPv4 qualquer, inclusive interno), site-local (`fec0::/10`), faixas de teste e documentação e multicast. Nova `WebhookUrlGuard::isPublic()`
- **Teste e reenvio de webhook sem limite.** Passam pelo `webhook_test_throttle` (10 por minuto, por usuário)
- **Avisos de pagamento sem assinatura gastavam o limite da conta no gateway.** Cada aviso, fácil de forjar, virava uma consulta à API: o IPN do Mercado Pago, as notificações da Efí e as do Pagar.me sem usuário e senha do webhook. Agora as consultas têm teto (`MP_IPN_LOOKUPS_PER_MINUTE` e `API_KEY_UNSIGNED_NOTIFICATION_LOOKUPS_PER_MINUTE`, padrão 60): acima dele, 503 e o gateway reentrega depois. Para um flood não barrar os avisos verdadeiros, cada IP tem um teto próprio dentro do geral (`API_KEY_UNSIGNED_NOTIFICATION_LOOKUPS_PER_IP_PER_MINUTE`, padrão 20), e IPs em `API_KEY_NOTIFICATION_TRUSTED_IPS` não entram em teto. O mesmo pagamento com status final não é consultado de novo por IPN dentro de um minuto. Webhook assinado (Mercado Pago, Stripe, PagBank, Pagar.me com usuário e senha) não muda. `CardGateway` ganha `webhooksAuthenticated()`

### Alterado

- **Fim do 410 no painel.** Erro inesperado responde 500; login com senha errada e link de redefinição inválido, 422; conta não verificada, 403; plano ou chave que não existe, 404; logs sem o monitoring instalado, 503. O desafio de 2FA vencido continua 410, que é o que ele é (o painel usa esse status para voltar à senha)

### Corrigido

- **Cupom recusado na hora de pagar virava cobrança cheia.** Se o cupom esgotava ou já tinha sido usado pela conta (outra aba) entre a validação e o pagamento, o checkout cobrava o preço sem desconto. Agora responde 422 com o motivo e não cobra
- **Front não compilava em TypeScript estrito.** Apps que compilam os `.vue` do pacote com `strict` (o padrão dos projetos Vue/Vite) recebiam erro em `ui/Dropdown.vue` (`position` com `left?: undefined`) e em `views/dashboard/team.vue` (papéis com `admin?: undefined`). Corrigidos, junto com dois acessos por índice sem checagem (`ui/Tabs.vue` e o nome do papel em `team.vue`). O `tsconfig.json` do pacote passa a ser `strict` com `noUncheckedIndexedAccess`, `router/index.ts` e `stores/admin.ts` deixam de ter `@ts-nocheck`, e o `typecheck` do CI pega isso daqui para frente
- `resources/js/router/index.ts` importa `RouteRecordRaw` como tipo

## [1.1.0] - 2026-10-29

### Atualização

Passo a passo em [UPGRADE.md](UPGRADE.md).

- **Classes removidas.** Veja "Removido": se o seu app usava alguma delas, troque antes de atualizar
- **Webhook do Mercado Pago com tolerância de replay ligada (300s).** Antes vinha desligada. Confira que o relógio do servidor está sincronizado (NTP); para manter o comportamento antigo, `MP_WEBHOOK_TOLERANCE_SECONDS=0`
- Rode `php artisan migrate`: nova coluna `usage_notices_enabled` em `authentications` e nova coluna `api_key_id` (com índice) em `request_logs`. Em tabela de log muito grande, a criação do índice pode levar alguns minutos e segura as escritas nela; rode fora do horário de pico
- Rode o agendador (`schedule:run`): o aviso de chave vencendo é uma rotina nova, diária às 07:00, e o `api-key:check-expired-plans` passou a ser agendado pelo pacote. Se você agendava esse comando por conta própria, remova o seu agendamento
- **`WebhookDispatcher::dispatch()` só envia evento registrado.** Antes, qualquer nome saía para os endpoints marcados com "Todos os eventos". Agora um evento fora do registro (pacote, `api-key.webhooks.events` ou `registerEvents()`) não é enviado: devolve 0 e o erro vai para o log. Se o seu app dispara eventos próprios, registre-os
- **Lista de origens da chave:** entradas de IP agora só conferem o IP da conexão, e entradas de domínio só o header `Origin` (ver "Corrigido"). Um painel servido direto de um IP (`http://10.0.0.5`) e liberado pela entrada `10.0.0.5` deixa de passar pelo `Origin`; libere pelo IP de quem acessa ou por um domínio

### Adicionado

- **`GET /api/v1/usage/daily?days=30`**: requisições por dia (1 a 90 dias) dividido em `success`, `client_error` e `server_error`, pela API key e sem gastar cota. O gráfico do painel passa a usar a mesma consulta (`Support\UsageHistory`), e cada ponto de `/dashboard/stats?days=` ganha os mesmos campos
- **Aviso de chave vencendo.** `api-key:notify-expiring` (diário, 07:00) dispara `ApiKeyExpiring`, com e-mail (`ApiKeyExpiringNotification`, substituível em `notifications.api_key_expiring`) e o webhook `api_key.expiring`, para chaves ativas que vencem dentro de `api_keys.expiry_warning_days` (padrão 7, `API_KEY_EXPIRY_WARNING_DAYS`). Uma vez por chave e por data de validade

- **Cota nas respostas.** Toda resposta do grupo `plan`, inclusive o 429 de cota esgotada, traz `X-Quota-Limit`, `X-Quota-Remaining`, `X-Quota-Used` e `X-Quota-Reset` (fim do período em epoch). Plano sem limite não manda `Limit` nem `Remaining`. Desligável com `API_KEY_QUOTA_HEADERS=false`
- **`GET /api/v1/usage`**, autenticado pela API key: plano, período, requisições, excedente, limite por minuto e a chave usada. Não consome a cota e responde mesmo com ela esgotada
- **Guia de webhooks para quem integra.** README com o passo a passo de validação (corpo bruto, HMAC em tempo constante, janela de 5 minutos, idempotência por `X-Webhook-Id`), exemplos em PHP e Node, prazos e novas tentativas. O `docs/openapi.yaml` ganha a seção `webhooks` com os 15 eventos, os headers e o `data` de cada um; testes conferem a lista contra `WebhookDispatcher::EVENTS` e o formato contra entregas reais. O cartão "Verificando a assinatura" do painel ganhou o passo da idempotência e o exemplo passou a conferir a janela de tempo
- **Webhooks com eventos do app.** O painel de webhooks aceitava só os eventos do pacote; cadastrar um evento do sistema que instala o pacote dava erro de validação. Agora `WebhookDispatcher::registerEvents('pedido.criado', ...)` (ou `api-key.webhooks.events` na config) registra eventos próprios, no formato `recurso.acao`: eles aparecem na lista do painel, podem ser assinados e saem com `WebhookDispatcher::dispatch($conta, 'pedido.criado', $dados)`, com a mesma assinatura HMAC, novas tentativas e histórico. Aceita descrição (`registerEvents(['pedido.criado' => 'Pedido criado'])`), mostrada no painel ao lado do nome; os eventos do pacote também ganharam descrição (`WebhookDispatcher::DESCRIPTIONS`), e `GET /dashboard/webhooks` traz `events_catalog` (nome e descrição) além de `available_events`. A lista completa fica em `WebhookDispatcher::events()` (e `catalog()`, com as descrições); `EVENTS` continua com os do pacote
- **Chave no log de requisições.** Cada linha de `request_logs` guarda a chave que fez a requisição (`api_key_id`, sem chave estrangeira: o log da chave excluída continua dizendo que foi ela). `GET /dashboard/log` traz `api_key` (`id`, `name`, `deleted`) e aceita `?api_key_id=` para filtrar; a tela Requisições ganha a coluna "Chave" e o filtro por chave, e a exportação de dados leva o `api_key_id`. Serve para ver o que uma chave vazada fez antes de ser regenerada (a regeneração mantém o id da chave; o horário da troca está na auditoria, `api_key.regenerated`). Linhas antigas ficam com `api_key` null. `LogApiRequestJob` e `Authentication::requestUsed()` ganham o parâmetro opcional `$apiKeyId` no fim. O filtro da tela também lista as chaves já excluídas (`GET /dashboard/api-keys/revoked`, com id e nome guardados na auditoria na exclusão)
- **Preferência de avisos por e-mail.** Nova aba Perfil → Notificações (só o dono da conta) e `PUT /api/v1/dashboard/profile/notifications` (`usage_notices`) desligam os avisos informativos: uso em 80%, chave vencendo e excedente iniciado. Cobrança, segurança, limite atingido, carência e expiração continuam sempre ligados; eventos e webhooks não mudam. Coluna `authentications.usage_notices_enabled` (migration nova, ligada por padrão) e trait `Notifications\Concerns\InformationalNotice`, herdado por classes que estendem as do pacote. O perfil passa a trazer `notifications.usage_notices`, e a troca fica na auditoria (`account.notification_preferences_changed`)
- **Especificação OpenAPI 3.1** da API do cliente em `docs/openapi.yaml`: autenticação, `/usage`, `/usage/daily`, headers e todos os `error_code`. Testes conferem o documento contra `ApiError` e contra a resposta do `/usage`

### Alterado

- **Comandos com o prefixo do pacote.** `admin:make` virou `api-key:admin-make`, `billing:process-renewals` virou `api-key:billing-process-renewals` e `billing:charge-overage` virou `api-key:billing-charge-overage`, como os demais (`api-key:*`). O agendamento do pacote já usa os nomes novos; os antigos continuam funcionando (ver "Obsoleto") e a tela Saúde conta a execução por qualquer um dos dois
- **Perfil sem a aba "API key principal".** Ela fazia, só para a chave mais antiga, o que a tela API Keys faz para todas (regenerar e origens permitidas, sem validação). As chaves ficam só em API Keys. Cartão extra do app configurado com `tab: 'key'` (ou sem `tab`) passa a aparecer em Dados pessoais

### Obsoleto

Continuam funcionando na 1.x e saem na próxima versão maior:

- `POST /dashboard/profile/regenerate-key`: use `POST /dashboard/api-keys/{id}/regenerate`. Passa a registrar `api_key.regenerated` na auditoria, que antes ficava sem registro por esta rota
- `GET` e `POST /dashboard/profile/allowed`: use `GET /dashboard/api-keys` e `PUT /dashboard/api-keys/{id}` (`allowed_origins`)
- Comandos `admin:make`, `billing:process-renewals` e `billing:charge-overage`: rodam normalmente e avisam para trocar pelos nomes `api-key:*`
- No painel: `updateAllowedOrigins()` e `fetchAllowedOrigins()` do store de autenticação, e `ProfileTab` `'key'`

### Removido (quebra compatibilidade)

Nada no pacote usava estas classes. Se o seu app usava alguma, troque antes de atualizar:

- `ApiKeyService::routes()`: use `RoutesApiKey::register()`, que era o que ele chamava
- `Traits\ApiResponseTrait`: chame `Http\Response\ApiResponse` direto (`success()`, `error()`, `apiError()` etc.)
- `Traits\HasApiKey` e `Scope\ApiKeyScope`: filtravam pela chave única da conta (`$user->apiKey`), modelo anterior às várias chaves; com mais de uma chave só a primeira entrava no filtro. Filtre pelo dono (`authentication_id`) num scope seu
- `Http\Resources\UserResource` e `Http\Resources\RequestLogResource`: o pacote responde com `Authentication\ProfileResource`, `Authentication\AuthenticationMeResource` e `Dashboard\Signature\LogHistoryResource`
- `Enums\BillingMethod`
- `ApiKey::isOriginAllowed()`: use `allowsRequest($origin, $ip)`. Era a comparação com a brecha descrita em "Corrigido"

### Corrigido

- **Visão geral sem plano.** Conta sem assinatura via "Ilimitado" no limite do plano e "∞" em restantes, porque limite 0 servia tanto para plano ilimitado quanto para nenhum plano. Agora mostra "Sem plano", "—" e o convite para escolher um plano
- **Admin sem 2FA nas telas de admin.** A tela abria, as chamadas voltavam 403 e algumas deixavam erro não tratado no console antes do redirecionamento. O router agora leva direto para Segurança antes de abrir a tela, e o redirecionamento do 403 não rejeita mais a chamada de quem está saindo da página
- Painel: `Avatar` com nome nulo e o papel no convite da equipe (`team.vue`) davam erro de tipo no `vue-tsc`
- **Expiração e aviso de carência agendados.** O `api-key:check-expired-plans` existia, mas nada o agendava: o aviso de carência (`PlanGracePeriodStarted`, e-mail e webhook `subscription.grace_period_started`) nunca saía, e uma assinatura só era encerrada quando o cliente fazia uma requisição — quem parou de chamar a API não recebia o aviso de expiração e ficava com as chaves ativas. Agora o aviso roda às 10:00 e o encerramento de hora em hora
- **Aviso de carência para quem não renovou.** O `--grace-only` avisava as assinaturas com vencimento no dia inteiro de hoje, inclusive as que ainda iam vencer e ser renovadas; agora avisa as que venceram nas últimas 24h e continuam ativas, uma vez por assinatura, e não avisa nada com `grace_period_days = 0`. O fim da carência no aviso é contado do vencimento, não da hora em que o comando rodou
- **Header `Origin` furava a lista de IPs da chave.** O valor do `Origin` era comparado com a lista inteira, então `Origin: http://<IP permitido>` passava de qualquer lugar. Agora domínio confere só o `Origin` e IP só o IP da conexão (`ApiKey::allowsRequest()`, que substitui o `isOriginAllowed()`, removido). A lista passa a aceitar faixas CIDR (IPv4 e IPv6), e um servidor no IP permitido que manda algum `Origin` deixa de ser recusado
- README: o comando aparecia como `apikey:check-expired`, nome que não existe
- **E-mail de confirmação de cadastro assinava "Equipe do Seu Site"**, texto de exemplo que ficou no código; agora usa o `app.name`, como os outros
- **E-mail de plano ativado dizia "Limite de requisições: 0" em plano ilimitado** (`request_limit` 0); agora diz "Requisições ilimitadas"
- Painel: a ajuda do campo "Origens permitidas" (perfil e API keys) explica que domínio confere o navegador e IP confere o servidor, e cita faixa CIDR
- **Webhooks de saída presos aos IPs conferidos.** A entrega resolvia o DNS de novo depois da checagem contra rede privada, o que deixava um domínio com TTL zero apontar para a rede interna entre uma e outra (DNS rebinding). Agora a conexão usa `CURLOPT_RESOLVE` com os IPs validados

### Interno

- **Teste que monta cada e-mail.** Os testes de fluxo usam `Notification::fake()`, que nunca chama `toMail()`: um erro no texto só aparecia em produção, com o e-mail falhando dentro da fila. `NotificationRenderingTest` renderiza o HTML de todas as notificações, em cada variação, e falha se uma notificação nova ficar de fora
- **Testes só em PostgreSQL.** A matriz do CI (PHP 8.4/8.5, prefer-lowest/stable) roda contra um PostgreSQL 17, e o job separado que rodava em PostgreSQL sem bloquear o merge (`continue-on-error`) deixou de existir. Localmente a suíte também precisa de um PostgreSQL (ver "Testes" no README). As factories de `Authentication`, `Plan` e `ApiKey` deixaram de inventar o `code` (UUID ou 4 caracteres aleatórios) e o `code-generate` gera o sequencial, como em produção: um valor como `3e45…` vira `PHP_INT_MAX` no cálculo da sequência e o PostgreSQL recusava o contador, o que derrubava testes ao acaso. Com isso o `code-generate.throw_on_error = false` dos testes saiu
- `composer.lock` deixou de ser versionado: é pacote, quem instala resolve pelo `composer.json` e o CI já roda `composer update`
- `symfony/yaml` em `require-dev`, para os testes do documento OpenAPI

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
