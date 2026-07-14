import { test, expect } from '@playwright/test';
import { startFreshHandoverShift } from '../helpers/handover';

test('customer field placeholder is readable', async ({ page }) => {
  await startFreshHandoverShift(page, 'cashier');
  const input = page.locator('#posCustomerPhoneInput');
  await expect(input).toBeVisible({ timeout: 20_000 });
  await expect(input).toHaveAttribute('placeholder', /ابحث عن عميل برقم الهاتف/);

  const color = await input.evaluate((el) => getComputedStyle(el, '::placeholder').color);
  console.log('placeholderColor=', color);
  const m = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
  expect(m, `unexpected placeholder color: ${color}`).toBeTruthy();
  const r = Number(m![1]);
  const g = Number(m![2]);
  const b = Number(m![3]);
  const avg = (r + g + b) / 3;
  expect(avg, `placeholder too dark: ${color}`).toBeGreaterThan(120);

  await page.locator('.pos-current-order-controls').screenshot({
    path: 'test-results/pos-customer-field-readable.png',
  });
});
