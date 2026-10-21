<?php

use Illuminate\Support\Facades\Route;
use RiseTechApps\ApiKey\RoutesApiKey;

RoutesApiKey::register([
    'prefix' => 'api/v1',
    'middleware' => ['api'],
]);

/*
|--------------------------------------------------------------------------
| Exemplo: protegendo suas próprias rotas
|--------------------------------------------------------------------------
|
| Use o grupo `plan` para exigir API key válida + plano ativo + limite de
| requisições (log/contagem) + validação de origem + idioma. Adicione
| `feature:sua_feature` para restringir a planos que tenham a feature.
|
| Chame com o header:  X-API-KEY: <sua-chave>   (e Accept: application/json)
|
| Dica: prefixe com `api/...` para não colidir com a SPA quando ela estiver
| habilitada (a rota catch-all serve tudo que NÃO começa com `api`).
|
*/

// Route::prefix('api/v1')
//     ->middleware(['plan', 'feature:notify-mail'])
//     ->group(function () {
//         Route::get('/ttt', function () {
//             return response()->jsonSuccess([auth()->user()]);
//         });
//     });
