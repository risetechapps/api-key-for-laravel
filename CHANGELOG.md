# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

## [Unreleased]

## [2.1.0] - 2026-10-12

### Atenção ao atualizar

- **Membros de integração.** Nova migration `2026_10_12_000002` (`external_id` em `authentications`, único por conta).
- **Equipe da conta.** Nova migration `2026_10_12_000001` (`account_id` e `team_role` em `authentications`, `team_members_limit` em `plans`, tabela `team_invitations`). Planos existentes ficam com `team_members_limit = 0` (só o dono); defina o limite em Admin → Planos.
- **Membro age como o dono.** Nas rotas do painel, o `active.user` troca o usuário da requisição pelo dono da conta quando quem entrou é membro da equipe. Código do app que usa `auth()->user()->id` como dono dos dados continua certo. O membro de fato fica em `Support\Team::actor()`.
- **Atendente é negado por padrão.** Rota do app sem marcação vale para dono e admin da equipe. Para liberar a atendentes, marque com `team.role:agent`; para só o dono, `team.role:owner`; para rota pessoal (o membro como ele mesmo), `team.self`. Rotas com o middleware `admin` da plataforma nunca valem para membros.
- **SPA publicado.** Quem publicou o SPA (`api-key-spa`) e o customizou precisa trazer as mudanças em `stores/auth.ts` (`team`, `teamCan`, `acceptInvitation`, `/auth/me` depois do login), `navigation.ts`, `router/index.ts` (`meta.teamRole`, rota `/invite/:token`, guard da equipe), `api-key.config.ts` (`app.teamHome`) e as telas novas `views/dashboard/team.vue` e `views/authentication/acceptInvite.vue`.

### Adicionado

- **Equipe da conta.** O dono convida pessoas por e-mail (nome, e-mail e papel); o convite vale 7 dias e a pessoa cria a própria senha pelo link (`/invite/{token}`). Papéis: **dono** (tudo), **administrador** (tudo menos plano, cobrança e excluir a conta; convida e gerencia atendentes) e **atendente** (só as rotas que o app libera). O plano limita quantos membros (convites pendentes contam). Tela Equipe no painel, `/dashboard/team` na API, campo "Membros na equipe" no cadastro de planos, auditoria de convite, entrada, troca de papel e remoção. Remover um membro exclui a conta dele (sessões encerradas, e-mail liberado); excluir a conta do dono leva a equipe junto. `/auth/me` traz `team` (papel e conta) e, para o membro, o plano e o uso da conta.
- **`GET /dashboard/team/members`**: quem está na equipe (nome e papel), liberado a atendentes, para o app atribuir trabalho.
- **Membros de integração.** `TeamService::provisionExternal($dono, $externalId, $nome, $papel)` cria (ou atualiza) um membro identificado pelo id dele no sistema do cliente (`authentications.external_id`, migration `2026_10_12_000002`), sem convite e sem login: o e-mail é fictício, a senha é aleatória e o `/login` recusa esses membros. Contam no limite de equipe. Na tela Equipe aparecem com o selo "Integração"; `/dashboard/team` devolve `external_id` e esconde o e-mail deles.
- **`SystemHealthService::extend()`**: o app acrescenta as próprias verificações à Saúde do sistema (admin), num grupo próprio. Uma verificação que falha vira aviso, sem derrubar a tela.

### Corrigido

- As origens permitidas (`/dashboard/profile/allowed`) quebravam para conta sem chave de API (ex.: admin da plataforma): a leitura devolvia erro e gravava exceção no log. Agora a leitura devolve lista vazia e a gravação responde que não há chave.
- O painel ficava sem plano até recarregar depois do login: o `/login` devolve o usuário sem o plano, e o SPA agora completa com o `/auth/me` (também depois da 2FA).

## [2.0.0]

### Atenção ao atualizar

