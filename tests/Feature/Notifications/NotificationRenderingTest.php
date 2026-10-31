<?php

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\Team\TeamInvitation;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\AccountSecurityNotification;
use RiseTechApps\ApiKey\Notifications\ApiKeyExpiringNotification;
use RiseTechApps\ApiKey\Notifications\EmailChangeNotification;
use RiseTechApps\ApiKey\Notifications\EmailVerifyNotification;
use RiseTechApps\ApiKey\Notifications\GracePeriodStartedNotification;
use RiseTechApps\ApiKey\Notifications\OverageChargeNotification;
use RiseTechApps\ApiKey\Notifications\OverageStartedNotification;
use RiseTechApps\ApiKey\Notifications\PaymentRejectedNotification;
use RiseTechApps\ApiKey\Notifications\PlanActivatedNotification;
use RiseTechApps\ApiKey\Notifications\PlanCancelledNotification;
use RiseTechApps\ApiKey\Notifications\PlanExpiredNotification;
use RiseTechApps\ApiKey\Notifications\PlanRefundedNotification;
use RiseTechApps\ApiKey\Notifications\RenewalFailedNotification;
use RiseTechApps\ApiKey\Notifications\RenewalPaymentRequiredNotification;
use RiseTechApps\ApiKey\Notifications\RequestLimitReachedNotification;
use RiseTechApps\ApiKey\Notifications\ResetPasswordNotification;
use RiseTechApps\ApiKey\Notifications\TeamInvitationNotification;
use RiseTechApps\ApiKey\Notifications\UsageThresholdNotification;
use RiseTechApps\ApiKey\Support\Team;

/*
 * Monta de verdade o HTML de cada e-mail. Os testes de fluxo usam
 * Notification::fake(), que nunca chama toMail(): uma variável errada no texto
 * só estourava em produção, dentro da fila, e o e-mail simplesmente não saía.
 */

beforeEach(function () {
    config(['app.name' => 'Acme API']);

    // Nomes já em maiúsculas: o ToUpper normaliza os atributos ao gravar.

    $this->user = Authentication::factory()->create(['name' => 'MARIA SOUZA']);
    $this->plan = Plan::factory()->create(['name' => 'PRO', 'request_limit' => 1000, 'overage_price' => 0.05]);
    $this->userPlan = UserPlan::factory()->create([
        'authentication_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'end_date' => Carbon::parse('2026-12-31 12:00'),
    ]);
});

/** HTML final, depois do markdown e do layout de e-mail do Laravel. */
function renderMail(object $notification, object $notifiable): array
{
    expect($notification->via($notifiable))->toContain('mail');

    $mail = $notification->toMail($notifiable);
    expect($mail)->toBeInstanceOf(MailMessage::class);

    $html = (string) $mail->render();

    // Placeholder esquecido e negrito vazio (`****`, variável nula no meio).
    expect($html)
        ->not->toContain('Seu Site')
        ->not->toContain('****')
        ->toContain('Acme API');

    return [$mail, $html];
}

