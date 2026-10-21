import { describe, expect, it } from 'vitest';
import { escapeHtml } from '@/utils/escapeHtml';

describe('escapeHtml', () => {
    it('neutralises markup typed into a name before it reaches a dialog', () => {
        expect(escapeHtml('<img src=x onerror="alert(\'x\')">')).toBe('&lt;img src=x onerror=&quot;alert(&#39;x&#39;)&quot;&gt;');
    });

    it('escapes the ampersand first, so entities are not double-decoded', () => {
        expect(escapeHtml('&lt;')).toBe('&amp;lt;');
    });

    it('turns empty values into an empty string', () => {
        expect(escapeHtml(null)).toBe('');
        expect(escapeHtml(undefined)).toBe('');
    });
});
