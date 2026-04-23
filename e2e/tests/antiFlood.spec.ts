import { test, expect, Page } from '@playwright/test';

const suffix = Date.now().toString().slice(-8);

async function join(page: Page, nickname: string, expectSuccess = true) {
    await page.goto('/index.php');
    await expect(page.locator('#join_dialog')).toHaveJSProperty('open', true);
    if (expectSuccess) {
        const subscribed = page.waitForResponse((r) => r.url().includes('chatPoll.php'));
        await page.locator('#nickname_input').fill(nickname);
        await page.locator('#join_form button[type="submit"]').click();
        await subscribed;
    } else {
        const rejected = page.waitForResponse(
            (r) => r.url().includes('joinChat.php') && r.status() === 409,
        );
        await page.locator('#nickname_input').fill(nickname);
        await page.locator('#join_form button[type="submit"]').click();
        await rejected;
    }
}

test('valid nickname closes dialog and enables chat UI', async ({ page }) => {
    await join(page, `ok_${suffix}`);
    await expect(page.locator('#join_dialog')).toHaveJSProperty('open', false);
    await expect(page.locator('#text_value')).toBeEnabled();
});

test('duplicate nickname keeps dialog open with error', async ({ browser }) => {
    const nick = `dup_${suffix}`;
    const ctxA = await browser.newContext();
    const pageA = await ctxA.newPage();
    await join(pageA, nick);

    const ctxB = await browser.newContext();
    const pageB = await ctxB.newPage();
    await join(pageB, nick, false);

    await expect(pageB.locator('#join_dialog')).toHaveJSProperty('open', true);
    await expect(pageB.locator('#join_error')).toBeVisible();
    await expect(pageB.locator('#join_error')).toContainText('Nickname already in use');

    await ctxA.close();
    await ctxB.close();
});

test('char counter increments live', async ({ page }) => {
    await join(page, `cnt_${suffix}`);
    await page.locator('#text_value').fill('abc');
    await expect(page.locator('#char_counter')).toHaveText('3/200');
    await page.locator('#text_value').fill('hello world');
    await expect(page.locator('#char_counter')).toHaveText('11/200');
});

test('send button disabled when empty, enabled when non-empty', async ({ page }) => {
    await join(page, `btn_${suffix}`);
    await expect(page.locator('#send_message')).toBeDisabled();
    await page.locator('#text_value').fill('hi');
    await expect(page.locator('#send_message')).toBeEnabled();
    await page.locator('#text_value').fill('');
    await expect(page.locator('#send_message')).toBeDisabled();
});

test('rapid second send triggers cooldown system message', async ({ page }) => {
    await join(page, `cd_${suffix}`);
    const first = `first_${suffix}`;
    await page.locator('#text_value').fill(first);
    await page.locator('#send_message').click();
    // Wait for the server echo via SSE — filter by our unique text so the
    // assertion is robust against any backfill that preceded the send.
    await expect(
        page.locator('#message_container li').filter({ hasText: first }),
    ).toHaveCount(1);

    await page.locator('#text_value').fill(`spam_${suffix}`);
    await page.locator('#send_message').click();

    await expect(page.locator('#message_container li').filter({ hasText: '[system]' }))
        .toContainText('Wait 3 seconds');
});

test('over-200-char message rejected with system message', async ({ page }) => {
    await join(page, `ovr_${suffix}`);
    // Bypass the client-side maxlength guard by setting the value directly
    await page.locator('#text_value').evaluate((el) => {
        (el as HTMLInputElement).value = 'a'.repeat(201);
    });
    // Force-enable the button (the input listener hasn't fired after .evaluate)
    await page.locator('#send_message').evaluate((el) => {
        (el as HTMLButtonElement).disabled = false;
    });
    await page.locator('#send_message').click();

    await expect(page.locator('#message_container li').filter({ hasText: '[system]' }))
        .toContainText('Message exceeds 200 characters');
});