- **Rode as migrations com a base limpa de duplicatas.** A nova migration põe índice único em `user_plans.payment_id` e para, listando os ids, se encontrar o mesmo pagamento em mais de uma assinatura. Resolva manualmente (uma assinatura por pagamento) e rode de novo
- **O `status` do usuário passa a valer.** Contas com `status` `disabled` ou `blocked` deixam de entrar no painel e de usar a API key. Antes o campo era gravado e ignorado; confira se não há contas marcadas assim por engano. `null` conta como habilitada
- **Lista de IPs permitidos atrás de proxy.** Sem header `Origin`, o IP comparado agora vem de `$request->ip()`. Atrás de Cloudflare ou de um proxy reverso, configure o `TrustProxies`, ou todas as requisições vão aparecer com o IP do proxy
- **Idioma padrão.** Requisição sem `Accept-Language` agora responde no `default_language` da config (padrão `pt`); antes respondia em inglês
- **Regras de login no banco.** Se a tabela `form_requests` tiver a regra `login` gravada, o `exists` do e-mail é ignorado pelo `LoginRequest`, mas convém tirá-lo de lá também
- **2FA obrigatória para admin.** Com `API_KEY_2FA_REQUIRED_FOR_ADMINS=true` (padrão), as rotas de admin respondem 403 até o admin ativar a verificação em duas etapas em Painel → Segurança. Ative antes de atualizar em produção, ou desligue a opção por enquanto
- **Grupo `plan` customizado.** O limite por minuto entra pelo middleware `api.key.rate`. Quem define `middleware_group.plan` na config precisa incluí-lo, logo depois de `api.key`
- **Notificações dos Pagamentos Automáticos.** Para a cobrança sem CVV, habilite no Mercado Pago as notificações de **Order** e de **Perfis de pagamento** para a URL do webhook. A cobrança pela Orders API é confirmada por elas
- **Permissões do painel admin.** Rotas de leitura passaram a aceitar os papéis `finance` e `support`. Quem estende o middleware `admin` numa rota própria continua exigindo o papel `admin` (sem parâmetro vale `manage`). A tela de saúde guarda o último webhook e a última execução das rotinas no cache: com `CACHE_STORE=array` eles aparecem como "sem registro"
- **Uma chave por conta deixou de ser regra.** `apiKey()` continua devolvendo a chave principal (a mais antiga); código que precise de todas usa `apiKeys()`. O vencimento do plano agora desativa todas as chaves da conta

### Segurança

- Login, esqueci a senha e reenvio de verificação respondem igual para e-mail cadastrado ou não. O login deixou de responder "usuário não encontrado" (inclusive pela regra `exists` da validação), confere o hash mesmo sem usuário para o tempo de resposta não denunciar, e só reenvia a verificação depois de conferir a senha
- Limite de tentativas em `forgot-password`, `reset-password` e no novo `email/resend`, junto com login e registro. Novo `payment_throttle` (por usuário) em checkout, validação de cupom e cadastro de cartão, contra teste de cartões roubados e força bruta de cupom. Cada grupo tem contador próprio
- Lista de IPs permitidos não pode mais ser contornada com `X-Forwarded-For` ou `CF-Connecting-IP` forjados: o IP vem de `$request->ip()`, que respeita o `TrustProxies`
- Curinga à direita (`app.example.*`) vale por um rótulo só e não casa mais `app.example.evil.com`
- Bypass por `X-Internal-Token` exige loopback em `REMOTE_ADDR` e em `$request->ip()`
- Webhook com assinatura confere `data.id` em minúsculas e pode recusar `ts` antigo (`MP_WEBHOOK_TOLERANCE_SECONDS`, desligado por padrão)
- Painel admin: o nome do usuário era interpolado sem escape no diálogo de estorno (XSS armazenado na sessão do admin)

### Cobrança

