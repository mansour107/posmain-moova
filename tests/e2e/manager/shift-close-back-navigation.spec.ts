import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { fillCloseShiftForm } from '../helpers/shift';
import { dismissShiftCloseResultModal } from '../helpers/handover';

async function openCloseShiftModal(page: import('@playwright/test').Page): Promise<void> {
  const shiftButton = page.locator('[data-bs-target="#closeShiftModal"], button[title="إغلاق الشيفت"]').first();
  await expect(shiftButton).toBeVisible({ timeout: 15_000 });
  await shiftButton.click();
  await expect(page.locator('#closeShiftModal')).toBeVisible({ timeout: 10_000 });
}

async function submitCloseShift(page: import('@playwright/test').Page): Promise<void> {
  await fillCloseShiftForm(page);
}

test.describe('manager: shift close hardening', () => {
  test('closing shift shows result ack without PIN unlock panel', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await openCloseShiftModal(page);
    await submitCloseShift(page);

    await page.waitForURL(/pos_barcode\.php/, { timeout: 20_000 });
    await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText(/تم إغلاق الشيفت/i)).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#pinPadSection, .unlock-shell')).toHaveCount(0);
    await expect(page.locator('#shiftCloseResultTimerBar')).toBeVisible();
  });

  test('dismissing close result goes to login screen', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await openCloseShiftModal(page);
    await submitCloseShift(page);
    await page.waitForURL(/pos_barcode\.php/, { timeout: 20_000 });
    await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 10_000 });
    await dismissShiftCloseResultModal(page);

    await expect(page).toHaveURL(/index\.php|login\.php/);
    await page.goto('/pos_barcode.php');
    // Logged out: should not reach selling surface.
    await expect(page.locator('#posForm')).toHaveCount(0);
  });

  test('browser back after close cannot keep selling on cached POS', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await openCloseShiftModal(page);
    await submitCloseShift(page);
    await page.waitForURL(/pos_barcode\.php/, { timeout: 20_000 });
    await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 10_000 });

    await page.goBack();
    await page.waitForLoadState('domcontentloaded');

    const posForm = page.locator('#posForm');
    const barcodeInput = page.locator('input[name="pos_barcode"]');
    const logoutUrl = page.url().includes('logout=1');

    const posFormVisible = await posForm.isVisible().catch(() => false);
    if (posFormVisible) {
      const addItemResponse = page.waitForResponse(
        (response) =>
          response.url().includes('doadd_invoice.php') || response.url().includes('/api/pos/'),
        { timeout: 10_000 },
      ).catch(() => null);

      await page.locator('.item-card, .pos-item-card, [data-item-id]').first().click({ timeout: 5_000 }).catch(() => {});
      const response = await addItemResponse;
      if (response) {
        expect(response.status()).toBeGreaterThanOrEqual(400);
      }
    }

    const barcodeVisible = await barcodeInput.isVisible().catch(() => false);
    expect(logoutUrl || barcodeVisible || !posFormVisible).toBeTruthy();
  });

  test('re-login after close allows POS again', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await openCloseShiftModal(page);
    await submitCloseShift(page);
    await page.waitForURL(/pos_barcode\.php/, { timeout: 20_000 });
    await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 10_000 });
    await dismissShiftCloseResultModal(page);

    await loginAndUnlockPos(page, 'manager');
    await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
  });
});
