# risetechapps/api-key-for-laravel - Gerenciamento de Chaves de API e Assinaturas

[![Latest Version on Packagist](https://img.shields.io/packagist/v/risetechapps/api-key-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/api-key-for-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/risetechapps/api-key-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/api-key-for-laravel)
![GitHub Actions](https://github.com/risetechapps/api-key-for-laravel/actions/workflows/main.yml/badge.svg)

## Sobre o Pacote

O `risetechapps/api-key-for-laravel` é uma solução robusta e completa para a gestão de chaves de API, planos de assinatura, cupons de desconto e logs de requisição em aplicações construídas com o framework Laravel.

Este pacote transforma sua aplicação em uma plataforma de serviços, permitindo que você:
*   Gerencie planos de acesso e assinaturas de usuários.
*   Crie e gerencie cupons de desconto.
*   Gere e valide chaves de API de forma segura, utilizando o Laravel Sanctum.
*   Registre e monitore todas as requisições de API para fins de auditoria e análise.
*   Fornece modelos (`ApiKey`, `Plan`, `Coupon`, `RequestLog`, etc.) e controladores prontos para uso.

## Instalação

Você pode instalar o pacote via Composer:

```bash
composer require risetechapps/api-key-for-laravel
```

### Configuração

1.  **Publicar e Executar Migrations**

    O pacote inclui migrations necessárias para criar as tabelas de `api_keys`, `plans`, `coupons`, `request_logs`, entre outras.

    Publique as migrations:
    ```bash
    php artisan vendor:publish --provider="RiseTechApps\ApiKey\ApiKeyServiceProvider" --tag="migrations"
    ```

    Execute as migrations:
    ```bash
    php artisan migrate
    ```

2.  **Adicionar o Trait `HasApiKey`**

    Para que seu modelo de usuário possa gerar e gerenciar chaves de API, adicione o trait `RiseTechApps\ApiKey\Traits\HasApiKey` ao seu modelo `App\Models\User` (ou o modelo que você usa para autenticação):

    ```php
    // app/Models/User.php

    use RiseTechApps\ApiKey\Traits\HasApiKey;
    use Illuminate\Foundation\Auth\User as Authenticatable;

    class User extends Authenticatable
    {
        use HasApiKey;
        // ...
    }
    ```

3.  **Publicar Arquivos de Configuração (Opcional)**

    Se você precisar personalizar as configurações do pacote, como nomes de tabelas ou modelos, publique o arquivo de configuração:

    ```bash
    php artisan vendor:publish --provider="RiseTechApps\ApiKey\ApiKeyServiceProvider" --tag="config"
    ```

## Uso

O pacote fornece rotas e controladores para gerenciar a autenticação e o painel de controle.

### Autenticação de API

O pacote utiliza o Laravel Sanctum para autenticação. Após a instalação e configuração, os usuários podem gerar tokens de API (chaves) para acessar endpoints protegidos.

### Modelos Principais

Os seguintes modelos são fornecidos para interação direta:

| Modelo | Descrição |
| :--- | :--- |
| `ApiKey` | Representa a chave de API gerada para um usuário. |
| `Plan` | Define os planos de assinatura disponíveis. |
| `Coupon` | Gerencia cupons de desconto para planos. |
| `RequestLog` | Armazena o histórico de requisições de API. |
| `UserPlan` | Liga um usuário a um plano de assinatura. |

Você pode acessar a funcionalidade principal através do Facade `ApiKey`:

```php
use ApiKey;

// Exemplo de uso (depende da funcionalidade exposta pelo Facade)
// $apiKey = ApiKey::generateNewKey($user);
```

## Dependências Chave

Este pacote depende de algumas bibliotecas importantes, incluindo:

*   **`php: ^8.3`**
*   **`illuminate/support: ^12`**
*   **`laravel/sanctum: ^4.0`**: Essencial para a funcionalidade de autenticação de API.
*   **`risetechapps/*`**: Uma série de pacotes internos que fornecem funcionalidades adicionais como gerenciamento de endereço, requisições de formulário, manipulação de mídia e repositórios.

## Contribuição

Por favor, veja [CONTRIBUTING](CONTRIBUTING.md) para detalhes.

### Segurança

Se você descobrir quaisquer problemas relacionados à segurança, por favor, envie um e-mail para apps@risetech.com.br em vez de usar o rastreador de issues.

## Créditos

-   [Rise Tech](https://github.com/risetechapps)
-   [Todos os Contribuidores](../../contributors)

## Licença

O MIT License (MIT). Por favor, veja o [Arquivo de Licença](LICENSE.md) para mais informações.
