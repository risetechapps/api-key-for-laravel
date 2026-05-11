<?php

namespace RiseTechApps\ApiKey\Http\Request\Authentication;

use Illuminate\Foundation\Http\FormRequest;
use RiseTechApps\FormRequest\Traits\HasFormValidation\HasFormValidation;
use RiseTechApps\FormRequest\ValidationRuleRepository;

class LoginRequest extends FormRequest
{
    use HasFormValidation;

    protected ValidationRuleRepository $repository;

    protected array $result = [];

    public function __construct(ValidationRuleRepository $ruleRepository, array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null)
    {
        parent::__construct($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->repository = $ruleRepository;

        $this->result = $this->repository->getRules('login');
    }

    public function rules(): array
    {
        return $this->result['rules'];
    }

    public function authorize(): bool
    {
        return true;
    }

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
            'email.exists' => 'O e-mail informado não está registrado.',
            'email.max' => 'O e-mail não pode ter mais que :max caracteres.',
            'password.required' => 'O campo senha é obrigatório.',
            'password.min' => 'A senha deve ter pelo menos :min caracteres.',
        ];

        return $translations[$key] ?? $key;
    }


}
