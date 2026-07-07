import type { Page } from '@playwright/test';

export async function fillCloseShiftForm(
  page: Page,
  values: { cash?: string; fundAfter?: string } = {},
): Promise<void> {
  await page.locator('#shift_cash').fill(values.cash ?? '0');
  await page.locator('#shift_fund_after').fill(values.fundAfter ?? '0');
}
