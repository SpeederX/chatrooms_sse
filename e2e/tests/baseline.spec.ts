import { test, expect } from '@playwright/test';

test('index.php baseline renders', async ({ page }) => {
    await page.goto('/index.php');
    // nickname dialog opens on load; snapshot that state
    await expect(page.locator('#join_dialog')).toHaveJSProperty('open', true);
    await expect(page).toHaveScreenshot('index.png', { fullPage: true, caret: 'hide' });
});
