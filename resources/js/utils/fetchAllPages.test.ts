import { describe, expect, it } from 'vitest';
import { fetchAllPages } from './fetchAllPages';

describe('fetchAllPages', () => {
    it('fetches pages concurrently within a limit and preserves page order', async () => {
        let active = 0;
        let peak = 0;
        const result = await fetchAllPages(async (page) => {
            active++;
            peak = Math.max(peak, active);
            await new Promise((resolve) => setTimeout(resolve, page % 2 ? 5 : 15));
            active--;
            return { data: [page], meta: { per_page: 1, last_page: 8 } };
        });
        expect(result).toEqual([1, 2, 3, 4, 5, 6, 7, 8]);
        expect(peak).toBe(3);
    });

    it('rejects instead of returning a partial list when a page fails', async () => {
        await expect(
            fetchAllPages(async (page) => {
                if (page === 2) throw new Error('Request failed');
                return { data: [page], meta: { per_page: 1, last_page: 3 } };
            })
        ).rejects.toThrow('Request failed');
    });

    it('fetches only once for a single page', async () => {
        const pages: number[] = [];
        expect(
            await fetchAllPages(async (page) => {
                pages.push(page);
                return { data: [], meta: { per_page: 250, last_page: 1 } };
            })
        ).toEqual([]);
        expect(pages).toEqual([1]);
    });
});
