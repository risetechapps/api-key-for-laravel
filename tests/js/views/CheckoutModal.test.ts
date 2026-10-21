import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import CheckoutModal from '@/views/dashboard/CheckoutModal.vue';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';

// O Modal real usa o Dialog do Headless UI, que teleporta para o body e
// segura o foco; aqui só interessa o conteúdo.
const stubs = {
    Modal: { template: '<div><slot /><slot name="footer" /></div>' },
    GatewayCardFields: { template: '<div data-test="gateway-card-fields" />' },
};

const paidPlan = { id: 'plan-1', name: 'Pro', price: 'R$ 50,00', raw_price: 50, billing_cycle: 'monthly', trial_days: 0 };

function card(overrides: Record<string, unknown>) {
    return { id: 1, brand: 'visa', last_four: '4242', holder_name: 'ANA', expiry_month: 3, expiry_year: 2031, ...overrides };
}

async function open(plan: Record<string, unknown>, user: Record<string, unknown>, cards: Record<string, unknown>[] = []) {
    const auth = useAuthStore();
    const dashboard = useDashboardStore();
    auth.user = { email: 'ana@example.com', ...user } as any;

    // fetchSavedCards() iria ao servidor; aqui ele só entrega a lista.
    vi.spyOn(dashboard, 'fetchSavedCards').mockImplementation(async () => {
        dashboard.savedCards = cards as any;
    });

    const wrapper = mount(CheckoutModal, { props: { modelValue: false, plan }, global: { stubs } });
    await wrapper.setProps({ modelValue: true });
    await flushPromises();

    return { wrapper, auth, dashboard };
}

describe('CheckoutModal', () => {
    beforeEach(() => setActivePinia(createPinia()));

    it('activates a free plan without asking for a payment method or coupon', async () => {
        const { wrapper } = await open({ ...paidPlan, raw_price: 0 }, { payment_gateway: 'stripe' });

        expect(wrapper.text()).toContain('Ativar Gratuitamente');
        expect(wrapper.text()).not.toContain('Cupom de desconto');
    });

    it('lists only the saved cards of the active gateway', async () => {
        // Um cartão só pode ser cobrado pelo gateway que o guardou.
        const { wrapper } = await open(paidPlan, { payment_gateway: 'stripe' }, [
            card({ id: 1, gateway: 'stripe', last_four: '4242' }),
            card({ id: 2, gateway: 'mercadopago', last_four: '1111', mp_card_id: 'mp-2' }),
        ]);

        const cards = wrapper.findAll('[role="radio"]');
        expect(cards).toHaveLength(1);
        expect(cards[0].text()).toContain('4242');
        expect(wrapper.text()).not.toContain('1111');
    });

    it('charges a saved driver card by id, without CVV', async () => {
        const { wrapper, dashboard } = await open(paidPlan, { payment_gateway: 'stripe' }, [card({ id: 7, gateway: 'stripe' })]);
        const processCheckout = vi.spyOn(dashboard, 'processCheckout').mockResolvedValue({ status: 'rejected' } as any);

        await wrapper.find('[role="radio"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).not.toContain('CVV');
        await wrapper.findAll('button').find((b) => b.text().startsWith('Pagar'))!.trigger('click');
        await flushPromises();

        expect(processCheckout).toHaveBeenCalledWith('plan-1', { saved_card_id: 7 }, null);
    });

    it('offers PIX only when the account has it enabled', async () => {
        const without = await open(paidPlan, { payment_gateway: 'stripe', pix_enabled: false });
        expect(without.wrapper.text()).not.toContain('PIX');

        setActivePinia(createPinia());
        const withPix = await open(paidPlan, { payment_gateway: 'stripe', pix_enabled: true });
        expect(withPix.wrapper.text()).toContain('PIX');
    });

    it('offers the trial only when the server would grant it', async () => {
        // Mesmas condições do CheckoutController::trialFor(): plano com teste,
        // conta que ainda não usou e um jeito de cobrar no fim.
        const trialPlan = { ...paidPlan, trial_days: 7 };

        const eligible = await open(trialPlan, { payment_gateway: 'stripe', trial_available: true }, [card({ gateway: 'stripe', automatic_billing: true })]);
        expect(eligible.wrapper.text()).toContain('7 dias grátis');

        setActivePinia(createPinia());
        const used = await open(trialPlan, { payment_gateway: 'stripe', trial_available: false }, [card({ gateway: 'stripe', automatic_billing: true })]);
        expect(used.wrapper.text()).not.toContain('dias grátis');

        setActivePinia(createPinia());
        const noWayToCharge = await open(trialPlan, { payment_gateway: 'stripe', trial_available: true });
        expect(noWayToCharge.wrapper.text()).not.toContain('dias grátis');
    });
});
