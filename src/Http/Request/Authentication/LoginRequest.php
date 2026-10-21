<?php

namespace RiseTechApps\ApiKey\Http\Request\Authentication;

use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\FormRequest\Traits\HasFormValidation\HasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class LoginRequest extends FormRequest
{
    use HasFormValidation;

    protected array $result = [];

    public function __construct(public ValidationRuleRepository $ruleRepository, array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->result = $this->ruleRepository->getRules('login');
    }

    public function rules(): array
    {
        $rules = $this->result['rules'];

        // As regras podem vir da tabela form_requests, que tem precedência sobre
        // AuthenticationRules. Uma instalação que gravou a regra antiga ainda
        // teria o `exists`, que revela quais e-mails têm conta; ele é tirado
        // aqui, venha de onde vier.
        if (isset($rules['email'])) {
            $rules['email'] = self::withoutExists($rules['email']);
        }

        return $rules;
    }

    private static function withoutExists(string|array $rule): string|array
    {
        $parts = is_array($rule) ? $rule : explode('|', $rule);

        $parts = array_values(array_filter(
            $parts,
            fn ($part) => ! is_string($part) || ! str_starts_with($part, 'exists:')
        ));

        return is_array($rule) ? $parts : implode('|', $parts);
    }

    public function authorize(): bool
    {
        return true;
    }

    #[\Override]
    public function messages(): array
    {
        $messages = $this->result['messages'] ?? [];

        // Traduzir mensagens de validação
        $translatedMessages = [];
        foreach ($messages as $key => $message) {
            // Se a mensagem é igual à chave (formato automático do repositório), traduzir
            if ($message === $key) {
                $translatedMessages[$key] = $this->translateMessage($key);
            } else {
                $translatedMessages[$key] = $message;
            }
        }

        return $translatedMessages;
    }

    private function translateMessage(string $key): string
    {
        // Mapeamento de mensagens para login
        $translations = [
            'email.required' => 'O campo e-mail é obrigatório.',
            'email.email' => 'O e-mail deve ser um endereço válido.',
            'email.max' => 'O e-mail não pode ter mais que :max caracteres.',
            'password.required' => 'O campo senha é obrigatório.',
            'password.min' => 'A senha deve ter pelo menos :min caracteres.',
        ];

        return $translations[$key] ?? $key;
    }
}
