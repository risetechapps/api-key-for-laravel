<?php

namespace RiseTechApps\ApiKey;

use Illuminate\Support\Facades\Route;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\AccountSecurityController;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\AuthController;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\ProfileController;
use RiseTechApps\ApiKey\Http\Controllers\Authentication\TwoFactorController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin\AdminBillingController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin\AdminController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin\AdminUserController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin\LogController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin\PaymentSettingsController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin\SystemHealthController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\ApiKeys\ApiKeyController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Billing\OverageController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Billing\ReceiptController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Cards\CardController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Checkout\CheckoutController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Checkout\CheckoutWebhookController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Coupons\CouponsController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Plans\PlansController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Signature\SignatureController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Team\TeamController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Team\TeamInvitationController;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Webhooks\WebhookController;

class RoutesApiKey
{
    public static function register(array $options = []): void
    {
        $options['middleware'][] = 'language';

        $prefix = config('api-key.routes.prefix', '');
        if (! empty($prefix)) {
            $options['prefix'] = ($options['prefix'] ?? '').'/'.trim((string) $prefix, '/');
        }

        Route::group($options, function () {

            // Tudo que é público e dispara e-mail ou confere senha entra no
            // limite: sem ele, /forgot-password e /email/resend serviam para
            // inundar a caixa de qualquer pessoa, e /reset-password para tentar
            // tokens à vontade.
            Route::middleware(self::throttle('auth_throttle', 5, 1, 'auth'))->group(function () {
                Route::post('/register', [AuthController::class, 'register']);
                Route::post('login', [AuthController::class, 'login']);
                Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email');
                Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
                Route::post('/email/resend', [AuthController::class, 'resendVerification'])->name('verification.send');
                Route::post('/login/two-factor', [TwoFactorController::class, 'verifyLogin']);

                // Convite para a equipe: ver e aceitar (define a senha e entra).
                Route::get('/team/invitations/{token}', [TeamInvitationController::class, 'show']);
                Route::post('/team/invitations/{token}/accept', [TeamInvitationController::class, 'accept']);
            });

            Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify')->whereUuid('id');
            Route::get('/email/change/{id}/{hash}', [AccountSecurityController::class, 'confirmEmailChange'])->name('email.change.confirm')->whereUuid('id');

            Route::get('/dashboard/plans', [PlansController::class, 'index']);

            Route::post('/dashboard/checkout/webhook', [CheckoutWebhookController::class, 'webhook']);
            // Pagar.me, Stripe, Efí e PagBank. Cada driver confere a própria
            // assinatura; o Mercado Pago segue na rota de cima.
            Route::post('/dashboard/checkout/webhook/{gateway}', [CheckoutWebhookController::class, 'gatewayWebhook'])
                ->whereIn('gateway', array_keys(GatewayRegistry::DRIVERS));

            Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
                // Equipe (ver Support\Team): `team.self` = rota pessoal do membro;
                // `team.role:owner` = só o dono. Sem marcação, a rota é da conta e
                // vale para admin da equipe (atendente não entra).
                Route::post('/logout', [AuthController::class, 'logout'])->middleware('team.self');
                Route::get('/auth/me', [AuthController::class, 'me'])->middleware('team.self');

                Route::middleware([...self::throttle('payment_throttle', 10, 10, 'payment'), 'team.role:owner'])->group(function () {
                    Route::post('/dashboard/checkout/process', [CheckoutController::class, 'process']);
                    Route::post('/dashboard/checkout/coupon', [CheckoutController::class, 'validateCoupon']);
                    Route::post('/dashboard/cards', [CardController::class, 'store']);
                    Route::post('/dashboard/cards/setup-intent', [CardController::class, 'setupIntent']);
                    Route::post('/dashboard/cards/{id}/automatic-billing', [CardController::class, 'enableAutomaticBilling'])->whereNumber('id');
                    Route::post('/dashboard/billing/overage/{id}/pix', [OverageController::class, 'payWithPix'])->whereUuid('id');
                    Route::post('/dashboard/billing/overage/{id}/card', [OverageController::class, 'payWithCard'])->whereUuid('id');
                });

                Route::get('/dashboard/checkout/payments/{paymentId}', [CheckoutController::class, 'paymentStatus'])->middleware('team.role:owner');

                Route::get('/dashboard/profile', [ProfileController::class, 'show'])->middleware('team.self');
                Route::put('/dashboard/profile', [ProfileController::class, 'update'])->middleware('team.self');
                Route::get('/dashboard/profile/export', [ProfileController::class, 'export'])->middleware('team.self');
                // Tudo que confere a senha atual divide um contador por usuário,
                // senão uma sessão roubada serviria para adivinhar a senha.
                Route::middleware([...self::throttle('auth_throttle', 5, 1, 'account'), 'team.self'])->group(function () {
                    Route::delete('/dashboard/profile', [ProfileController::class, 'destroy']);
                    Route::put('/dashboard/profile/password', [AccountSecurityController::class, 'changePassword']);
                    Route::post('/dashboard/profile/email', [AccountSecurityController::class, 'requestEmailChange']);
                    Route::post('/dashboard/two-factor', [TwoFactorController::class, 'enable']);
                    Route::post('/dashboard/two-factor/confirm', [TwoFactorController::class, 'confirm']);
                    Route::delete('/dashboard/two-factor', [TwoFactorController::class, 'disable']);
                    Route::post('/dashboard/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
                });

                Route::get('/dashboard/api-keys', [ApiKeyController::class, 'index']);
                Route::post('/dashboard/api-keys', [ApiKeyController::class, 'store']);
                Route::put('/dashboard/api-keys/{id}', [ApiKeyController::class, 'update'])->whereUuid('id');
                Route::post('/dashboard/api-keys/{id}/regenerate', [ApiKeyController::class, 'regenerate'])->whereUuid('id');
                Route::delete('/dashboard/api-keys/{id}', [ApiKeyController::class, 'destroy'])->whereUuid('id');

                Route::get('/dashboard/webhooks', [WebhookController::class, 'index']);
                Route::post('/dashboard/webhooks', [WebhookController::class, 'store']);
                Route::put('/dashboard/webhooks/{id}', [WebhookController::class, 'update'])->whereUuid('id');
                Route::delete('/dashboard/webhooks/{id}', [WebhookController::class, 'destroy'])->whereUuid('id');
                Route::post('/dashboard/webhooks/{id}/rotate-secret', [WebhookController::class, 'rotateSecret'])->whereUuid('id');
                Route::post('/dashboard/webhooks/{id}/test', [WebhookController::class, 'test'])->whereUuid('id');
                Route::get('/dashboard/webhooks/{id}/deliveries', [WebhookController::class, 'deliveries'])->whereUuid('id');
                Route::post('/dashboard/webhooks/{id}/deliveries/{deliveryId}/redeliver', [WebhookController::class, 'redeliver'])->whereUuid(['id', 'deliveryId']);

                Route::middleware('team.role:owner')->group(function () {
                    Route::get('/dashboard/billing/overage', [OverageController::class, 'show']);
                    Route::get('/dashboard/billing/renewal', [OverageController::class, 'renewal']);
                    Route::put('/dashboard/billing/overage', [OverageController::class, 'update']);

                    Route::get('/dashboard/receipts', [ReceiptController::class, 'index']);
                    Route::get('/dashboard/receipts/{id}', [ReceiptController::class, 'show'])->whereUuid('id');
                });

                Route::middleware('team.self')->group(function () {
                    Route::get('/dashboard/sessions', [AccountSecurityController::class, 'sessions']);
                    Route::delete('/dashboard/sessions', [AccountSecurityController::class, 'revokeOtherSessions']);
                    Route::delete('/dashboard/sessions/{id}', [AccountSecurityController::class, 'revokeSession'])->whereNumber('id');
                });

                // Equipe da conta: dono e admin gerenciam; o convite é aceito
                // fora do login (rotas públicas, acima).
                Route::get('/dashboard/team', [TeamController::class, 'index']);
                Route::post('/dashboard/team/invitations', [TeamController::class, 'invite']);
                Route::post('/dashboard/team/invitations/{id}/resend', [TeamController::class, 'resend'])->whereUuid('id');
                Route::delete('/dashboard/team/invitations/{id}', [TeamController::class, 'cancelInvitation'])->whereUuid('id');
                Route::put('/dashboard/team/members/{id}', [TeamController::class, 'updateMember'])->whereUuid('id');
                Route::delete('/dashboard/team/members/{id}', [TeamController::class, 'removeMember'])->whereUuid('id');
                // Lista enxuta de quem está na equipe (nome e papel), para
                // atribuir trabalho; liberada a atendentes.
                Route::get('/dashboard/team/members', [TeamController::class, 'members'])->middleware('team.role:agent');

                Route::get('/dashboard/profile/allowed', [ProfileController::class, 'getAllowedOrigins']);
                Route::post('/dashboard/profile/allowed', [ProfileController::class, 'updateAllowedOrigins']);
                Route::post('/dashboard/profile/regenerate-key', [ProfileController::class, 'regenerateKey']);

                Route::get('/dashboard/plans/{plan}', [PlansController::class, 'show'])->whereUuid('plan');
                Route::get('/dashboard/coupons', [CouponsController::class, 'index']);
                Route::get('/dashboard/coupons/{coupon}', [CouponsController::class, 'show'])->whereUuid('coupon');

                // Equipe do painel. O parâmetro do middleware é a permissão exigida
                // (ver AdminRoles); sem parâmetro vale `manage`, só do admin.
                Route::middleware(['admin:view'])->group(function () {
                    Route::get('/dashboard/admin/plans', [AdminController::class, 'plans']);
                    Route::get('/dashboard/admin/users', [AdminController::class, 'users']);
                    Route::get('/dashboard/admin/users/{id}', [AdminUserController::class, 'show'])->whereUuid('id');
                    Route::get('/dashboard/admin/refunds', [AdminController::class, 'refunds']);
                    Route::get('/dashboard/admin/payments', [AdminBillingController::class, 'payments']);
                    Route::get('/dashboard/admin/delinquency', [AdminBillingController::class, 'delinquency']);
                    Route::get('/dashboard/admin/features', [AdminController::class, 'features']);
                    Route::get('/dashboard/admin/metrics', [AdminController::class, 'metrics']);
                    Route::get('/dashboard/admin/audit', [AdminController::class, 'audit']);
                    Route::get('/dashboard/admin/health', SystemHealthController::class);
                    Route::get('/dashboard/admin/logs', [LogController::class, 'index']);
                    Route::get('/dashboard/admin/logs/{id}', [LogController::class, 'show'])->whereUuid('id');
                });

                Route::middleware(['admin:accounts'])->group(function () {
                    Route::post('/dashboard/admin/users/{id}/verification', [AdminUserController::class, 'resendVerification'])->whereUuid('id');
                    Route::post('/dashboard/admin/users/{id}/sessions/revoke', [AdminUserController::class, 'revokeSessions'])->whereUuid('id');
                    Route::post('/dashboard/admin/users/{id}/api-keys/revoke', [AdminUserController::class, 'revokeApiKeys'])->whereUuid('id');
                });

                Route::middleware(['admin:billing'])->group(function () {
                    Route::post('/dashboard/admin/refunds/{id}', [AdminController::class, 'processRefund'])->whereUuid('id');
                    Route::post('/dashboard/admin/users/{id}/subscriptions/{planId}/cancel', [AdminUserController::class, 'cancelSubscription'])->whereUuid(['id', 'planId']);
                    Route::post('/dashboard/admin/users/{id}/subscriptions/{planId}/extend', [AdminUserController::class, 'extendSubscription'])->whereUuid(['id', 'planId']);
                    Route::put('/dashboard/admin/users/{id}/subscriptions/{planId}/usage', [AdminUserController::class, 'adjustUsage'])->whereUuid(['id', 'planId']);
                    Route::post('/dashboard/admin/users/{id}/subscriptions/{planId}/retry-renewal', [AdminUserController::class, 'retryRenewal'])->whereUuid(['id', 'planId']);
                    Route::post('/dashboard/admin/users/{id}/subscriptions/{planId}/waive-overage', [AdminUserController::class, 'waiveOverage'])->whereUuid(['id', 'planId']);
                    Route::get('/dashboard/admin/export/{type}', [AdminBillingController::class, 'export']);
                });

                Route::middleware(['admin'])->group(function () {
                    Route::post('/dashboard/plans', [PlansController::class, 'store']);
                    Route::put('/dashboard/plans/{plan}', [PlansController::class, 'update'])->whereUuid('plan');
                    Route::delete('/dashboard/plans/{plan}', [PlansController::class, 'delete'])->whereUuid('plan');

                    Route::post('/dashboard/coupons', [CouponsController::class, 'store']);
                    Route::put('/dashboard/coupons/{coupon}', [CouponsController::class, 'update'])->whereUuid('coupon');
                    Route::delete('/dashboard/coupons/{coupon}', [CouponsController::class, 'delete'])->whereUuid('coupon');

                    Route::put('/dashboard/admin/users/{id}/status', [AdminController::class, 'updateUserStatus'])->whereUuid('id');
                    Route::post('/dashboard/admin/users/{id}/plan', [AdminController::class, 'grantPlan'])->whereUuid('id');
                    Route::put('/dashboard/admin/users/{id}/role', [AdminUserController::class, 'updateRole'])->whereUuid('id');
                    Route::post('/dashboard/admin/users/{id}/two-factor/reset', [AdminUserController::class, 'resetTwoFactor'])->whereUuid('id');

                    Route::get('/dashboard/admin/payment-settings', [PaymentSettingsController::class, 'show']);
                    Route::put('/dashboard/admin/payment-settings', [PaymentSettingsController::class, 'update']);
                    Route::post('/dashboard/admin/payment-settings/test/{gateway}', [PaymentSettingsController::class, 'test']);
                });

                Route::middleware('team.role:owner')->group(function () {
                    Route::get('/dashboard/cards', [CardController::class, 'index']);
                    Route::delete('/dashboard/cards/{id}', [CardController::class, 'destroy'])->whereNumber('id');

                    Route::post('/dashboard/signature', [SignatureController::class, 'signature']);
                    Route::get('/dashboard/signature/refund-preview', [SignatureController::class, 'refundPreview']);
                    Route::post('/dashboard/signature/cancel', [SignatureController::class, 'cancel']);
                    Route::post('/dashboard/signature/resume', [SignatureController::class, 'resume']);
                    Route::get('/dashboard/history', [SignatureController::class, 'history']);
                });
                Route::get('/dashboard/log', [SignatureController::class, 'log']);
                Route::get('/dashboard/stats', [SignatureController::class, 'stats']);
            });
        });
    }

    /**
     * Middleware `throttle` montado a partir de uma seção da config, ou nada
     * quando a seção está desligada.
     *
     * O prefixo separa os contadores: sem ele o Laravel chaveia só pelo usuário
     * (ou pelo IP), e grupos diferentes gastariam o mesmo saldo.
     *
     * @return list<string>
     */
    private static function throttle(string $section, int $attempts, int $decayMinutes, string $prefix): array
    {
        if (! config("api-key.{$section}.enabled", true)) {
            return [];
        }

        $attempts = config("api-key.{$section}.attempts", $attempts);
        $decayMinutes = config("api-key.{$section}.decay_minutes", $decayMinutes);

        return ["throttle:{$attempts},{$decayMinutes},{$prefix}"];
    }
}
