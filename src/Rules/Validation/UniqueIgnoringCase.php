<?php

namespace RiseTechApps\ApiKey\Rules\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Unicidade comparada como o índice do banco compara, e não como o `unique:` do
 * Laravel compara.
 *
 * O `unique:` consulta pelo valor exatamente como ele chegou na requisição, mas
 * o HasToUpper normaliza o atributo na hora de gravar. Um valor que difere só na
 * caixa passa pela validação, colide depois no índice único e chega ao operador
 * como erro genérico — sem apontar o campo e sugerindo "tente novamente", que
 * para um conflito de nome nunca resolve.
 *
 * A comparação usa o scope `whereUpper` do próprio to-upper, então esta regra
 * não precisa supor se — ou como — o campo é normalizado: ela só concorda com o
 * índice.
 */
final readonly class UniqueIgnoringCase implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     * @param  Model|string|null  $ignore  Registro que não conta como conflito,
     *                                     para o caso de update. Aceita o model
     *                                     já resolvido pelo route binding ou o
     *                                     id cru, porque as duas formas circulam
     *                                     entre os requests do pacote.
     */
    public function __construct(
        private string $model,
        private string $column,
        private Model|string|null $ignore = null,
        private ?string $message = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = $this->model::whereUpper($this->column, (string) $value);

        $ignoreId = $this->ignore instanceof Model ? $this->ignore->getKey() : $this->ignore;

        if ($ignoreId !== null && $ignoreId !== '') {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            $fail($this->message ?? __('validation.unique', ['attribute' => $attribute]));
        }
    }
}
