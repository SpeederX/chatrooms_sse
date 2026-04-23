import { test, expect, Page } from '@playwright/test';

const suffix = Date.now().toString().slice(-8);

async function join(page: Page, nickname: string) {
    await page.goto('/index.php');
    await expect(page.locator('#join_dialog')).toHaveJSProperty('open', true);
    const subscribed = page.waitForResponse((r) => r.url().includes('chatPoll.php'));
    await page.locator('#nickname_input').fill(nickname);
    await page.locator('#join_form button[type="submit"]').click();
    await subscribed;
}

async function sendOnce(page: Page, text: string) {
    await page.locator('#text_value').fill(text);
    await page.locator('#send_message').click();
    // Self-echo via SSE proves the insert committed; filter by text because
    // the container may already hold backfilled messages from previous seeders.
    await expect(
        page.locator('#message_container li').filter({ hasText: text }),
    ).toHaveCount(1);
}

test('new joiner receives the last N messages as backfill', async ({ browser }) => {
    // Seed three messages across three independent sessions — each has its
    // own cooldown, so no 3s waits are needed between sends.
    const seeders = await Promise.all([
        browser.newContext().then((c) => c.newPage()),
        browser.newContext().then((c) => c.newPage()),
        browser.newContext().then((c) => c.newPage()),
    ]);
    await join(seeders[0], `s1_${suffix}`);
    await sendOnce(seeders[0], `one_${suffix}`);
    await join(seeders[1], `s2_${suffix}`);
    await sendOnce(seeders[1], `two_${suffix}`);
    await join(seeders[2], `s3_${suffix}`);
    await sendOnce(seeders[2], `three_${suffix}`);

    // Fresh reader joins — no Last-Event-ID, so the server emits the backfill
    const readerCtx = await browser.newContext();
    const reader = await readerCtx.newPage();
    await join(reader, `r_${suffix}`);

    // Filter to the three items tagged with our unique suffix. Ignores any
    // pre-existing rows from earlier runs.
    const ourItems = reader.locator('#message_container li').filter({ hasText: suffix });
    await expect(ourItems).toContainText([
        `one_${suffix}`,
        `two_${suffix}`,
        `three_${suffix}`,
    ]);

    await Promise.all([
        ...seeders.map((p) => p.context().close()),
        readerCtx.close(),
    ]);
});
