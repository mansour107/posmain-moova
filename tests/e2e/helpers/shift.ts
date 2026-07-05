import type { Page } from '@playwright/test';

export async function fillCloseShiftForm(
  page: Page,
  values: { cash?: string; fundAfter?: string; expenses?: string } = {},
): Promise<void> {
  await page.locator('#shift_cash').fill(values.cash ?? '0');
  await page.locator('#shift_fund_after').fill(values.fundAfter ?? '0');

  const expenses = page.locator('#shift_expenses');
  const isReadonly = await expenses.evaluate((el) => (el as HTMLInputElement).readOnly);
  if (!isReadonly) {
    await expenses.fill(values.expenses ?? '0');
  }
}