- `subscribeToPlan()` grava o rastro do pagamento no mesmo INSERT e é idempotente por `payment_id`, com lock por usuário: checkout, webhook e reconciliação confirmando o mesmo pagamento não criam mais duas assinaturas, e um webhook reentregue depois de estorno não reativa a assinatura devolvida
- Uma assinatura nova desativa todas as ativas do usuário, inclusive a que está em carência
- Estorno trava a assinatura até gravar o resultado: clique duplo ou duas abas não estornam duas vezes (`SubscriptionAlreadyRefundedException`)
- Webhook com id ausente ou de pagamento inexistente responde 200 e é descartado, em vez de 500 com reentrega infinita; falha do gateway responde 503 para o Mercado Pago tentar de novo
- `credit_applied` passa a ser gravado também quando quem confirma o pagamento é o webhook
- O cartão usado na compra continua sendo o padrão da conta: o checkout desmarcava todos e o `update()` no model não gravava a marcação de volta, deixando a renovação sem cartão para cobrar
- O checkout não oferece mais "ativar renovação automática" para um cartão que já renova sozinho
- Erro técnico de gateway nunca chega à tela: só `PaymentMessageException` (mensagens escritas para o cliente) tem o texto exibido; o resto vira a mensagem genérica, com o detalhe no log
- Efí: o `custom_id` vai sem `|` (a Efí só aceita letras, números, `_`, `-` e espaço, e respondia HTTP 500)
- Efí: o "Testar conexão" acusa identificador de conta no formato errado (número da conta em vez do código de 32 caracteres) e chave PIX aleatória que não é da conta
- Painel: a lista de cartões (e de planos) vazia não quebra mais o checkout; o `jsonSuccess` omite `data` vazio
- Crédito de troca de plano calculado sobre o que o período custou de fato (pago mais crédito usado), não sobre o preço de tabela: assinatura com cupom de 100%, concedida pelo admin ou em teste não gera crédito cheio. `credit_applied` grava só o crédito que a compra usou

### Contas

- Reenvio do e-mail de verificação (`POST email/resend`) e botão "Reenviar" na tela de login
- Admin suspende e reativa usuários (`PUT dashboard/admin/users/{id}/status`) e concede plano sem cobrança (`POST dashboard/admin/users/{id}/plan`). Suspender revoga os tokens; a API key é recusada até a reativação
- Exportação dos dados da conta em JSON (`GET dashboard/profile/export`) e exclusão da própria conta com senha (`DELETE dashboard/profile`), que anonimiza a conta e mantém só o registro dos pagamentos
- Middleware `active.user`, aplicado às rotas autenticadas do pacote; a SPA leva a conta suspensa de volta ao login com aviso
- `password_reset_invalid`: mensagem própria para token de redefinição inválido, que antes dizia "não foi possível enviar o link"

### Segurança da conta

- Troca de senha estando logado, que encerra as outras sessões
- Troca de e-mail com confirmação no endereço novo e aviso ao antigo
- Lista de sessões abertas (IP e navegador) com encerramento individual ou de todas as outras
- Verificação em duas etapas (TOTP) com códigos de recuperação, implementada sem dependência nova e obrigatória para admin por padrão
- E-mail de aviso a cada alteração de segurança

### Cobrança

