# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

## [2.0.0] - 2026-04-10

### Adicionado
- Sistema de eventos para ciclo de vida de API keys e planos (`ApiKeyCreated`, `ApiKeyDeleted`, `UserPlanActivated`, `UserPlanExpired`)
- Cache para melhorar performance em validações de API keys
- Métodos de login no `AuthService` (`login()`, `logout()`, `validateSession()`, `refresh()`)
- Opções de configuração para período de carência e cache no arquivo `config/apikey.php`
- Melhorias de segurança no modelo `ApiKey` (hash de tokens, encriptação)
- Suporte a rate limiting com regras baseadas em planos
- Tratamento de exceções centralizado para erros de validação

### Alterado
- **BREAKING**: Atualizados middlewares com lógica aprimorada (`ApiKeyAuthenticate`, `HasPlan`, `CheckFeature`)
- **BREAKING**: Atualizados controllers com funcionalidade melhorada (validação de features com retornos padronizados)
- **BREAKING**: Atualizados resources da API (estrutura de resposta modificada)
- **BREAKING**: Atualizados providers de serviço e rotas (novos bindings no container)
- **BREAKING**: Atualizados arquivos de migration (alterações na estrutura das tabelas)
- Atualizado `Authentication` e `UserPlan` models com novos relacionamentos e atributos
- Documentação README completamente reescrita com documentação abrangente

### Removed
- Compatibilidade com versões anteriores do Laravel 10 (requer Laravel 11+)
- Métodos depreciados no `ApiKey` model

## [1.7.1] - 2026-03-17
- Atualizado Packages.
- 
## [1.7.0] - 2026-03-17
- Atualizado Packages.
- 
## [1.6.0] - 2026-03-13
- Atualizado Packages.

## [1.5.0] - 2026-03-07
- Corrigido validação de features nos planos e implementado suporte para validação customizada de features.
- 
## [1.4.0] - 2026-03-07
- Corrigido validação se model existe no metodo show, update e delete

## [1.3.0] - 2026-03-05
- Atualizado packages

## [1.2.0] - 2026-03-04
- Atualizado packages

## [1.1.0] - 2025-12-15
- Atualizado packages HasUuid e RiseTools

## [1.0.0] - 2025-12-10
- Lançamento inicial (Primeira versão estável).
