<?php

return [
    // Authentication
    'registration_success' => 'Registro realizado com sucesso. Por favor, verifique seu e-mail.',
    'registration_failed' => 'Não foi possível realizar o registro no momento, por favor tente novamente mais tarde.',
    'user_not_found' => 'Usuário não encontrado',
    'account_not_verified' => 'Conta não verificada, por favor verifique sua caixa de e-mail.',
    'incorrect_credentials' => 'Usuário ou senha incorretos',
    'logout_success' => 'Logout realizado com sucesso.',
    'password_reset_sent' => 'Link de recuperação enviado para seu e-mail.',
    'password_reset_failed' => 'Não foi possível enviar o link de recuperação. Tente novamente.',
    'password_reset_success' => 'Senha redefinida com sucesso.',
    'unauthorized' => 'Não autorizado',
    'forbidden' => 'Acesso negado',

    // API Key
    'api_key_not_active' => 'Sua chave API não está ativa',
    'api_key_expired' => 'Sua chave API expirou',
    'api_key_not_found' => 'API Key não encontrada.',
    'error_regenerating_api_key' => 'Erro ao regenerar a API Key. Tente novamente.',
    'origin_not_allowed' => 'Não autorizado: Origem/IP da requisição não permitido',

    // Plan & Subscription
    'plan_expired_or_inactive' => 'Seu plano expirou ou não está ativo',
    'plan_expired_grace_ended' => 'Seu plano expirou. O período de tolerância também encerrou.',
    'request_limit_reached' => 'Limite de requisições atingido',
    'grace_period_warning' => 'período-de-tolerância',
    'grace_period_days_remaining' => 'dias restantes no período de tolerância',

    // Error Messages
    'error_loading_plans' => 'Erro ao carregar lista de planos',
    'error_creating_plan' => 'Erro ao registrar este plano, por favor tente novamente mais tarde',
    'error_loading_plan' => 'Erro ao carregar dados do plano',
    'error_updating_plan' => 'Não foi possível atualizar o plano no momento, por favor tente novamente mais tarde',
    'error_deleting_plan' => 'Não foi possível excluir este plano no momento, por favor tente novamente mais tarde',

    // Validation
    'validation_failed' => 'Falha na validação',

    // Coupon Messages
    'error_loading_coupons' => 'Erro ao carregar cupons',
    'error_creating_coupon' => 'Não foi possível registrar este cupom no momento, por favor tente novamente mais tarde',
    'error_loading_coupon' => 'Não foi possível carregar os detalhes do cupom, por favor tente novamente mais tarde',
    'error_updating_coupon' => 'Não foi possível atualizar este cupom no momento, por favor tente novamente mais tarde',
    'error_deleting_coupon' => 'Não foi possível excluir este cupom no momento, por favor tente novamente mais tarde',

    // Profile Messages
    'error_loading_profile' => 'Erro ao carregar dados do perfil',
    'error_updating_profile' => 'Não é possível atualizar seu perfil no momento',
    'error_loading_allowed_origins' => 'Erro ao carregar dados de origens permitidas',
    'error_updating_allowed_origins' => 'Erro ao atualizar dados de origens permitidas',

    // Signature Messages
    'error_creating_signature' => 'Não foi possível completar a assinatura no momento, por favor tente novamente mais tarde',
    'error_loading_signature_history' => 'Não foi possível carregar histórico de planos',
    'error_loading_request_log' => 'Não foi possível carregar histórico de requisições',

    // Cards
    'card_already_registered' => 'Este cartão já está cadastrado.',
    'card_not_found' => 'Cartão não encontrado.',
    'cvv_required' => 'CVV obrigatório.',

    // Checkout & Pagamento
    'plan_not_found' => 'Plano não encontrado.',
    'coupon_invalid_or_expired' => 'Cupom inválido ou expirado.',
    'invalid_payment_data' => 'Dados de pagamento inválidos.',
    'subscription_activated_full_discount' => 'Assinatura ativada com cupom de desconto total.',
    'payment_approved' => 'Pagamento aprovado! Sua assinatura foi ativada.',
    'payment_pending' => 'Pagamento em análise. Você será notificado em breve.',
    'payment_declined' => 'Pagamento recusado.',
    'error_processing_payment' => 'Erro interno ao processar o pagamento.',
    'invalid_webhook_signature' => 'Assinatura inválida.',

    // Admin
    'error_processing_refund' => 'Erro ao processar estorno.',

    // Billing Cycle
    'billing_cycle_weekly' => 'Semanal',
    'billing_cycle_monthly' => 'Mensal',
    'billing_cycle_annually' => 'Anual',

    // Generic
    'success' => 'Sucesso',
    'error' => 'Erro',
    'please_try_again' => 'Por favor tente novamente mais tarde',
];