- PIX no checkout, com QR code, consulta automática no painel e ativação pelo webhook. O QR é sempre gerado no painel e nunca enviado por e-mail
- Renovação que não pode ser cobrada automaticamente (sem cartão, assinante de PIX, ou conta do Mercado Pago que exige CVV em toda cobrança) não gera mais cobrança nem novas tentativas: o assinante é avisado e renova pelo painel em "Renovar agora". Excedente sem cobrança automática é pago pelo painel, com cartão salvo (`POST dashboard/billing/overage/{id}/card`; o Mercado Pago pede o CVV quando o cartão não cobra sozinho) ou PIX
- Novas tentativas da renovação recusada (`API_KEY_RENEWAL_RETRY_DAYS`, padrão 1, 3 e 5 dias), com e-mail a cada falha e cobrança imediata quando o assinante cadastra outro cartão. Cada tentativa usa uma chave de idempotência própria
- Cobrança sem CVV pelos **Pagamentos Automáticos** do Mercado Pago. Ao cadastrar o cartão, ou ao pagar com um cartão salvo digitando o CVV, o painel gera um segundo token e o pacote cria o perfil de pagamento do cartão. A renovação e o excedente de um cartão com perfil passam a ir pela Orders API, sem token e sem CVV, identificados pelo id da order (`ORD…`). Webhook (`order` e `payment_profile`), reconciliação e estorno tratam as orders. Depende de autorização da equipe Comercial do Mercado Pago: sem ela, o gateway responde 403 e a cobrança segue pelo caminho antigo. Ligado por padrão (`MP_AUTOMATIC_PAYMENTS_ENABLED`). Cartão sem perfil ganha o botão "Ativar renovação automática" em Faturamento, que pede só o CVV, e o painel oferece a ativação logo depois de uma compra que salvou cartão novo
- Remover um cartão, ou excluir a conta, apaga também o cartão e o perfil de pagamento no Mercado Pago. Antes saíam só do banco
- **Stripe, Efí e PagBank** como gateways, com cartão salvo cobrado sem CVV na renovação e no excedente, e PIX. Stripe: SetupIntent pelo Stripe Elements, PaymentIntent `off_session` na renovação e 3DS no checkout (`client_action`); webhook com `Stripe-Signature`. Efí: `payment_token` reutilizável pela API de Cobranças, PIX pela API Pix com o certificado da aplicação enviado pelo painel; cadastro de cartão exige perfil completo (CPF, celular, nascimento e endereço), que a Efí pede na cobrança. PagBank: cartão criptografado no navegador, recorrência `INITIAL`/`SUBSEQUENT`, notificação conferida pelo `x-authenticity-token`
- **Pagar.me** com cartão e PIX: cartão tokenizado no navegador, salvo com validação de valor zero e cobrado sem CVV (`recurrence_cycle` e `payment_origin`); webhook com Basic Auth opcional. Fica fora do seletor do painel por padrão (`payment.hidden_gateways`), porque o cadastro novo no Pagar.me vai para a Stone
- Só o gateway ativo cobra: depois de trocar de gateway, cartões salvos no anterior ficam fora de uso (`usable: false`) no checkout, na renovação e no excedente, até o assinante cadastrar um cartão novo. A renovação pede o pagamento pelo painel com o motivo `card_out_of_use`. Estorno e reconciliação de pagamentos antigos seguem pelo gateway de origem
- Gateways como drivers de `Contracts\CardGateway` em `GatewayRegistry`. Webhook único `checkout/webhook/{gateway}`, que só usa o corpo para saber qual pagamento consultar. O id do pagamento diz o gateway (`pi_`, `efi_`/`efipix_`, `ORDE_`, `or_`) para estorno, reconciliação e filtro de cobranças do admin. A consulta de status do PIX no painel pergunta ao gateway enquanto o QR está aberto, e o desfecho de um pagamento sem referência utilizável é achado pelo registro de espera (excedente incluído)
- **Gateway de pagamento pelo painel** (Admin → Gateways): gateway ativo, credenciais, teste de conexão e URLs de webhook. Os valores ficam criptografados no banco e valem por cima do `.env`. Cartões já salvos seguem no gateway que os guardou
- Renovação de plano gratuito não passa mais pelo gateway
- Período de teste por plano (`trial_days`), um por conta
- Cobrança por excedente (`overage_price`, `overage_max_requests`) com o comando `billing:charge-overage`, diário às 09:00. O excedente é **opt-in**: desligado por padrão para cada assinante, que liga em Faturamento e pode definir um teto de gasto próprio; ao entrar no excedente, recebe aviso por e-mail e webhook
- Recibo em HTML de cada pagamento e do excedente, com dados do emissor configuráveis

### API