it('monta cada notificação de plano e cobrança', function (Closure $build, string $subject, array $contains) {
    [$mail, $html] = renderMail($build->call($this), $this->user);

    expect($mail->subject)->toBe($subject);

    foreach ($contains as $text) {
        expect($html)->toContain($text);
    }
})->with([
    'plano ativado' => [
        fn () => new PlanActivatedNotification($this->plan, $this->userPlan),
        'Assinatura confirmada',
        ['MARIA SOUZA', 'PRO', '1000', '31/12/2026'],
    ],
    'troca de plano' => [
        fn () => new PlanActivatedNotification($this->plan, $this->userPlan, Plan::factory()->create(['name' => 'BASIC'])),
        'Seu plano foi alterado',
        ['BASIC', 'PRO'],
    ],
    'plano ativado ilimitado, em trial e pago' => [
        function () {
            $this->plan->update(['request_limit' => 0]);
            $this->userPlan->update(['is_trial' => true, 'payment_id' => 'pay_1', 'payment_amount' => 49.9]);

            return new PlanActivatedNotification($this->plan->fresh(), $this->userPlan->fresh());
        },
        'Assinatura confirmada',
        ['ilimitadas', 'período de teste', 'R$ 49,90'],
    ],
    'aviso de uso' => [
        fn () => new UsageThresholdNotification($this->plan, $this->userPlan, 800, 1000, 80),
        'Você já usou 80% do seu limite',
        ['800 / 1000', '200'],
    ],
    'limite atingido' => [
        fn () => new RequestLimitReachedNotification($this->plan, $this->userPlan, 1000, 1000),
        'Limite de requisições atingido',
        ['1000 / 1000'],
    ],
    'carência' => [
        fn () => new GracePeriodStartedNotification($this->plan, $this->userPlan, 3, new DateTime('2027-01-03')),
        'Seu plano expirou — período de tolerância ativo',
        ['3 dias', '03/01/2027'],
    ],
    'expirado' => [
        fn () => new PlanExpiredNotification($this->plan, $this->userPlan),
        'Seu acesso foi suspenso',
        ['PRO'],
    ],
    'cancelado' => [
        fn () => new PlanCancelledNotification($this->plan, $this->userPlan),
        'Renovação cancelada',
        ['31/12/2026'],
    ],
    'estornado' => [
        fn () => new PlanRefundedNotification($this->plan, $this->userPlan, 1234.5),
        'Estorno concedido',
        ['R$ 1.234,50'],
    ],
    'pagamento recusado' => [
        fn () => new PaymentRejectedNotification($this->plan, 99.9, 'Saldo insuficiente.'),
        'Pagamento não aprovado',
        ['R$ 99,90', 'Saldo insuficiente.', 'Tentar novamente'],
    ],
    'renovação recusada' => [
        fn () => new PaymentRejectedNotification($this->plan, 99.9, isRenewal: true),
        'Renovação não aprovada',
        ['Revisar meio de pagamento'],
    ],
    'falha na renovação com nova tentativa' => [
        fn () => new RenewalFailedNotification($this->plan, 99.9, 1, Carbon::parse('2027-01-02'), 'Cartão vencido.'),
        'Não conseguimos renovar sua assinatura',
        ['02/01/2027', 'Cartão vencido.'],
    ],
    'falha na última tentativa' => [
        fn () => new RenewalFailedNotification($this->plan, 99.9, 3, null),
        'Sua assinatura não foi renovada',
        ['última tentativa'],
    ],
    'renovação manual por PIX' => [
        fn () => new RenewalPaymentRequiredNotification($this->plan, 99.9, Carbon::parse('2027-01-05'), 'pix'),
        'Renove sua assinatura do plano PRO',
        ['PIX', '05/01/2027'],
    ],
    'renovação manual sem cartão e sem data' => [
        fn () => new RenewalPaymentRequiredNotification($this->plan, 99.9, null, 'no_card'),
        'Renove sua assinatura do plano PRO',
        ['Não há cartão cadastrado'],
    ],
    'renovação manual pedindo CVV' => [
        fn () => new RenewalPaymentRequiredNotification($this->plan, 99.9, null, 'cvv_required'),
        'Renove sua assinatura do plano PRO',
        ['precisa da sua confirmação'],
    ],
    'renovação manual com cartão fora de uso' => [
        fn () => new RenewalPaymentRequiredNotification($this->plan, 99.9, null, 'card_out_of_use'),
        'Renove sua assinatura do plano PRO',
        ['não é mais aceito'],
    ],
    'excedente iniciado com teto' => [
        fn () => new OverageStartedNotification($this->plan, $this->userPlan, 500),
        'Você passou do limite do plano PRO',
        ['R$ 0,05', '500 requisições'],
    ],
    'excedente cobrado' => [
        fn () => new OverageChargeNotification($this->plan, 'paid', 25.0, 500),
        'Excedente de uso do plano PRO',
        ['R$ 25,00', 'cartão salvo'],
    ],
    'excedente recusado' => [
        fn () => new OverageChargeNotification($this->plan, 'rejected', 25.0, 500),
        'Excedente de uso do plano PRO',
        ['não foi aprovada', 'Nunca enviamos cobranças'],
    ],
    'excedente a pagar' => [
        fn () => new OverageChargeNotification($this->plan, 'pending', 25.0, 500),
        'Excedente de uso do plano PRO',
        ['Pagar no painel'],
    ],
]);

