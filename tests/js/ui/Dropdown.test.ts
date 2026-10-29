import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Dropdown from '@/ui/Dropdown.vue';

// Gatilho colado ao fim da tela (como a última linha de uma tabela longa).
function rect(top: number, right = 300): DOMRect {
    return { top, bottom: top + 32, left: right - 32, right, width: 32, height: 32, x: right - 32, y: top, toJSON: () => ({}) } as DOMRect;
}

async function openMenu(triggerTop: number) {
    const edit = vi.fn();
    const wrapper = mount(Dropdown, {
        attachTo: document.body,
        props: { items: [{ label: 'Editar', onClick: edit }, { label: 'Excluir', danger: true }] },
        global: { stubs: { RouterLink: true } },
    });
    const anchor = wrapper.find('div.inline-flex').element as HTMLElement;
    vi.spyOn(anchor, 'getBoundingClientRect').mockReturnValue(rect(triggerTop));
    await wrapper.find('button').trigger('click');
    await flushPromises();

    return { wrapper, edit, menu: document.body.querySelector('[role="menu"]') as HTMLElement | null };
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Dropdown', () => {
    it('abre no body, fora do contêiner com overflow, e fixo junto ao gatilho', async () => {
        const { wrapper, menu } = await openMenu(100);

        expect(menu).not.toBeNull();
        expect(wrapper.element.contains(menu)).toBe(false);
        expect(menu!.className).toContain('fixed');
        expect(menu!.style.top).toBe(`${100 + 32 + 8}px`);
        expect(menu!.style.right).toBe(`${window.innerWidth - 300}px`);
    });

    it('abre para cima quando não cabe embaixo', async () => {
        vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockReturnValue(120);
        const { menu } = await openMenu(window.innerHeight - 40);

        expect(menu!.className).toContain('origin-bottom-right');
        expect(menu!.style.top).toBe(`${window.innerHeight - 40 - 8 - 120}px`);
        vi.restoreAllMocks();
    });

    it('o clique no item chega à ação', async () => {
        const { menu, edit } = await openMenu(100);

        (menu!.querySelector('button') as HTMLElement).click();

        expect(edit).toHaveBeenCalledOnce();
    });
});
