import { test, expect } from '@playwright/test';
import { loginAndUnlockPos, unlockPos } from '../helpers/auth';
import { fillCloseShiftForm } from '../helpers/shift';

async function openCloseShiftModal(page: import('@playwright/test').Page): Promise<void> {
  const shiftButton = page.locator('[data-bs-target="#closeShiftModal"], button[title="إغلاق الشيفت"]').first();
  await expect(shiftButton).toBeVisible({ timeout: 15_000 });
  await shiftButton.click();
  await expect(page.locator('#closeShiftModal')).toBeVisible({ timeout: 10_000 });
}

async function readShiftPreviewOrders(page: import('@playwright/test').Page): Promise<number> {
  const response = await page.request.get('/do/get_shift_preview.php');
  expect(response.ok()).toBeTruthy();
  const body = await response.json();
  expect(body.success).toBe(true);
  return Number(body.data?.total_orders ?? 0);
}

test.describe('manager: real shift window lifecycle', () => {
  test('second shift after close excludes prior shift sales from preview', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    const ordersBeforeFirstClose = await readShiftPreviewOrders(page);

    await openCloseShiftModal(page);
    await fillCloseShiftForm(page);
    await page.waitForURL(/pos_barcode\.php/, { timeout: 20_000 });
    await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 10_000 });
    await page.locator('#shiftCloseResultDismiss').click();

    await unlockPos(page, 'manager');

    const ordersAfterReopen = await readShiftPreviewOrders(page);
    expect(ordersAfterReopen).toBe(0);

    await openCloseShiftModal(page);
    await expect(page.locator('#closeShiftModal')).toContainText(/0|لا توجد/i);
    await fillCloseShiftForm(page);
    await page.waitForURL(/pos_barcode\.php/, { timeout: 20_000 });
    await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 10_000 });

    await expect(page.getByText(/لا توجد مبيعات|تم إغلاق الشيفت/i)).toBeVisible({ timeout: 10_000 });

    // Sanity: first close could have had orders from the active test shift only.
    expect(ordersBeforeFirstClose).toBeGreaterThanOrEqual(0);
  });

  test('session status reports shift_open across lifecycle', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    let status = await page.evaluate(async () => {
      const r = await fetch('pos_session_status.php', { cache: 'no-store' });
      return r.json();
    });
    expect(status.authenticated).toBe(true);
    expect(status.shift_open).toBe(true);

    await openCloseShiftModal(page);
    await fillCloseShiftForm(page);
    await page.waitForURL(/pos_barcode\.php/, { timeout: 20_000 });
    await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 10_000 });
    await page.locator('#shiftCloseResultDismiss').click();

    status = await page.evaluate(async () => {
      const r = await fetch('pos_session_status.php', { cache: 'no-store' });
      return r.json();
    });
    expect(status.authenticated).toBe(false);
    expect(status.shift_open).toBe(false);

    await unlockPos(page, 'manager');
    status = await page.evaluate(async () => {
      const r = await fetch('pos_session_status.php', { cache: 'no-store' });
      return r.json();
    });
    expect(status.authenticated).toBe(true);
    expect(status.shift_open).toBe(true);
    expect(status.shift_opened_at).toBeTruthy();
  });
});
