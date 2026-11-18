import { expect, test, type Page, type Route } from '@playwright/test';

const BASE_URL = process.env.BASE_URL || 'http://localhost:8015';

function sseEvent(event: string, data: unknown): string {
  return `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`;
}

async function openChatWithSse(page: Page, body: string): Promise<void> {
  await page.route('**/api/v2/chat/completions', async (route: Route) => {
    await route.fulfill({
      status: 200,
      contentType: 'text/event-stream',
      body,
    });
  });
  await page.goto(`${BASE_URL}/chat`);
}

async function submitMessage(page: Page, text = 'نمایش را بررسی کن') {
  await page.getByRole('textbox', { name: 'پیام خود را بنویسید...' }).fill(text);
  await page.getByRole('button', { name: 'ارسال' }).click();
}

test.describe('reasoning hardening', () => {
  test('status event with reasoning_details is rejected by the client guard', async ({ page }) => {
    const warnings: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'warning') {
        warnings.push(msg.text());
      }
    });

    const body = [
      sseEvent('status', {
        step: 'reviewing',
        message: 'در حال بررسی...',
        reasoning_details: ['secret'],
      }),
      sseEvent('assistant_message', {
        content: 'پاسخ نهایی',
        content_format: 'markdown',
      }),
    ].join('');

    await openChatWithSse(page, body);
    await submitMessage(page);

    const assistantBubble = page.locator('.msg.assistant .bubble');
    await expect(assistantBubble).toBeVisible();
    await expect(assistantBubble).toContainText('پاسخ نهایی');

    const steps = page.locator('.progress-steps .progress-step');
    await expect(steps).toHaveCount(2);
    await expect(page.locator('.progress-steps')).not.toContainText('در حال بررسی...');
    await expect(page.locator('.progress-steps')).not.toContainText('reviewing');

    expect(warnings.join('\n')).toContain('Refusing to render reasoning field in status event');
  });

  test('tool_execution_start event carries only canonical fields (no args leak)', async ({ page }) => {
    const body = [
      sseEvent('tool_execution_start', {
        tool: 'candidates',
        tool_label: 'جست‌وجوی پروفایل‌های ذخیره‌شده...',
        id: 'call-1',
        args: { job_requirements: [{ skill: 'React' }] },
      }),
      sseEvent('tool_execution_end', {
        tool: 'candidates',
        tool_label: 'جست‌وجوی پروفایل‌های ذخیره‌شده...',
        result: {
          matched_count: 0,
          search_stats: {
            searched_profiles: 0,
            matched_profiles: 0,
            source_breakdown: {},
          },
        },
        id: 'call-1',
      }),
      sseEvent('assistant_message', {
        content: 'هیچ نتیجه‌ای یافت نشد.',
        content_format: 'markdown',
      }),
    ].join('');

    await openChatWithSse(page, body);
    await submitMessage(page);

    await expect(page.locator('.msg.assistant .bubble')).toBeVisible();
    await page.locator('.widget-header').click();

    const steps = page.locator('.progress-steps');
    await expect(steps).toContainText('جست‌وجوی پروفایل‌های ذخیره‌شده...');
    await expect(steps).not.toContainText('args');
    await expect(steps).not.toContainText('job_requirements');
    await expect(steps).not.toContainText('React');

    const detail = page.locator('.progress-step-detail');
    await expect(detail).toContainText('Tool Output');
    await expect(detail).toContainText('آمار جستجو');
  });

  test('assistant_message with reasoning fields throws', async ({ page }) => {
    const body = sseEvent('assistant_message', {
      content: 'پاسخ نهایی',
      content_format: 'markdown',
      reasoning_content: 'این یک روند فکری است.',
    });

    await openChatWithSse(page, body);
    await submitMessage(page);

    await expect(page.locator('.msg.error')).toBeVisible();
    await expect(page.locator('.msg.error')).toContainText('پاسخ حاوی اطلاعات داخلی غیرمجاز است.');
    await expect(page.locator('.msg.assistant')).toHaveCount(0);
  });

  test('tool_execution_end result is rendered in collapsible detail section', async ({ page }) => {
    const body = [
      sseEvent('tool_execution_start', {
        tool: 'candidates',
        tool_label: 'جست‌وجوی پروفایل‌های ذخیره‌شده...',
        id: 'call-1',
      }),
      sseEvent('tool_execution_end', {
        tool: 'candidates',
        tool_label: 'جست‌وجوی پروفایل‌های ذخیره‌شده...',
        result: {
          matched_count: 0,
          search_stats: {
            searched_profiles: 0,
            matched_profiles: 0,
            source_breakdown: {},
          },
        },
        id: 'call-1',
      }),
      sseEvent('assistant_message', {
        content: 'هیچ نتیجه‌ای یافت نشد.',
        content_format: 'markdown',
      }),
    ].join('');

    await openChatWithSse(page, body);
    await submitMessage(page);

    await expect(page.locator('.msg.assistant .bubble')).toBeVisible();
    await page.locator('.widget-header').click();

    const detail = page.locator('.progress-step-detail');
    await expect(detail).toBeVisible();
    await expect(detail).toContainText('Tool Output');
    await expect(detail).toContainText('آمار جستجو');
  });
});
