import { expect, type Page } from '@playwright/test';
import { personaCredentials, type PersonaRole } from './env';

export async function loginAs(page: Page, role: PersonaRole): Promise<void> {
  const { username, password } = personaCredentials(role);

  await page.goto('/index.php');
  await page.locator('#uname').fill(username);
  await page.locator('#password').fill(password);
  await page.getByRole('button', { name: /تسجيل الدخول/ }).click();

  await page.waitForURL(/dashboard\.php/, { timeout: 20_000 });
}

export async function unlockPos(page: Page, role: PersonaRole): Promise<void> {
  const { password } = personaCredentials(role);
  const pinByRole: Record<PersonaRole, string> = {
    admin: process.env.POSMAIN_TEST_PIN_ADMIN || '2468',
    manager: process.env.POSMAIN_TEST_PIN_MANAGER || '1357',
    cashier: process.env.POSMAIN_TEST_PIN_CASHIER || '9753',
    waiter: process.env.POSMAIN_TEST_PIN_WAITER || '8642',
    kitchen: process.env.POSMAIN_TEST_PIN_KITCHEN || '7531',
  };
  const testPin = pinByRole[role];

  await page.goto('/pos_barcode.php');

  const pinPad = page.locator('#pinPadSection');
  if (await pinPad.isVisible().catch(() => false)) {
    for (const digit of testPin.split('')) {
      await page.locator(`#pinGrid [data-key="${digit}"]`).click();
    }
    await Promise.all([
      page.waitForURL(/pos_barcode\.php/, { timeout: 20_000 }),
      page.locator('#pinGrid [data-key="دخول"]').click(),
    ]);
    await page.waitForLoadState('networkidle');
  } else {
    const posCodeInput = page.locator('input[name="pos_barcode"]');
    if (await posCodeInput.isVisible().catch(() => false)) {
      await posCodeInput.fill(password);
      await page.getByRole('button', { name: /دخول/ }).click();
      await page.waitForLoadState('networkidle');
    }
  }

  await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
}

export async function loginAndUnlockPos(page: Page, role: PersonaRole): Promise<void> {
  await loginAs(page, role);
  await unlockPos(page, role);
}

export async function isPosPinPadMode(page: Page, role: PersonaRole = 'cashier'): Promise<boolean> {
  await loginAs(page, role);
  await page.goto('/pos_barcode.php', { waitUntil: 'domcontentloaded' });
  return page.locator('#pinPadSection').isVisible().catch(() => false);
}

export function assertNoFatalText(body: string): void {
  expect(body).not.toMatch(/fatal error|SQL syntax|mysqli_|uncaught exception/i);
}
