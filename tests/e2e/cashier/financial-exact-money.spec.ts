import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { prepareCleanShift } from '../helpers/handover';
import { clickFirstAddableItem, payCashInModal, readCartNet } from '../helpers/pos';

/**
 * Browser e2e for the always-on exact-money path:
 * unlock (opens drawer) → takeaway cash pay → receipt → legacy journal writer blocked.
 */
test.describe('financial: exact-money browser flow', () => {
  test.beforeEach(() => {
    prepareCleanShift();
  });

  test('cashier cash takeaway posts receipt without fatal errors', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await clickFirstAddableItem(page);

    const net = await readCartNet(page);
    expect(net).toBeGreaterThan(0);
    // Posted money must be 2dp; cart net display should parse cleanly.
    expect(Number(net.toFixed(2))).toBe(net);

    const paymentResponse = page.waitForResponse((response) =>
      response.url().includes('api/pos/index.php')
      && response.request().method() === 'POST',
    );

    await Promise.all([
      page.waitForURL(/print\/(?:receipt|index)\.php/, { timeout: 30_000 }),
      payCashInModal(page, net),
    ]);

    const paymentBody = await (await paymentResponse).json();
    expect(paymentBody.success, JSON.stringify(paymentBody)).toBeTruthy();
    expect(Number(paymentBody.order_id || paymentBody.data?.order_id || 0)).toBeGreaterThan(0);

    const receipt = await page.content();
    expect(receipt).not.toMatch(/fatal error|SQL syntax|LEGACY_FINANCIAL_WRITER|DRAWER_SESSION_REQUIRED/i);
  });

  test('legacy journal writer endpoint is permanently disabled', async ({ page }) => {
    await loginAndUnlockPos(page, 'admin');
    const response = await page.request.post('/do/doadd_journal.php', {
      form: { json: '1', amount: '10.00' },
      headers: { Accept: 'application/json' },
    });
    expect([403, 410]).toContain(response.status());
    if (response.status() === 410) {
      const body = await response.json();
      expect(body.code).toBe('LEGACY_FINANCIAL_WRITER_FORBIDDEN');
    }
  });

  test('admin full refund of paid takeaway uses credit-note path', async ({ page }) => {
    await loginAndUnlockPos(page, 'admin');
    await clickFirstAddableItem(page);
    const net = await readCartNet(page);
    expect(net).toBeGreaterThan(0);

    const paymentResponse = page.waitForResponse((response) =>
      response.url().includes('api/pos/index.php')
      && response.request().method() === 'POST',
    );
    await Promise.all([
      page.waitForURL(/print\/(?:receipt|index)\.php/, { timeout: 30_000 }),
      payCashInModal(page, net),
    ]);
    const paymentBody = await (await paymentResponse).json();
    const orderId = Number(paymentBody.order_id || paymentBody.data?.order_id || 0);
    expect(orderId).toBeGreaterThan(0);

    await page.goto('/pos_barcode.php');
    await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });

    const refund = await page.evaluate(async (id) => {
      const tokenEl = document.querySelector('meta[name="posmain-csrf-token"]');
      const csrf = tokenEl?.getAttribute('content') || '';
      const body = new URLSearchParams({
        order_id: String(id),
        action: 'refund',
        refund_stock_policy: 'waste',
        refund_payment_method: 'cash',
        reason: 'e2e financial credit-note refund',
        idempotency_key: `e2e-fin-refund-${id}-${Date.now()}`,
      });
      const response = await fetch('/ajax/refund_order.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrf,
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
        body: body.toString(),
      });
      return { status: response.status, body: await response.json().catch(() => null) };
    }, orderId);

    expect(refund.status, JSON.stringify(refund.body)).toBeLessThan(500);
    expect(refund.body?.success, JSON.stringify(refund.body)).toBeTruthy();
    expect(
      String(refund.body?.data?.payment_status || refund.body?.payment_status || ''),
    ).toMatch(/refunded/i);
  });
});
