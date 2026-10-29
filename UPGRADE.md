# Guia de atualização

Passo a passo para atualizar entre versões. O detalhe de cada mudança está no [CHANGELOG](CHANGELOG.md).

## Da 1.0 para a 1.1

Tempo estimado: 15 a 30 minutos, mais a criação de um índice em `request_logs` (passo 3).

### 1. Antes de atualizar: código que usa classes removidas

Procure no seu app por estas classes e troque antes de subir a versão. Nada no pacote as usava, mas um app pode ter usado:

| Removido | Use no lugar |
|---|---|
| `ApiKeyService::routes()` | `RoutesApiKey::register()` |
| `Traits\ApiResponseTrait` | `Http\Response\ApiResponse` (`success()`, `error()`, `apiError()`…) |
| `Traits\HasApiKey`, `Scope\ApiKeyScope` | um scope seu filtrando por `authentication_id` (com várias chaves, o antigo só via a primeira) |
| `Http\Resources\UserResource` | `Authentication\ProfileResource` ou `Authentication\AuthenticationMeResource` |
| `Http\Resources\RequestLogResource` | `Dashboard\Signature\LogHistoryResource` |
| `Enums\BillingMethod` | sem substituto |
| `ApiKey::isOriginAllowed($origin)` | `ApiKey::allowsRequest($origin, $ip)` |

```bash
grep -rnE "ApiKeyService|ApiResponseTrait|HasApiKey|ApiKeyScope|UserResource|RequestLogResource|BillingMethod|isOriginAllowed" app/ routes/ config/
```

### 2. Atualizar o pacote

```bash
composer update risetechapps/api-key-for-laravel
```

Se você publicou os assets do painel, publique de novo:

```bash
php artisan vendor:publish --tag=api-key-assets --force
```

### 3. Migrations

```bash
php artisan migrate
```

Duas colunas novas:

- `authentications.usage_notices_enabled`: preferência de avisos por e-mail. Ligada para todo mundo, então ninguém deixa de receber nada.
- `request_logs.api_key_id`, com índice: qual chave fez cada requisição.

**Atenção ao índice.** Em `request_logs` muito grande (milhões de linhas), criar o índice pode levar alguns minutos e, nesse tempo, segura as gravações na tabela: as requisições da API continuam respondendo, mas o registro delas espera. Rode fora do horário de pico. Se a tabela estiver enorme, considere `php artisan api-key:prune-logs` antes. As linhas antigas ficam com `api_key_id` vazio.

### 4. Agendador

O pacote passou a agendar as próprias rotinas. Confirme que o `schedule:run` roda a cada minuto:

```cron
* * * * * cd /caminho/do/app && php artisan schedule:run >> /dev/null 2>&1
```

- **Novo:** `api-key:notify-expiring`, todo dia às 07:00 (aviso de chave vencendo).
- **Comandos renomeados:** `billing:process-renewals` → `api-key:billing-process-renewals`, `billing:charge-overage` → `api-key:billing-charge-overage` e `admin:make` → `api-key:admin-make`. O agendamento do pacote já usa os nomes novos. Se algum cron, script de deploy ou documentação interna sua chama os nomes antigos, troque: eles ainda funcionam na 1.x, mas avisam e saem na próxima versão maior. Se você agendava os de cobrança por conta própria, remova o seu agendamento, porque o pacote já agenda.
- **Agora agendado pelo pacote:** `api-key:check-expired-plans` (de hora em hora, e o aviso de carência às 10:00). **Se você agendava esse comando no seu `routes/console.php` ou `Kernel`, remova**, senão ele roda duas vezes.

### 5. Relógio do servidor

O webhook do Mercado Pago passou a recusar notificações com mais de 300 segundos de diferença (antes a checagem vinha desligada). Confira que o servidor está com o relógio sincronizado (NTP):

```bash
timedatectl status   # "System clock synchronized: yes"
```

Para manter o comportamento antigo: `MP_WEBHOOK_TOLERANCE_SECONDS=0`.

### 6. Origens permitidas das chaves

A lista de origens de cada chave ficou mais estrita (corrige uma brecha): **entradas de IP conferem só o IP da conexão**, e **entradas de domínio só o header `Origin`**.

Quem pode ser afetado: um painel ou front servido direto de um IP (`http://10.0.0.5`) que chamava a API pelo navegador e estava liberado pela entrada `10.0.0.5`. Libere pelo IP de quem faz a chamada ou por um domínio. Faixas CIDR (`10.0.0.0/8`) agora são aceitas.

Se você usa `TrustProxies`, confira que o proxy está listado: o IP conferido é o de `$request->ip()`.

### 7. Eventos de webhook próprios

Se o seu app já chamava `WebhookDispatcher::dispatch()` com eventos que não são do pacote, registre-os: na 1.1, evento não registrado não é enviado (antes ia para quem marcou "Todos os eventos").

```php
// AppServiceProvider::boot()
WebhookDispatcher::registerEvents(['pedido.criado' => 'Pedido criado']);
```

Ou liste em `config('api-key.webhooks.events')`. O erro de um evento não registrado aparece no log da aplicação.

### 8. Rotas obsoletas do perfil

Continuam funcionando na 1.x e saem na próxima versão maior. Se o seu app ou alguma integração chama:

| Obsoleta | Use no lugar |
|---|---|
| `POST /dashboard/profile/regenerate-key` | `POST /dashboard/api-keys/{id}/regenerate` |
| `GET /dashboard/profile/allowed` | `GET /dashboard/api-keys` (campo `allowed_origins` de cada chave) |
| `POST /dashboard/profile/allowed` | `PUT /dashboard/api-keys/{id}` com `allowed_origins` |

A aba "API key principal" saiu do perfil. Se o seu `api-key.config.ts` tem cartões extras com `tab: 'key'` (ou sem `tab`), eles passam a aparecer em "Dados pessoais". Para outra aba, use `tab: 'personal' | 'address' | 'notifications' | 'privacy'`.

### 9. Conferir

```bash
php artisan api-key:check        # aponta o que falta na instalação
php artisan schedule:list | grep api-key
```

No painel, como cliente: a tela Requisições mostra a coluna "Chave" nas requisições novas, e Perfil → Notificações tem a opção de avisos por e-mail.

### Opcional: o que dá para aproveitar

- **Eventos de webhook do seu app.** `WebhookDispatcher::registerEvents(['pedido.criado' => 'Pedido criado'])` e `WebhookDispatcher::dispatch($conta, 'pedido.criado', $dados)`: o cliente escolhe no painel e valida como os do pacote. Ver "Webhooks de saída" no README.
- **Cota nas respostas** (`X-Quota-*`) e os endpoints `GET /api/v1/usage` e `/usage/daily` para o cliente acompanhar o consumo.
- **Contrato da API** em `docs/openapi.yaml`, incluindo os webhooks, para gerar documentação ou clientes.
