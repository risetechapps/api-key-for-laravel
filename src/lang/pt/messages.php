<?php

return [
    // Authentication
    'registration_success' => 'Registro realizado com sucesso. Por favor, verifique seu e-mail.',
    'registration_failed' => 'Não foi possível realizar o registro no momento, por favor tente novamente mais tarde.',
    'user_not_found' => 'Usuário não encontrado',
    'account_not_verified' => 'Conta não verificada, por favor verifique sua caixa de e-mail.',
    'incorrect_credentials' => 'Usuário ou senha incorretos',
    'logout_success' => 'Logout realizado com sucesso.',
    'password_reset_sent' => 'Se houver uma conta com este e-mail, enviaremos um link de recuperação em instantes.',
    'password_reset_failed' => 'Não foi possível enviar o link de recuperação. Tente novamente.',
    'password_reset_success' => 'Senha redefinida com sucesso.',
    'password_reset_invalid' => 'Link de recuperação inválido ou expirado. Peça um novo.',
    'verification_resent' => 'Se houver uma conta pendente de verificação com este e-mail, enviaremos um novo link em instantes.',
    'account_disabled' => 'Esta conta está suspensa. Entre em contato com o suporte.',
    'account_deleted' => 'Sua conta foi excluída.',
    'account_delete_admin' => 'Contas de administrador não podem ser excluídas pelo painel. Peça a outro administrador para remover o seu acesso antes.',
    'account_delete_failed' => 'Não foi possível excluir a conta no momento. Tente novamente mais tarde.',
    'password_incorrect' => 'Senha incorreta.',
    'admin_cannot_change_self' => 'Você não pode alterar o status da sua própria conta.',
    'admin_ability_required' => 'Seu papel na equipe não permite esta ação.',
    'admin' => [
        'subscription_cancelled' => 'Renovação cancelada. O acesso segue até o fim do período pago.',
        'subscription_already_cancelled' => 'Esta assinatura já está cancelada.',
        'subscription_extended' => 'Vencimento estendido.',
        'usage_adjusted' => 'Consumo do período ajustado.',
        'renewal_retried' => 'Nova tentativa de renovação enviada para a fila.',
        'renewal_not_retryable' => 'Só dá para tentar de novo a renovação de uma assinatura ativa e não cancelada.',
        'overage_waived' => 'Excedente perdoado.',
        'email_already_verified' => 'O e-mail deste usuário já está verificado.',
        'verification_resent' => 'E-mail de verificação reenviado.',
        'sessions_revoked' => 'Todas as sessões do usuário foram encerradas.',
        'api_keys_revoked' => 'As API keys do usuário foram desativadas.',
        'two_factor_not_enabled' => 'Este usuário não tem verificação em duas etapas ativa.',
        'two_factor_reset' => 'Verificação em duas etapas desligada. O usuário foi avisado por e-mail.',
        'role_changed' => 'Papel atualizado.',
        'last_admin' => 'Este é o último administrador: promova outra pessoa antes de rebaixá-lo.',
    ],
    'user_status_updated' => 'Status do usuário atualizado.',
    'plan_granted' => 'Plano concedido ao usuário.',
    'password_changed' => 'Senha alterada. As outras sessões foram encerradas.',
    'email_unchanged' => 'Este já é o e-mail da sua conta.',
    'email_taken' => 'Este e-mail já está em uso por outra conta.',
    'email_change_sent' => 'Enviamos um link de confirmação para o novo e-mail. A troca só vale depois da confirmação.',
    'session_revoked' => 'Sessão encerrada.',
    'sessions_revoked' => 'As outras sessões foram encerradas.',
    'renewal_no_card' => 'Não há cartão salvo para cobrar a renovação.',
    'renewal_card_unusable' => 'O cartão salvo não pôde ser usado: ele pode ter sido removido ou estar vencido.',
    'pix_disabled' => 'Pagamento com PIX não está disponível.',
    'pix_failed' => 'Não foi possível gerar o PIX agora. Tente novamente em instantes.',
    'trial_started' => 'Seu período de teste de :days dias começou.',
    'two_factor_challenge_invalid' => 'A verificação expirou. Faça login novamente.',
    'two_factor_code_invalid' => 'Código inválido.',
    'two_factor_already_enabled' => 'A verificação em duas etapas já está ativa.',
    'two_factor_not_pending' => 'Inicie a ativação da verificação em duas etapas antes de confirmar.',
    'two_factor_not_enabled' => 'A verificação em duas etapas não está ativa.',
    'two_factor_disabled' => 'Verificação em duas etapas desativada.',
    'two_factor_required_admin' => 'Ative a verificação em duas etapas no seu perfil para acessar a administração.',
    'api_key_limit_reached' => 'Limite de :max chaves por conta atingido.',
    'api_key_last' => 'A conta precisa de pelo menos uma chave. Crie outra antes de revogar esta.',
    'api_key_revoked' => 'Chave revogada.',
    'api_key_scope_denied' => 'Esta chave não tem permissão para este recurso.',
    'rate_limited' => 'Muitas requisições por minuto. Aguarde e tente novamente.',
    'webhook_limit_reached' => 'Limite de :max webhooks por conta atingido.',
    'webhook_deleted' => 'Webhook removido.',
    'webhook_url_not_allowed' => 'URL não permitida: use HTTPS e um endereço público.',
    'overage_description' => 'Excedente do plano :plan (:requests requisições)',
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
    'plan_name_taken' => 'Já existe um plano com este nome.',

    // Validation
    'validation_failed' => 'Falha na validação',

    // Coupon Messages
    'error_loading_coupons' => 'Erro ao carregar cupons',
    'error_creating_coupon' => 'Não foi possível registrar este cupom no momento, por favor tente novamente mais tarde',
    'error_loading_coupon' => 'Não foi possível carregar os detalhes do cupom, por favor tente novamente mais tarde',
    'error_updating_coupon' => 'Não foi possível atualizar este cupom no momento, por favor tente novamente mais tarde',
    'error_deleting_coupon' => 'Não foi possível excluir este cupom no momento, por favor tente novamente mais tarde',
    'coupon_code_taken' => 'Já existe um cupom com este código.',

    // Logs
    'monitoring_unavailable' => 'O armazenamento de logs não está disponível. Verifique se as migrations do pacote de monitoramento foram executadas.',
    'log_not_found' => 'Registro de log não encontrado.',

    // Profile Messages
    'error_loading_profile' => 'Erro ao carregar dados do perfil',
    'error_updating_profile' => 'Não é possível atualizar seu perfil no momento',
    'error_loading_allowed_origins' => 'Erro ao carregar dados de origens permitidas',
    'error_updating_allowed_origins' => 'Erro ao atualizar dados de origens permitidas',

    // Signature Messages
    'error_creating_signature' => 'Não foi possível completar a assinatura no momento, por favor tente novamente mais tarde',
    'error_cancelling_signature' => 'Não foi possível cancelar a assinatura no momento, por favor tente novamente mais tarde',
    'error_resuming_signature' => 'Não foi possível reativar a renovação no momento, por favor tente novamente mais tarde',
    'no_active_subscription' => 'Nenhuma assinatura ativa encontrada.',
    'no_cancelled_subscription' => 'Nenhuma assinatura cancelada para reativar.',
    'subscription_cancelled' => 'Renovação cancelada. Seu acesso continua até o fim do período já pago e nenhuma nova cobrança será feita.',
    'subscription_resumed' => 'Renovação reativada. Sua assinatura será renovada normalmente no vencimento.',
    'subscription_cannot_resume' => 'Esta assinatura já venceu e não pode ser reativada. Contrate um plano novamente.',
    'subscription_refunded' => 'Assinatura cancelada e valor devolvido. O acesso foi encerrado agora, e o crédito aparece na fatura do cartão conforme o prazo da operadora.',

    // Motivos de recusa do estorno automático
    'refund' => [
        'eligible' => 'Você tem direito ao estorno do valor pago.',
        'refund_disabled' => 'Este serviço não oferece estorno automático no cancelamento.',
        'nothing_to_refund' => 'Não há valor a devolver nesta assinatura.',
        'already_refunded' => 'O valor desta assinatura já foi devolvido.',
        'window_expired' => 'O prazo para estorno desta assinatura já passou.',
        'usage_exceeded' => 'O estorno não se aplica porque boa parte das requisições do plano já foi utilizada.',
    ],
    'error_loading_signature_history' => 'Não foi possível carregar histórico de planos',
    'error_loading_request_log' => 'Não foi possível carregar histórico de requisições',

    // Cards
    'card_already_registered' => 'Este cartão já está cadastrado.',
    'card_not_found' => 'Cartão não encontrado.',
    'overage_paid' => 'Excedente pago. O recibo já está no histórico.',
    'overage_cvv_required' => 'Este cartão pede o CVV para ser cobrado. Digite o código e tente de novo.',
    'card_saved' => 'Cartão salvo com sucesso.',
    'automatic_billing_enabled' => 'Renovação automática ativada. As próximas cobranças deste cartão não pedem o CVV.',
    'automatic_billing_unavailable' => 'Não foi possível ativar a renovação automática neste cartão agora. Você continua podendo renovar pelo painel.',
    'gateway_not_configured' => 'O pagamento com cartão está indisponível no momento. Tente mais tarde ou pague com PIX.',
    'card_verification_failed' => 'O cartão não foi aprovado na validação. Confira os dados ou use outro cartão.',
    'pix_requires_document_and_phone' => 'Para pagar com PIX, informe CPF e celular no seu perfil.',
    'pix_requires_document' => 'Para pagar com PIX, informe o CPF no seu perfil.',
    'pix_amount_out_of_range' => 'O PIX aceita valores de :min a :max. Pague com cartão.',
    'card_requires_full_profile' => 'Para salvar o cartão, complete no seu perfil CPF, celular, data de nascimento e endereço.',
    'card_requires_security_code' => 'Informe o código de segurança (CVV) do cartão.',
    'cvv_required' => 'CVV obrigatório.',

    // Checkout & Pagamento
    'plan_not_found' => 'Plano não encontrado.',
    'plan_requires_payment' => 'Este plano exige pagamento. Conclua a assinatura pelo checkout.',
    'coupon_invalid_or_expired' => 'Cupom inválido ou expirado.',
    'invalid_payment_data' => 'Dados de pagamento inválidos.',
    'subscription_activated_full_discount' => 'Assinatura ativada com cupom de desconto total.',
    'subscription_activated_with_credit' => 'Assinatura ativada. O crédito do plano anterior cobriu o valor integral.',
    'payment_approved' => 'Pagamento aprovado! Sua assinatura foi ativada.',
    'payment_pending' => 'Pagamento em análise. Você será notificado em breve.',
    'payment_declined' => 'Pagamento recusado.',
    'error_processing_payment' => 'Erro interno ao processar o pagamento.',
    'invalid_webhook_signature' => 'Assinatura inválida.',

    // Admin
    'error_processing_refund' => 'Não foi possível processar o estorno. O pagamento não foi alterado e a assinatura segue ativa. Procure o código :id no log da aplicação para ver o motivo.',

    // Billing
    'plan_subscription_description' => 'Assinatura do plano :plan',
    'plan_renewal_description' => 'Renovação do plano :plan',

    // Motivos de recusa do Mercado Pago (status_detail)
    'payment_rejected' => [
        'cc_rejected_bad_filled_card_number' => 'Número do cartão inválido.',
        'cc_rejected_bad_filled_date' => 'Data de validade inválida.',
        'cc_rejected_bad_filled_other' => 'Dado inválido. Verifique as informações do cartão.',
        'cc_rejected_bad_filled_security_code' => 'Código de segurança inválido.',
        'cc_rejected_blacklist' => 'Cartão não permitido.',
        'cc_rejected_call_for_authorize' => 'Entre em contato com seu banco para autorizar o pagamento.',
        'cc_rejected_card_disabled' => 'Cartão desativado. Entre em contato com seu banco.',
        'cc_rejected_card_error' => 'Não foi possível processar o cartão. Tente novamente.',
        'cc_rejected_duplicated_payment' => 'Pagamento duplicado detectado.',
        'cc_rejected_high_risk' => 'Pagamento recusado por motivos de segurança.',
        'cc_rejected_insufficient_amount' => 'Saldo insuficiente.',
        'cc_rejected_invalid_installments' => 'Número de parcelas inválido.',
        'cc_rejected_max_attempts' => 'Número máximo de tentativas atingido. Tente outro cartão.',
        'default' => 'Pagamento recusado. Verifique os dados e tente novamente.',
    ],

    // Billing Cycle
    'billing_cycle_weekly' => 'Semanal',
    'billing_cycle_monthly' => 'Mensal',
    'billing_cycle_annually' => 'Anual',

    // Generic
    'success' => 'Sucesso',
    'error' => 'Erro',
    'please_try_again' => 'Por favor tente novamente mais tarde',
];
