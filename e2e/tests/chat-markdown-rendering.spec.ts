import { expect, test, type Page, type Route } from '@playwright/test';

const BASE_URL = process.env.BASE_URL || 'http://localhost:8015';
const ANSWER = [
  '# Renderer check',
  '',
  '**Formatted text**',
  '',
  '- first item',
  '',
  '[unsafe link](javascript:alert(1))',
  '',
  '<img src=x onerror="window.__markdownXss = true">',
  '<iframe src="javascript:alert(1)"></iframe>',
  '<script>window.__markdownXss = true</script>',
].join('\n');

async function mockAssistantResponse(route: Route): Promise<void> {
  await route.fulfill({
    status: 200,
    contentType: 'text/event-stream',
    body: `event: assistant_message\ndata: ${JSON.stringify({
      content: ANSWER,
      content_format: 'markdown',
    })}\n\n`,
  });
}

async function openChatWithMockedResponse(page: Page): Promise<void> {
  await page.route('**/api/v2/chat/completions', mockAssistantResponse);
  await page.goto(`${BASE_URL}/chat`);
}

async function submitMessage(page: Page) {
  await page.getByRole('textbox', { name: 'پیام خود را بنویسید...' }).fill('نمایش را بررسی کن');
  await page.getByRole('button', { name: 'ارسال' }).click();

  const bubble = page.locator('.msg.assistant .bubble');
  await expect(bubble).toBeVisible();
  return bubble;
}

test.describe('chat Markdown renderer', () => {
  test('renders Markdown while removing unsafe HTML and URL schemes', async ({ page }) => {
    await openChatWithMockedResponse(page);

    const bubble = await submitMessage(page);

    await expect(bubble.locator('h1')).toHaveText('Renderer check');
    await expect(bubble.locator('strong')).toHaveText('Formatted text');
    await expect(bubble.locator('ul > li')).toHaveText('first item');
    await expect(bubble.locator('a')).toHaveText('unsafe link');
    await expect(bubble.locator('a')).not.toHaveAttribute('href');
    await expect(bubble.locator('script, iframe, [onerror]')).toHaveCount(0);
    expect(await page.evaluate(() => (window as Window & { __markdownXss?: boolean }).__markdownXss))
      .toBeUndefined();
  });

  for (const dependency of ['marked', 'dompurify'] as const) {
    test(`escapes assistant text when ${dependency} is unavailable`, async ({ page }) => {
      const scriptUrl = dependency === 'marked'
        ? '**/js/vendor/marked.min.js'
        : '**/js/vendor/purify.min.js';
      await page.route(scriptUrl, route => route.abort());
      await openChatWithMockedResponse(page);

      const bubble = await submitMessage(page);

      await expect(bubble).toHaveText(ANSWER);
      await expect(bubble.locator('h1, strong, ul, a, img, script, iframe, [onerror]')).toHaveCount(0);
      expect(await page.evaluate(() => (window as Window & { __markdownXss?: boolean }).__markdownXss))
        .toBeUndefined();
    });
  }
});
