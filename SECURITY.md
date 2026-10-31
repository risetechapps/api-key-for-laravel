# Política de segurança

## Versões com correções de segurança

| Versão | Suporte |
|---|---|
| 1.2.x | ✅ |
| 1.1.x | ❌ atualize para a 1.2 ([guia](UPGRADE.md)) |
| 1.0.x | ❌ atualize para a 1.2 ([guia](UPGRADE.md)) |

Correções saem em uma versão de patch da linha suportada.

## Como reportar uma vulnerabilidade

**Não abra uma issue pública.** Uma issue fica visível para qualquer pessoa antes de existir correção.

Use um destes canais:

- **GitHub:** aba *Security* do repositório → *Report a vulnerability* (relato privado).
- **E-mail:** apps@risetech.com.br, com o assunto começando por `[SEGURANÇA]`.

Ajuda muito incluir:

- versão do pacote, do Laravel e do PHP;
- o que a falha permite (ex.: usar a API sem chave válida, ver dados de outra conta, contornar o limite do plano);
- passos para reproduzir ou uma prova de conceito mínima;
- se você já viu a falha sendo explorada.

## O que acontece depois

1. Confirmamos o recebimento e avaliamos a gravidade.
2. Combinamos com você o prazo e preparamos a correção em privado.
3. Publicamos a versão corrigida e um aviso de segurança (GitHub Security Advisory), com crédito a quem reportou, se quiser.

Pedimos que não divulgue a falha antes da versão corrigida sair.

## Escopo

Entram, por exemplo:

- autenticação por API key, validação de origem, escopos e limites de plano;
- painel: login, 2FA, sessões, permissões da equipe e do admin;
- webhooks de entrada dos gateways de pagamento (assinatura, replay) e de saída (assinatura HMAC, SSRF);
- exposição de segredos (chaves, segredos de webhook, dados de cartão).

Não entram:

- falhas que dependem de configuração insegura documentada como só para desenvolvimento (ex.: `API_KEY_WEBHOOKS_ALLOW_HTTP=true`, `API_KEY_WEBHOOKS_BLOCK_PRIVATE=false`);
- vulnerabilidades do próprio Laravel, do PHP ou de dependências (reporte ao projeto delas), a menos que o pacote as use de forma insegura;
- ausência de boas práticas sem impacto demonstrável (ex.: falta de um header).

## Para quem usa o pacote

- Mantenha o pacote atualizado na linha suportada.
- Rode `php artisan api-key:check` depois de instalar ou atualizar.
- Deixe a 2FA obrigatória para a equipe do painel (`API_KEY_2FA_REQUIRED_FOR_ADMINS=true`, o padrão).
- Quem recebe os webhooks do pacote deve validar a assinatura: ver "Validando as entregas" no README.
