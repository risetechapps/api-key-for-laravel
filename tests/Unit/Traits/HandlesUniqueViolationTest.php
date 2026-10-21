<?php

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use RiseTechApps\ApiKey\Traits\HandlesUniqueViolation;

/**
 * PDOException com um SQLSTATE escolhido.
 *
 * O código de uma QueryException é copiado da exceção anterior, e o PDO real só
 * o preenche ao falar com o banco. Esta subclasse escreve a propriedade
 * diretamente para que o teste possa exercitar cada driver sem precisar de um
 * banco que produza aquele erro.
 */
final class SqlStateException extends PDOException
{
    public function __construct(string $sqlState)
    {
        parent::__construct("SQLSTATE[{$sqlState}]: constraint violation");

        $this->code = $sqlState;
    }
}

/** Exceção como o Laravel a entrega ao controller. */
function queryExceptionWith(string $sqlState): QueryException
{
    return new QueryException(
        'testing',
        'insert into "plans" ("name") values (?)',
        ['PLANO PROFISSIONAL'],
        new SqlStateException($sqlState),
    );
}

beforeEach(function () {
    // O método é protected: só existe para os controllers que usam a trait.
    $this->host = new class
    {
        use HandlesUniqueViolation;

        public function call(QueryException $e, string $field, string $message): ?JsonResponse
        {
            return $this->uniqueViolationResponse($e, $field, $message);
        }
    };
});

describe('Traducao da violacao de unicidade', function () {
    it('traduz o codigo do MySQL e do SQLite', function () {
        $response = $this->host->call(queryExceptionWith('23000'), 'name', 'Já existe um plano com este nome.');

        expect($response)->not->toBeNull()
            ->and($response->getStatusCode())->toBe(422);
    });

    it('traduz o codigo do PostgreSQL', function () {
        // 23505 é o unique_violation do PostgreSQL. Faltar este código na lista
        // deixaria a correção valendo só em desenvolvimento, onde a suíte roda
        // em SQLite — o tipo de erro que nunca aparece antes de produção.
        $response = $this->host->call(queryExceptionWith('23505'), 'name', 'Já existe um plano com este nome.');

        expect($response)->not->toBeNull()
            ->and($response->getStatusCode())->toBe(422);
    });

    it('aponta o campo e repete a mensagem recebida', function () {
        $response = $this->host->call(queryExceptionWith('23000'), 'code', 'Já existe um cupom com este código.');

        $body = $response->getData(true);

        expect($body['success'])->toBeFalse()
            ->and($body['errors'])->toBe(['code' => ['Já existe um cupom com este código.']]);
    });

    it('nao reivindica uma violacao de chave estrangeira', function () {
        // 23503 também é da classe 23xxx, mas é chave estrangeira, não
        // unicidade. Casar por prefixo faria o controller anunciar "já existe um
        // plano com este nome" para um erro que não tem nada a ver com o nome.
        expect($this->host->call(queryExceptionWith('23503'), 'name', 'Já existe.'))->toBeNull();
    });

    it('devolve null para erro de banco sem relacao com unicidade', function () {
        // Devolver null é o que faz o chamador seguir com o tratamento genérico,
        // em vez de disfarçar uma tabela ausente como erro de formulário.
        expect($this->host->call(queryExceptionWith('42S02'), 'name', 'Já existe.'))->toBeNull()
            ->and($this->host->call(queryExceptionWith('HY000'), 'name', 'Já existe.'))->toBeNull();
    });
});
