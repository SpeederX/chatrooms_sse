import { test, expect } from '@playwright/test';

test('index.php baseline renders', async ({ page }) => {
    await page.goto('/index.php');
    // main.js assigns a random user id on load — freeze it so the baseline is deterministic
    await page.locator('#user_id').evaluate((el) => {
        (el as HTMLInputElement).value = '00-00-00';
    });
    await expect(page).toHaveScreenshot('index.png', { fullPage: true });
});
