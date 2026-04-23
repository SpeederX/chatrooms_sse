import { test, expect } from '@playwright/test';

const suffix = Date.now().toString().slice(-8);

async function join(page: import('@playwright/test').Page, nickname: string) {
    await page.goto('/index.php');
    await expect(page.locator('#join_dialog')).toHaveJSProperty('open', true);
    // Subscription is guaranteed once chatPoll.php response headers arrive,
    // which happens right after joinChat.php success + sseHandler.connect().
    const subscribed = page.waitForResponse((r) => r.url().includes('chatPoll.php'));
    await page.locator('#nickname_input').fill(nickname);
    await page.locator('#join_form button[type="submit"]').click();
    await subscribed;
}

test('message sent from one client appears in another (golden path)', async ({ browser }) => {
    const contextA = await browser.newContext();
    const pageA = await contextA.newPage();
    const contextB = await browser.newContext();
    const pageB = await contextB.newPage();

    await join(pageA, `alice_${suffix}`);
    await join(pageB, `bob_${suffix}`);

    const msg = `hello from A ${suffix}`;
    await pageA.locator('#text_value').fill(msg);
    await pageA.locator('#send_message').click();

    await expect(
        pageB.locator('#message_container li').filter({ hasText: msg }),
    ).toHaveCount(1);

    await contextA.close();
    await contextB.close();
});
