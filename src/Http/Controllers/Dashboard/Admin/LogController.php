<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RiseTechApps\Monitoring\Entry\EntryType;

/**
 * Leitura dos logs que o pacote grava pelo monitoring.
 *
 * Endpoints próprios em vez de expor a API do monitoring: ela devolve a coleção
 * sem os metadados de paginação, e uma tela de log sem total nem páginas obriga
 * a carregar tudo. Aqui também vale o mesmo portão de admin e o mesmo envelope
 * de resposta das demais telas administrativas, em vez de um segundo modelo de
 * autenticação e um segundo formato para o painel entender.
 *
 * Somente leitura. Resolver ou apagar entrada é operação do monitoring, e
 * duplicá-la aqui criaria dois lugares decidindo a mesma coisa.
 */
class LogController extends Controller
{
    /**
     * Níveis que o Loggly grava, do mais grave ao mais verboso. A ordem importa
     * para a tela: é ela que o filtro apresenta.
     */
    private const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /**
     * Tipos que esta tela mostra.
     *
     * O monitoring guarda requisição, query, job, e-mail e mais na mesma tabela;
     * nada disso pertence aqui. Exceção entra porque os 30 `report()` do pacote
     * caem nela — sem isso, um estorno que falha aparece pela metade: o log com
     * o código de correlação estaria na tela, e a exceção correspondente não.
     */
    private const TYPES = [EntryType::LOG, EntryType::EXCEPTION];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:'.implode(',', self::TYPES)],
            'level' => ['nullable', 'string', 'in:'.implode(',', self::LEVELS)],
            'search' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Teto explícito: sem ele um per_page grande carrega a tabela
            // inteira na memória, e esta é a que mais cresce na instalação.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (! $this->tableExists()) {
            return response()->jsonGone(__('api-key::messages.monitoring_unavailable'));
        }

        $query = $this->baseQuery();

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['level'])) {
            // Entrada de exceção não tem `level`, então filtrar por nível a
            // exclui naturalmente — que é o comportamento esperado de quem
            // escolheu "apenas erros de nível X".
            $query->where('content->level', $validated['level']);
        }

        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from'].' 00:00:00');
        }

        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to'].' 23:59:59');
        }

        if (! empty($validated['search'])) {
            // Escapado para que % ou _ digitados pelo operador sejam procurados
            // literalmente, em vez de virarem curinga e devolver a base inteira.
            //
            // Com ESCAPE explícito: MySQL e PostgreSQL adotam a contrabarra por
            // padrão, mas SQLite não adota nenhum caractere de escape. Sem a
            // cláusula, o escape é ignorado ali e o `\%` passa a casar com as
            // contrabarras que o JSON do content carrega nos namespaces.
            $term = '%'.addcslashes($validated['search'], '%_\\').'%';
            $query->whereRaw('content LIKE ? ESCAPE ?', [$term, '\\']);
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->jsonSuccess([
            'data' => $logs->getCollection()->map(fn ($row) => $this->summary($row))->all(),
            'total' => $logs->total(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'levels' => self::LEVELS,
            'types' => self::TYPES,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! $this->tableExists()) {
            return response()->jsonGone(__('api-key::messages.monitoring_unavailable'));
        }

        $log = $this->baseQuery()->where('uuid', $id)->first();

        if (! $log) {
            return response()->json([
                'success' => false,
                'message' => __('api-key::messages.log_not_found'),
            ], 404);
        }

        return response()->jsonSuccess($this->detail($log));
    }

    private function baseQuery()
    {
        return DB::connection($this->connection())
            ->table('monitoring')
            ->whereIn('type', self::TYPES);
    }

    private function connection(): ?string
    {
        return config('monitoring.drivers.database.connection', config('database.default'));
    }

    /**
     * A tabela pertence ao monitoring e é criada pelas migrations dele. Consultar
     * sem checar produziria um 500 onde a resposta honesta é "não instalado".
     */
    private function tableExists(): bool
    {
        return Schema::connection($this->connection())->hasTable('monitoring');
    }

    /**
     * Linha da listagem: o suficiente para varrer a tela sem trazer contexto,
     * que em log de exceção chega a alguns KB por entrada.
     */
    private function summary(object $row): array
    {
        $content = $this->decode($row->content);

        return [
            'id' => $row->uuid,
            'type' => $row->type,
            // Exceção não carrega nível: quem a gravou foi o watcher do handler,
            // não o Loggly. Tratar como `error` é o que ela significa na prática
            // e evita uma coluna vazia na tela.
            'level' => $content['level'] ?? ($row->type === EntryType::EXCEPTION ? 'error' : null),
            'message' => $content['message'] ?? null,
            'origin' => $this->origin($row, $content),
            'created_at' => $row->created_at,
        ];
    }

    private function detail(object $row): array
    {
        $content = $this->decode($row->content);
        $isException = $row->type === EntryType::EXCEPTION;

        return [
            'id' => $row->uuid,
            'type' => $row->type,
            'level' => $content['level'] ?? ($isException ? 'error' : null),
            'message' => $content['message'] ?? null,
            'context' => $content['context'] ?? [],
            'properties' => $content['properties'] ?? [],
            // Log do Loggly guarda a exceção em `exception`; o watcher espalha
            // class, file, line e trace na raiz do content. A tela recebe um
            // formato só, em vez de decidir qual leitura fazer.
            'exception' => $isException
                ? array_filter([
                    'class' => $content['class'] ?? null,
                    'file' => $content['file'] ?? null,
                    'line' => $content['line'] ?? null,
                    'trace' => $content['trace'] ?? null,
                ], fn ($value) => $value !== null)
                : ($content['exception'] ?? null),
            'tags' => $this->decode($row->tags),
            'user' => $this->decode($row->user ?? null),
            'created_at' => $row->created_at,
        ];
    }

    /** De onde veio: a classe que chamou o log, ou o arquivo que estourou. */
    private function origin(object $row, array $content): ?string
    {
        if ($row->type === EntryType::EXCEPTION) {
            return $content['class'] ?? $content['file'] ?? null;
        }

        return $content['properties']['class'] ?? null;
    }

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        return json_decode($value, true) ?: [];
    }
}
