import { test, expect } from '@playwright/test';

test('message sent from one client appears in another (golden path)', async ({ browser }) => {
    const contextA = await browser.newContext();
    const pageA = await contextA.newPage();
    const contextB = await browser.newContext();
    const pageB = await contextB.newPage();

    await pageA.goto('/index.php');
    await pageB.goto('/index.php');

    await pageA.locator('#user_id').evaluate((el) => {
        (el as HTMLInputElement).value = 'aaa';
    });
    await pageB.locator('#user_id').evaluate((el) => {
        (el as HTMLInputElement).value = 'bbb';
    });

    // Response headers arriving guarantee the server-side cursor has been set
    // (chatPoll.php flushes the ": connected" comment right after max_message_id()),
    // so A's send can't race B's subscription.
    const bSubscribed = pageB.waitForResponse((r) => r.url().includes('chatPoll.php'));
    await pageB.locator('#join_chat').click();
    await bSubscribed;

    // Register the dialog handler BEFORE the click that triggers it
    const sentAlert = pageA.waitForEvent('dialog');

    await pageA.locator('#text_value').fill('hello from A');
    await pageA.locator('#send_message').click();

    // A gets a native "Message sent" alert on successful POST
    const dialog = await sentAlert;
    expect(dialog.message()).toBe('Message sent');
    await dialog.accept();

    // B receives the SSE-delivered message
    await expect(pageB.locator('#message_container li'))
        .toContainText('aaa: hello from A');

    await contextA.close();
    await contextB.close();
});