- Várias API keys por conta, com nome, permissões por feature (`scopes`), origens, validade, limite por minuto e registro do último uso
- Limite de requisições por minuto por chave ou por plano (`api.key.rate`), antes da reserva de cota
- Webhooks de saída assinados (HMAC-SHA256), com novas tentativas, histórico de entregas, reenvio, rotação de segredo, desligamento automático depois de falhas seguidas e proteção contra SSRF

### Administração

- **Papéis na equipe:** `admin`, `finance` (cobranças e estornos) e `support` (ações de conta), com permissão por rota (`admin:view`, `admin:accounts`, `admin:billing`, `admin`). Troca de papel pela ficha do usuário; ninguém muda o próprio, e quem perde permissões tem as sessões encerradas
- **Ficha do usuário:** assinaturas, pagamentos, cartões (com gateway e renovação automática), API keys, sessões, excedente em aberto e o histórico da auditoria
- **Cobranças:** todos os pagamentos de todos os gateways numa tela, com filtros, totais por status e exportação
- **Inadimplência:** renovações em nova tentativa, renovações esperando o assinante e excedente não pago, com o total a recuperar
- **Ações da equipe**, todas na auditoria: cancelar a renovação, estender o vencimento, ajustar o consumo, tentar a renovação de novo, perdoar excedente, reenviar a verificação, encerrar sessões, desativar API keys e desligar a 2FA de quem perdeu o aparelho (só admin; o titular é avisado por e-mail)
- **Exportação em CSV** de cobranças, estornos e usuários, com proteção contra fórmula em campo de texto
- **Saúde do sistema:** gateway ativo e credenciais, último webhook de cada gateway, última execução de cada rotina agendada, fila (falhas e jobs parados), pagamentos em análise há mais de 2 horas e configurações de produção
- Trilha de auditoria (`audit_logs`) das ações de admin e das alterações de segurança, com tela de consulta
- Painel de métricas: MRR, ARR, assinantes, novos, cancelamento, receita, estornos, conversão de teste, renovações em nova tentativa e receita por mês
- Plano com campos de teste, excedente e limite por minuto no formulário

### Painel e manutenção

- **Visual novo do painel inteiro.** Menu lateral recolhível, com seções e menu no celular. Busca rápida com Ctrl+K, para páginas e ações. Transição entre páginas, abas com indicador animado, números que contam ao aparecer, avisos (toast) no lugar dos diálogos para mensagens simples, confirmações no visual do painel, carregamento com esqueleto e estados vazios. Telas redesenhadas: login e cadastro (com força da senha), dashboard, faturamento, planos, API keys, requisições, webhooks, segurança, perfil, checkout e todo o admin
- **Biblioteca de componentes `@/ui`** para os módulos de quem usa o pacote: tabela de dados, abas, modal, painel lateral, campos, avisos, confirmação, cartões de métrica e utilitários de formatação e de gráfico. Os componentes de `views/componentes/` viraram adaptadores dos novos
- **Marca configurável sem recompilar:** nome, cor e logo em Admin → Aparência (ou no `.env`). A cor gera a paleta inteira (`brand-*`), também no painel pré-compilado
- **Pontos de extensão:** itens de menu com `section`, `badge` e `ability`; comandos da busca rápida (`commands` e `registerCommands`); texto do painel do login (`auth`)
- A busca de CEP no perfil não manda mais o token de sessão ao ViaCEP
- Nome de plano e de cupom deixou de ser interpolado sem escape nos diálogos de exclusão do admin
- E-mail da página de suporte vem da config (`API_KEY_SUPPORT_EMAIL`, com fallback para `MAIL_FROM_ADDRESS`), em vez de um endereço de exemplo fixo
- `npm run typecheck` (vue-tsc) e job de frontend no CI: checagem de tipos, build e verificação de que o `dist/` commitado bate com o build
- Relações Eloquent tipadas e baseline do PHPStan reduzida de 91 para 69 entradas

## [1.0.0] - 2026-08-03

Primeiro lançamento oficial.

As tags anteriores serviram apenas a testes de distribuição durante o desenvolvimento, não representam versões publicadas e foram removidas. O histórico começa aqui.