it('monta as notificações de conta e acesso', function (Closure $build, string $subject, array $contains) {
    [$mail, $html] = renderMail($build->call($this), $this->user);

    expect($mail->subject)->toBe($subject);

    foreach ($contains as $text) {
        expect($html)->toContain($text);
    }
})->with([
    'confirmação de e-mail' => [
        fn () => new EmailVerifyNotification,
        'Confirme seu e-mail',
        ['/email/verify/', 'signature='],
    ],
    'redefinição de senha' => [
        fn () => new ResetPasswordNotification('token-123'),
        'Redefinição de senha',
        ['token-123'],
    ],
    'troca de e-mail' => [
        fn () => new EmailChangeNotification($this->user, 'novo@example.com'),
        'Confirme seu novo e-mail',
        ['/email/change/', 'signature='],
    ],
    'senha alterada' => [
        fn () => new AccountSecurityNotification(AccountSecurityNotification::PASSWORD_CHANGED),
        'Sua senha foi alterada',
        ['Redefinir senha'],
    ],
    'e-mail alterado' => [
        fn () => new AccountSecurityNotification(AccountSecurityNotification::EMAIL_CHANGED, ['new_email' => 'novo@example.com']),
        'O e-mail da sua conta foi alterado',
        ['novo@example.com'],
    ],
    '2FA ativada' => [
        fn () => new AccountSecurityNotification(AccountSecurityNotification::TWO_FACTOR_ENABLED),
        'Verificação em duas etapas ativada',
        [],
    ],
    '2FA desativada' => [
        fn () => new AccountSecurityNotification(AccountSecurityNotification::TWO_FACTOR_DISABLED),
        'Verificação em duas etapas desativada',
        [],
    ],
    '2FA bloqueada por tentativas' => [
        fn () => new AccountSecurityNotification(AccountSecurityNotification::TWO_FACTOR_LOCKED),
        'Muitas tentativas de código na sua conta',
        [],
    ],
    'evento de segurança desconhecido' => [
        fn () => new AccountSecurityNotification('outro'),
        'Alteração na sua conta',
        [],
    ],
    'chave vencendo em dias' => [
        fn () => new ApiKeyExpiringNotification(
            ApiKey::factory()->create(['authentication_id' => $this->user->id, 'name' => 'Produção', 'expires_at' => Carbon::parse('2027-01-10 09:30')]),
            5
        ),
        'Sua chave de API vence em 5 dias',
        ['Produção', '10/01/2027 09:30', '/dashboard/api-keys'],
    ],
    'chave vencendo amanhã, sem nome' => [
        fn () => new ApiKeyExpiringNotification(
            ApiKey::factory()->create(['authentication_id' => $this->user->id, 'name' => null, 'expires_at' => now()->addHours(10)]),
            1
        ),
        'Sua chave de API vence amanhã',
        ['sem nome'],
    ],
    'convite para a equipe' => [
        fn () => new TeamInvitationNotification(
            TeamInvitation::create([
                'account_id' => $this->user->id,
                'name' => 'joão lima',
                'email' => 'joao@example.com',
                'role' => Team::ADMIN,
                'token_hash' => TeamInvitation::hashToken('convite-abc'),
                'expires_at' => now()->addDays(7),
            ]),
            'convite-abc',
            'maria souza'
        ),
        'Maria Souza convidou você para a equipe no Acme API',
        ['João Lima', 'administrador', '/invite/convite-abc'],
    ],
]);

it('cobre toda notificação configurável', function () {
    // Notificação nova na config sem caso acima não passa despercebida.
    $covered = [
        AccountSecurityNotification::class, ApiKeyExpiringNotification::class,
        EmailChangeNotification::class, EmailVerifyNotification::class,
        GracePeriodStartedNotification::class, OverageChargeNotification::class,
        OverageStartedNotification::class, PaymentRejectedNotification::class,
        PlanActivatedNotification::class, PlanCancelledNotification::class,
        PlanExpiredNotification::class, PlanRefundedNotification::class,
        RenewalFailedNotification::class, RenewalPaymentRequiredNotification::class,
        RequestLimitReachedNotification::class, ResetPasswordNotification::class,
        TeamInvitationNotification::class, UsageThresholdNotification::class,
    ];

    expect(array_diff(array_values(config('api-key.notifications')), $covered))->toBe([]);

    $files = array_map(
        fn ($f) => 'RiseTechApps\\ApiKey\\Notifications\\'.basename($f, '.php'),
        glob(__DIR__.'/../../../src/Notifications/*.php')
    );
    expect(array_diff($files, $covered))->toBe([]);
});
