/**
 * Fetches all pages from a paginated Laravel API endpoint.
 * Uses `meta.last_page` to determine the total number of pages,
 * so only a single request is made when all data fits on one page.
 */
export async function fetchAllPages<T>(
    fetchPage: (page: number) => Promise<{
        data: T[];
        meta: { per_page: number; last_page: number };
    }>
): Promise<T[]> {
    const firstResponse = await fetchPage(1);
    const allItems: T[] = [...firstResponse.data];
    const { last_page } = firstResponse.meta;

    const concurrency = 3;
    for (let page = 2; page <= last_page; page += concurrency) {
        const responses = await Promise.all(
            Array.from({ length: Math.min(concurrency, last_page - page + 1) }, (_, offset) =>
                fetchPage(page + offset)
            )
        );
        for (const response of responses) allItems.push(...response.data);
    }

    return allItems;
}