### Autenticação e API Keys

- Registro, login, logout, verificação de e-mail e redefinição de senha via Sanctum, com URLs assinadas apontando para a SPA
- API keys geradas com `bin2hex(random_bytes(64))` e guardadas apenas como hash bcrypt. A chave existe em texto puro uma única vez, no momento em que é emitida
- Busca em O(1) por `lookup_hash` (HMAC-SHA256): uma consulta indexada e um único bcrypt. Chaves antigas sem hash de busca continuam funcionando por um scan de fallback que faz backfill e se esgota sozinho
- Validação de origem por IP ou domínio, no estilo CORS, configurável por chave
- `api-key:rotate-keys` emite chaves novas em lote, com `--legacy`, `--user=`, `--all`, `--output=` (CSV), `--dry-run` e `--force`. Exige exatamente um seletor e recusa rotacionar mais de uma chave sem `--output`, porque a chave nova só existe em texto puro durante a execução

### Planos, cota e features

- Planos com ciclo de cobrança, limite de requisições e features, administráveis por API
- Contagem de cota por período com contadores atômicos: a reserva volta quando o servidor falha e o consumo se mantém quando o erro é do cliente, senão bastaria mandar requisição malformada para nunca gastar o plano. Recusa do próprio pacote — origem não permitida, plano sem a feature — não consome cota: o cliente não paga uma requisição para ouvir "não"
- Aviso por e-mail ao atingir o limiar de uso configurável (padrão 80%), no máximo uma vez por período
- Período de carência configurável após o vencimento, com evento próprio ao entrar e ao encerrar
- `FeatureRegistry` para declarar features em código, com sincronização no banco e middleware `feature`
- Retenção do log de requisições por `api-key:prune-logs`, em lotes, com suporte a PostgreSQL, que não tem `DELETE LIMIT`

### Cobrança com Mercado Pago

- Checkout com Secure Fields: os dados do cartão são tokenizados no navegador e nunca chegam ao servidor
- Cartões salvos com pagamento em um clique, tokenizando apenas o CVV
- Cobrança de validação ao cadastrar um cartão, estornada em seguida. A pendência fica registrada e `api-key:retry-validation-refunds` reprocessa o que falhar, para que o valor não fique parado no cartão do cliente sem ninguém saber
- Idempotência nas cobranças por `X-Idempotency-Key`, que cobre o caso em que o pagamento é aceito e a resposta se perde num timeout — o comprador olhando o spinner clica de novo e pagaria duas vezes
- Crédito pró-rata na troca de plano, aplicado na ordem preço → cupom → crédito, para que um desconto percentual incida sobre o preço cheio
- Cupons com desconto percentual ou fixo, limite de usos e validade. A reserva acontece em um único UPDATE condicional **antes** de acionar o gateway, e é devolvida quando o pagamento não se concretiza
- Renovação automática por fila, um job por assinatura, com `ShouldBeUnique` e `tries = 1` para não cobrar duas vezes
- `notification_url` enviada em cada pagamento, como a revisão de qualidade da integração exige
- Identificação de dispositivo repassada no header `X-meli-session-id`, sinal que o antifraude do gateway usa
- Nome, sobrenome e endereço do comprador no `payer`, fatores que o Mercado Pago lista como determinantes da aprovação

### Notificações do gateway

- Webhook com verificação HMAC, que fecha a porta quando o segredo não está configurado: um endpoint que aceita qualquer assinatura é o mesmo que não ter assinatura
- Notificações IPN, que chegam sem assinatura por definição, aceitas com verificação por consulta à API do gateway — só pagamentos da própria conta produzem efeito — e desligáveis por configuração
- Compras que ficam em análise são registradas em `pending_payments`. Recusa posterior devolve a reserva do cupom e avisa o comprador, cumprindo o "você será notificado em breve" da tela; aprovação posterior assina o plano
- `api-key:reconcile-payments` roda a cada 15 minutos e resolve o que o webhook não entregou, aplicando exatamente o mesmo desfecho. Sem ele, um pagamento pendente sem webhook ficaria pendente para sempre

