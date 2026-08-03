# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

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
- Contagem de cota por período com contadores atômicos: a reserva volta quando o servidor falha e o consumo se mantém quando o erro é do cliente, senão bastaria mandar requisição malformada para nunca gastar o plano
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

### Eventos e notificações

- Onze eventos: `PlanChanged`, `PlanExpired`, `PlanGracePeriodStarted`, `PlanCancelled`, `PlanRefunded`, `PaymentRejected`, `PlanUsageThresholdReached`, `RequestLimitReached`, `UserStatusChanged`, `ApiKeyCreated` e `ApiKeyStatusChanged`
- Onze notificações por e-mail em pt-BR, todas substituíveis apontando a chave em `config('api-key.notifications')` para a sua classe

### Painel

- SPA em Vue 3 com assets pré-compilados, sem exigir Node.js no servidor
- Telas de uso, faturamento, cartões, perfil, planos e cupons, além da administração
- A API key não é exibida no cadastro: quem se inscreve pode nunca consumir a API, e um segredo irrecuperável na primeira tela obriga todo mundo a lidar com ele antes de saber se vai precisar. A emissão fica no perfil, com exibição única e opção de copiar

### Qualidade

- 351 testes Pest cobrindo autenticação, cota, middlewares, checkout, webhook, IPN, cupons, reembolso, comandos e agendamento
- Larastan no nível 5 e Laravel Pint, ambos no CI
- Matriz de CI cruzando PHP 8.4 e 8.5 com `prefer-lowest` e `prefer-stable`

### Requisitos

- PHP 8.4 ou superior
- Laravel 12
