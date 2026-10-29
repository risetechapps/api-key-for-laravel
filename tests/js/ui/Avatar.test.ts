import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Avatar from '@/ui/Avatar.vue';

describe('Avatar', () => {
    it('com foto e nome nulo, não escreve alt="null"', () => {
        const img = mount(Avatar, { props: { name: null, src: '/foto.png' } }).find('img');

        expect(img.attributes('alt')).toBeUndefined();
    });

    it('usa o nome como alt da foto', () => {
        expect(mount(Avatar, { props: { name: 'Ana Souza', src: '/foto.png' } }).find('img').attributes('alt')).toBe('Ana Souza');
    });
});