### Assinatura, cancelamento e reembolso

- Cancelamento pelo assinante, que interrompe a renovação sem revogar o período já pago — quem cancela no dia 2 de 30 não perde os outros 28 — com reativação enquanto o ciclo corre
- Política de reembolso configurável, **desligada por padrão**: janela de arrependimento contada da primeira contratação daquele plano, com teto de consumo. Concedido o estorno, devolve o valor efetivamente pago e encerra o acesso na hora
- `GET /dashboard/signature/refund-preview` informa o desfecho antes da confirmação, para a tela não prometer o que não vai cumprir
- Estorno manual pelo painel admin, gravando o mesmo rastro do automático

### Observabilidade

- Registros gravados pelo `risetechapps/monitoring-for-laravel`, que os persiste em banco em vez de arquivo. Substitui a facade `Log` do Laravel em todo o pacote
- Tela administrativa de logs no painel, com filtro por tipo, nível, período e busca em mensagem e contexto, mais o detalhe de cada registro. Mostra tanto o que o pacote grava de propósito quanto as exceções capturadas pelo handler — um estorno que falha produz as duas, e só o par conta a história inteira
- Trace de exceção gravado de forma estruturada, apenas arquivo, linha, classe e função por frame, com teto de 20. O trace em texto do PHP inclui os argumentos de cada chamada, e é por eles que passam token de cartão e dados do pagador
- `api-key:check` diagnostica a instalação: tabelas, credenciais do gateway, fila do log e supervisors do Horizon, agendador e chaves legadas. Existe porque as falhas do pacote são silenciosas por natureza — fila sem worker não dá erro, cron parado não dá erro —, e o sintoma aparece dias depois

### Eventos e notificações

- Onze eventos: `PlanChanged`, `PlanExpired`, `PlanGracePeriodStarted`, `PlanCancelled`, `PlanRefunded`, `PaymentRejected`, `PlanUsageThresholdReached`, `RequestLimitReached`, `UserStatusChanged`, `ApiKeyCreated` e `ApiKeyStatusChanged`
- Onze notificações por e-mail em pt-BR, todas substituíveis apontando a chave em `config('api-key.notifications')` para a sua classe

### Painel

- SPA em Vue 3 com assets pré-compilados, sem exigir Node.js no servidor
- Telas de uso, faturamento, cartões, perfil, planos, cupons e logs, além da administração
- Cartão salvo paga em um clique, tokenizando apenas o CVV pelo SDK do gateway no navegador do dono
- A API key não é exibida no cadastro: quem se inscreve pode nunca consumir a API, e um segredo irrecuperável na primeira tela obriga todo mundo a lidar com ele antes de saber se vai precisar. A emissão fica no perfil, com exibição única e opção de copiar

### Qualidade

- 384 testes Pest cobrindo autenticação, cota, middlewares, checkout, webhook, IPN, cupons, reembolso, logs, comandos e agendamento
- Larastan no nível 5 e Laravel Pint, ambos no CI
- Matriz de CI cruzando PHP 8.4 e 8.5 com `prefer-lowest` e `prefer-stable`

### Requisitos

- PHP 8.4 ou superior
- Laravel 12
- `risetechapps/monitoring-for-laravel` ^4.0, que armazena os logs

### Operação

Três coisas precisam estar de pé, e nenhuma delas acusa erro quando falta:

- **Worker de fila** consumindo `logs`, senão o histórico de requisições fica vazio enquanto a contagem de cota segue correta
- **`schedule:run` no cron**, senão um pagamento em análise sem webhook fica pendente para sempre
- **`vendor:publish --tag=api-key-assets --force`** a cada atualização, porque a publicação copia o `dist/` e o painel não é servido do pacote

`php artisan api-key:check` verifica o que é verificável e aponta o que falta.
