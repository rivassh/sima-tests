import { test, expect, request } from '@playwright/test';

const BASE_URL = process.env.BASE_URL || 'http://localhost:8015';
const CHAT_ENDPOINT = `${BASE_URL}/api/v1/chat/query`;

async function sendChat(messages: Array<{ role: string; content: string }>) {
  const ctx = await request.newContext();
  try {
    const r = await ctx.post(`${CHAT_ENDPOINT}`, {
      data: {
        model: 'youse',
        messages,
        collection_id: 'jobvision-chat',
        top_k: 5,
        temperature: 0.3,
        max_tokens: 1000,
      },
      waitUntil: 'networkidle',
    });
    const text = await r.text();
    console.log('Response status:', r.status());
    console.log('Response body (first 500 chars):', text.substring(0, 500));
    return { ok: r.ok(), status: r.status(), body: text };
  } finally {
    await ctx.dispose();
  }
}

async function getCollections() {
  const ctx = await request.newContext();
  try {
    const r = await ctx.get(`${BASE_URL}/api/v1/chat/collections`);
    const data = await r.json();
    return { ok: r.ok(), status: r.status(), data };
  } finally {
    await ctx.dispose();
  }
}

test.describe('0.req — AI Talent Analysis (Naveed Sharifi)', () => {
  test('chat endpoint responds for JobVision query about available jobs', async () => {
    const result = await sendChat([
      { role: 'system', content: 'You are a helpful assistant for the JobVision system.' },
      { role: 'user', content: 'چه شغل‌های فعالی داریم؟' },
    ]);
    expect(result.ok).toBe(true);
  });

  test('chat endpoint responds for skill-related query', async () => {
    const result = await sendChat([
      { role: 'system', content: 'You are a helpful assistant for the JobVision system.' },
      { role: 'user', content: 'برای برنامه‌نویس لاراول چه مهارت‌های لازم است؟' },
    ]);
    expect(result.ok).toBe(true);
    expect(result.body.length).toBeGreaterThan(0);
  });

  test('chat endpoint responds for candidate filtering query', async () => {
    const result = await sendChat([
      { role: 'system', content: 'You are a helpful assistant for the JobVision system.' },
      { role: 'user', content: 'کدام شهرها بیشترین آگهی شغلی دارند؟' },
    ]);
    expect(result.ok).toBe(true);
    expect(result.body.length).toBeGreaterThan(0);
  });

  test('partial NLP extraction — resume skills', async () => {
    const result = await sendChat([
      { role: 'system', content: 'You are a helpful assistant for the JobVision system.' },
      { role: 'user', content: 'متن رزومه: SOLIDWORKS، PDMS، CAESAR، Python، SQL و PHP' },
    ]);
    expect(result.ok).toBe(true);
    expect(result.body.length).toBeGreaterThan(0);
  });

  test('full pipeline — job post collection response', async () => {
    const result = await sendChat([
      { role: 'system', content: 'You are a helpful assistant for the JobVision system.' },
      { role: 'user', content: 'لطفاً فهرست آگهی‌های شغلی اخیر را برای موقعیت‌های مهندسی بررسی کنید.' },
    ]);
    expect(result.ok).toBe(true);
    expect(result.body.length).toBeGreaterThan(0);
  });

  test('collection endpoint lists available collections', async () => {
    const result = await getCollections();
    expect(result.ok).toBe(true);
    expect(Array.isArray(result.data?.data || [])).toBe(true);
  });
});