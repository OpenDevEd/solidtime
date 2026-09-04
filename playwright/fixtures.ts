import { test as baseTest } from '@playwright/test';
import type { Page } from '@playwright/test';
import { PLAYWRIGHT_BASE_URL } from './config';
import { type TestContext, setupTestContext } from '../e2e/utils/api';
import { authenticateTestUser, setupAdminUser, setupEmployeeUser } from '../e2e/utils/members';

export * from '@playwright/test';
export type { TestContext };

export interface EmployeeFixture {
    page: Page;
    memberId: string;
}

export interface AdminFixture {
    page: Page;
    memberId: string;
}

/**
 * API-based authentication fixture - creates a new user via HTTP requests instead of UI interactions.
 * This is ~10-25x faster than UI-based authentication (~100-200ms vs ~3-5s).
 *
 * Uses page.context().request() to ensure cookies are shared between the API request and page.
 */
export const test = baseTest.extend<
    { ctx: TestContext; employee: EmployeeFixture; admin: AdminFixture },
    { workerStorageState: string }
>({
    page: async ({ page }, use) => {
        // Generate unique email for this test
        const email = `john+${Date.now()}_${Math.floor(Math.random() * 10000)}@doe.com`;
        const name = 'John Doe';
        const timezone = await page.evaluate(
            () => Intl.DateTimeFormat().resolvedOptions().timeZone
        );

        await authenticateTestUser(page, name, email, timezone);
        await page.goto(`${PLAYWRIGHT_BASE_URL}/dashboard`);
        await page.waitForLoadState('domcontentloaded');

        await use(page);
    },

    ctx: async ({ page }, use) => {
        const ctx = await setupTestContext(page);
        await use(ctx);
    },

    employee: async ({ page, ctx, browser }, use) => {
        const { employeePage, employeeMemberId, closeEmployee } = await setupEmployeeUser(
            page,
            ctx,
            browser
        );
        await use({ page: employeePage, memberId: employeeMemberId });
        await closeEmployee();
    },

    admin: async ({ page, ctx, browser }, use) => {
        const { adminPage, adminMemberId, closeAdmin } = await setupAdminUser(page, ctx, browser);
        await use({ page: adminPage, memberId: adminMemberId });
        await closeAdmin();
    },
});
