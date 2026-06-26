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

  await page.goto('/pos_barcode.php');
  const posCodeInput = page.locator('input[name="pos_barcode"]');
  if (await posCodeInput.isVisible().catch(() => false)) {
    await posCodeInput.fill(password);
    await page.getByRole('button', { name: /دخول/ }).click();
    await page.waitForLoadState('networkidle');
  }

  await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
}

export async function loginAndUnlockPos(page: Page, role: PersonaRole): Promise<void> {
  await loginAs(page, role);
  await unlockPos(page, role);
}

export function assertNoFatalText(body: string): void {
  expect(body).not.toMatch(/fatal error|SQL syntax|mysqli_|uncaught exception/i);
}
