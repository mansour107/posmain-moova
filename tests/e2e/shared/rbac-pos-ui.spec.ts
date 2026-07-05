import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

test('cashier shift close control hidden without capability', async ({ page }) => {
  await loginAs(page, 'cashier');
  await page.goto('/pos_barcode.php', { waitUntil: 'domcontentloaded' });

  const closeShift = page.locator(
    '[data-capability="pos.shift.close"], #closeShiftBtn, .close-shift-btn, [data-bs-target="#closeShiftModal"]',
  );
  const count = await closeShift.count();
  // Cashier without pos.shift.close: control is not rendered (preferred) or hidden.
  if (count === 0) {
    return;
  }

  for (let i = 0; i < count; i += 1) {
    await expect(closeShift.nth(i)).toBeHidden();
  }
});

test('admin session status includes POS capabilities', async ({ page }) => {
  await loginAs(page, 'admin');
  const response = await page.request.get('/pos_session_status.php');
  expect(response.ok()).toBeTruthy();
  const payload = await response.json();
  expect(payload.capabilities).toBeTruthy();
  expect(typeof payload.capabilities['pos.shift.close']).toBe('boolean');
});
