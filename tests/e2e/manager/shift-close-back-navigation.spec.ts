import { test, expect } from '@playwright/test';
import { loginAndUnlockPos, unlockPos } from '../helpers/auth';
import { fillCloseShiftForm } from '../helpers/shift';

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
  test('closing shift stays on POS unlock with result modal', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await openCloseShiftModal(page);
    await submitCloseShift(page);

    await page.waitForURL(/pos_barcode\.php/, { timeout: 20_000 });
    await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText(/تم إغلاق الشيفت/i)).toBeVisible({ timeout: 10_000 });
  });

  test('fresh POS load after close shows barcode lock screen', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await openCloseShiftModal(page);
    await submitCloseShift(page);
    await page.waitForURL(/pos_barcode\.php/, { timeout: 20_000 });
    await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 10_000 });
    await page.locator('#shiftCloseResultDismiss').click();

    await page.goto('/pos_barcode.php');
    await expect(page.locator('input[name="pos_barcode"], .pin-key').first()).toBeVisible({ timeout: 15_000 });
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

  test('re-unlock after close allows POS again', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await openCloseShiftModal(page);
    await submitCloseShift(page);
    await page.waitForURL(/pos_barcode\.php/, { timeout: 20_000 });
    await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 10_000 });
    await page.locator('#shiftCloseResultDismiss').click();

    await unlockPos(page, 'manager');
    await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
  });
});
