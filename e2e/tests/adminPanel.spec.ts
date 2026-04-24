import { test, expect, Page } from '@playwright/test';

const ADMIN_PASSWORD = 'admin_test_pw';
const suffix = Date.now().toString().slice(-8);

async function adminLoginExpectSuccess(page: Page, password: string = ADMIN_PASSWORD) {
    await page.goto('/adminLogin.php');
    await page.locator('input[name="password"]').fill(password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/adminPanel\.php$/);
}

async function adminLoginExpectFailure(page: Page, password: string) {
    await page.goto('/adminLogin.php');
    await page.locator('input[name="password"]').fill(password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/adminLogin\.php\?error=1$/);
}

async function joinChat(page: Page, nickname: string) {
    await page.goto('/index.php');
    await expect(page.locator('#join_dialog')).toHaveJSProperty('open', true);
    const subscribed = page.waitForResponse((r) => r.url().includes('chatPoll.php'));
    await page.locator('#nickname_input').fill(nickname);
    await page.locator('#join_form button[type="submit"]').click();
    await subscribed;
}

async function resetConfigToDefaults(page: Page) {
    await adminLoginExpectSuccess(page);
    await page.locator('input[name="message_max_length"]').fill('200');
    await page.locator('input[name="cooldown_base_seconds"]').fill('3');
    await page.locator('input[name="history_size"]').fill('50');
    await page.locator('input[name="nickname_min_length"]').fill('2');
    await page.locator('input[name="nickname_max_length"]').fill('20');
    await page.locator('input[name="session_ttl_minutes"]').fill('15');
    await page.locator('input[name="active_user_window_minutes"]').fill('12');
    await page.locator('#config_form button[type="submit"]').click();
    await page.waitForURL(/adminPanel\.php\?success=config/);
}

test.afterEach(async ({ browser }) => {
    // Defensive reset — tests that changed config should leave it at defaults.
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
        await resetConfigToDefaults(page);
    } finally {
        await ctx.close();
    }
});

test('login fails with wrong password and shows error', async ({ page }) => {
    await adminLoginExpectFailure(page, 'definitely_wrong');
    await expect(page.locator('#login_error')).toBeVisible();
    await expect(page.locator('#login_error')).toContainText('Invalid credentials');
});

test('login success renders stats cards and config form', async ({ page }) => {
    await adminLoginExpectSuccess(page);
    await expect(page.locator('h1')).toHaveText('Admin panel');
    await expect(page.locator('#stat_total_messages')).toBeVisible();
    await expect(page.locator('#stat_total_chars')).toBeVisible();
    await expect(page.locator('#stat_avg_message_length')).toBeVisible();
    await expect(page.locator('#stat_total_users')).toBeVisible();
    await expect(page.locator('#stat_active_users_now')).toBeVisible();
    await expect(page.locator('#config_form input[type="number"]')).toHaveCount(7);
    await expect(page.locator('input[name="history_size"]')).toHaveValue('50');
    await expect(page.locator('input[name="cooldown_base_seconds"]')).toHaveValue('3');
});

test('history cleanup clears backfill but keeps stats counters', async ({ browser }) => {
    const seedCtx = await browser.newContext();
    const seedPage = await seedCtx.newPage();
    await joinChat(seedPage, `seed_${suffix}`);
    const msg = `cleanup_me_${suffix}`;
    await seedPage.locator('#text_value').fill(msg);
    await seedPage.locator('#send_message').click();
    await expect(
        seedPage.locator('#message_container li').filter({ hasText: msg }),
    ).toHaveCount(1);

    const adminCtx = await browser.newContext();
    const adminPage = await adminCtx.newPage();
    await adminLoginExpectSuccess(adminPage);

    const totalBefore = await adminPage.locator('#stat_total_messages').textContent();

    adminPage.on('dialog', (d) => d.accept());
    await adminPage.locator('#cleanup_form button[type="submit"]').click();
    await adminPage.waitForURL(/adminPanel\.php\?success=cleanup/);

    const totalAfter = await adminPage.locator('#stat_total_messages').textContent();
    expect(totalAfter).toBe(totalBefore);

    const freshCtx = await browser.newContext();
    const freshPage = await freshCtx.newPage();
    await joinChat(freshPage, `fresh_${suffix}`);
    await expect(
        freshPage.locator('#message_container li').filter({ hasText: msg }),
    ).toHaveCount(0);

    await seedCtx.close();
    await adminCtx.close();
    await freshCtx.close();
});

test('config change reflected in chat (cooldown=0 allows back-to-back sends)', async ({ browser }) => {
    const adminCtx = await browser.newContext();
    const adminPage = await adminCtx.newPage();
    await adminLoginExpectSuccess(adminPage);

    await adminPage.locator('input[name="cooldown_base_seconds"]').fill('0');
    await adminPage.locator('#config_form button[type="submit"]').click();
    await adminPage.waitForURL(/adminPanel\.php\?success=config/);

    const chatCtx = await browser.newContext();
    const chatPage = await chatCtx.newPage();
    await joinChat(chatPage, `nocd_${suffix}`);

    const first = `cd_one_${suffix}`;
    await chatPage.locator('#text_value').fill(first);
    await chatPage.locator('#send_message').click();
    await expect(
        chatPage.locator('#message_container li').filter({ hasText: first }),
    ).toHaveCount(1);

    const second = `cd_two_${suffix}`;
    await chatPage.locator('#text_value').fill(second);
    await chatPage.locator('#send_message').click();
    await expect(
        chatPage.locator('#message_container li').filter({ hasText: second }),
    ).toHaveCount(1);

    await expect(
        chatPage.locator('#message_container li').filter({ hasText: '[system]' }),
    ).toHaveCount(0);

    await chatCtx.close();
    await adminCtx.close();
});

test('config update rejects out-of-bounds value without saving', async ({ page }) => {
    await adminLoginExpectSuccess(page);
    const before = await page.locator('input[name="history_size"]').inputValue();

    // Bypass the HTML min/max to smoke-test server-side validation.
    await page.locator('input[name="history_size"]').evaluate((el) => {
        (el as HTMLInputElement).removeAttribute('max');
        (el as HTMLInputElement).value = '9999';
    });
    await page.locator('#config_form button[type="submit"]').click();
    await page.waitForURL(/adminPanel\.php\?error=config/);
    await expect(page.locator('#admin_error')).toBeVisible();

    // Value in DB should be unchanged — next page load shows the original.
    await page.goto('/adminPanel.php');
    await expect(page.locator('input[name="history_size"]')).toHaveValue(before);
});

test('logout destroys session and panel redirects to login', async ({ page }) => {
    await adminLoginExpectSuccess(page);
    await page.locator('.admin__header form button[type="submit"]').click();
    await page.waitForURL(/adminLogin\.php$/);

    await page.goto('/adminPanel.php');
    await page.waitForURL(/adminLogin\.php$/);
});
