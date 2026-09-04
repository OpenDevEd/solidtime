import { expect, test } from '../playwright/fixtures';
import { PLAYWRIGHT_BASE_URL } from '../playwright/config';
import { getInvitationAcceptUrl } from './utils/mailpit';
import { createAuthenticatedTestUser } from './utils/members';

// Invitation acceptance flows touch mail delivery + redirects.
test.describe.configure({ timeout: 45000 });

test.describe('invitation accept banners', () => {
    test('shows success banner on dashboard when a logged-in user accepts an invitation', async ({
        page,
        browser,
    }) => {
        const memberId = Math.floor(Math.random() * 100000);
        const memberEmail = `success+${memberId}@invite-banner.test`;

        // Invitee already has an account and is logged in.
        const invitee = await createAuthenticatedTestUser(browser, 'Banner Success', memberEmail);

        // Owner sends the invitation.
        await page.goto(PLAYWRIGHT_BASE_URL + '/members');
        await page.getByRole('button', { name: 'Invite Member' }).click();
        await expect(page.getByPlaceholder('Member Email')).toBeVisible();
        await page.getByLabel('Email').fill(memberEmail);
        await page.getByRole('button', { name: 'Employee' }).click();
        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/invitations') &&
                    response.request().method() === 'POST' &&
                    response.status() === 204
            ),
            page.getByRole('button', { name: 'Invite Member', exact: true }).click(),
        ]);

        // Invitee clicks the email link.
        const acceptUrl = await getInvitationAcceptUrl(invitee.page.request, memberEmail);
        await invitee.page.goto(acceptUrl);
        await invitee.page.waitForURL(/\/dashboard$/);

        const banner = invitee.page.getByTestId('banner');
        await expect(banner).toBeVisible();
        await expect(banner).toContainText(
            /Great! You have accepted the invitation to join the .* organization\./
        );

        await invitee.close();
    });

    test('shows info banner on login screen when a registered-but-logged-out invitee clicks the accept link', async ({
        page,
        browser,
    }) => {
        const memberId = Math.floor(Math.random() * 100000);
        const memberEmail = `loggedout+${memberId}@invite-banner.test`;

        // Invitee has an account, but the context that clicks the link has no session.
        const invitee = await createAuthenticatedTestUser(browser, 'Banner Loggedout', memberEmail);
        await invitee.close();

        // Owner sends the invitation.
        await page.goto(PLAYWRIGHT_BASE_URL + '/members');
        await page.getByRole('button', { name: 'Invite Member' }).click();
        await expect(page.getByPlaceholder('Member Email')).toBeVisible();
        await page.getByLabel('Email').fill(memberEmail);
        await page.getByRole('button', { name: 'Employee' }).click();
        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/invitations') &&
                    response.request().method() === 'POST' &&
                    response.status() === 204
            ),
            page.getByRole('button', { name: 'Invite Member', exact: true }).click(),
        ]);

        // Open the accept link in a fresh browser context (no session).
        const context = await browser.newContext();
        const inviteePage = await context.newPage();
        const acceptUrl = await getInvitationAcceptUrl(inviteePage.request, memberEmail);
        await inviteePage.goto(acceptUrl);
        await inviteePage.waitForURL(/\/login$/);

        const banner = inviteePage.getByTestId('banner');
        await expect(banner).toBeVisible();
        await expect(banner).toContainText(
            /Great! You have accepted the invitation to join the .* organization\. Please log in to access it\./
        );

        await page.goto(PLAYWRIGHT_BASE_URL + '/members');
        const memberRow = page.getByRole('row').filter({ hasText: 'Banner Loggedout' });
        await expect(memberRow).toBeVisible();
        await expect(memberRow.getByText('Employee', { exact: true })).toBeVisible();

        await context.close();
    });

    test('sends an unregistered invitee to Google login after accepting an invitation', async ({
        page,
        browser,
    }) => {
        const memberId = Math.floor(Math.random() * 100000);
        const memberEmail = `info+${memberId}@invite-banner.test`;

        // Owner invites an email that has no account yet.
        await page.goto(PLAYWRIGHT_BASE_URL + '/members');
        await page.getByRole('button', { name: 'Invite Member' }).click();
        await expect(page.getByPlaceholder('Member Email')).toBeVisible();
        await page.getByLabel('Email').fill(memberEmail);
        await page.getByRole('button', { name: 'Employee' }).click();
        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().includes('/invitations') &&
                    response.request().method() === 'POST' &&
                    response.status() === 204
            ),
            page.getByRole('button', { name: 'Invite Member', exact: true }).click(),
        ]);

        // Open the accept link in a fresh browser context (no session).
        const context = await browser.newContext();
        const inviteePage = await context.newPage();
        const acceptUrl = await getInvitationAcceptUrl(inviteePage.request, memberEmail);
        await inviteePage.goto(acceptUrl);
        await inviteePage.waitForURL(/\/login$/);

        const banner = inviteePage.getByTestId('banner');
        await expect(banner).toBeVisible();
        await expect(banner).toContainText(
            /Please sign in with Google to finish joining the .* organization\./
        );

        await context.close();
    });
});
