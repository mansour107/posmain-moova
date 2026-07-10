import { expect, type Page } from '@playwright/test';
import { personaCredentials, type PersonaRole } from './env';
import { fillOpenShiftCount } from './shift';

const personaPins: Record<PersonaRole, string> = {
  admin: process.env.POSMAIN_TEST_PIN_ADMIN || '2468',
  manager: process.env.POSMAIN_TEST_PIN_MANAGER || '1357',
  cashier: process.env.POSMAIN_TEST_PIN_CASHIER || '9753',
  waiter: process.env.POSMAIN_TEST_PIN_WAITER || '8642',
  kitchen: process.env.POSMAIN_TEST_PIN_KITCHEN || '7531',
};

const postLoginByRole: Record<PersonaRole, RegExp> = {
  admin: /dashboard\.php/,
  manager: /dashboard\.php/,
  cashier: /pos_barcode\.php|register_pair\.php/,
  waiter: /pos_barcode\.php|pos_tables\.php|workspace\.php|register_pair\.php/,
  kitchen: /kds\.php|workspace\.php|no_access\.php/,
};

/** Navigate after login without waiting for networkidle (dashboard keeps polling). */
export async function gotoAfterLogin(page: Page, path: string): Promise<void> {
  await page.goto(path, { waitUntil: 'commit', timeout: 30_000 });
  await page.waitForLoadState('domcontentloaded');
}

export async function logoutIfNeeded(page: Page): Promise<void> {
  // Clear any existing ERP/POS session so the next loginAs can reach the login UI.
  await page.goto('/do/do_logout.php', { waitUntil: 'commit', timeout: 15_000 }).catch(() => undefined);
  await page.context().clearCookies();
}

async function enterMainPin(page: Page, pin: string): Promise<void> {
  const grid = page.locator('#mainPinPadGrid, .ppm-grid').first();
  await expect(grid).toBeVisible({ timeout: 20_000 });
  for (const digit of pin.split('')) {
    // 4th digit auto-submits and navigates; noWaitAfter avoids click() waiting on that nav.
    await grid.locator(`[data-key="${digit}"]`).click({ noWaitAfter: true });
  }
}

export async function loginAs(page: Page, role: PersonaRole): Promise<void> {
  const { username, password } = personaCredentials(role);
  const pin = personaPins[role];

  await logoutIfNeeded(page);
  await page.goto('/index.php', { waitUntil: 'domcontentloaded' });

  const passwordUser = page.locator('#uname');
  const mainPinGrid = page.locator('#mainPinPadGrid, .ppm-grid').first();

  if (await mainPinGrid.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await enterMainPin(page, pin);
    // commit: dashboard/register pages may never reach full "load" while assets/XHR run.
    await page.waitForURL(postLoginByRole[role], { timeout: 20_000, waitUntil: 'commit' });
    return;
  }

  await expect(passwordUser).toBeVisible({ timeout: 20_000 });
  await passwordUser.fill(username);
  await page.locator('#password').fill(password);
  await page.getByRole('button', { name: /تسجيل الدخول/ }).click();
  await page.waitForURL(postLoginByRole[role], { timeout: 20_000, waitUntil: 'commit' });
}

async function completeRegisterPairing(page: Page): Promise<void> {
  for (let attempt = 0; attempt < 3 && page.url().includes('register_pair.php'); attempt++) {
    const createFirst = page.getByRole('button', { name: /إنشاء وربط/ });
    if (await createFirst.isVisible().catch(() => false)) {
      await createFirst.click();
      await page.waitForURL(/pos_barcode\.php/, { timeout: 15_000, waitUntil: 'commit' });
      continue;
    }

    const showPin = page.locator('#showPinBtn');
    if (await showPin.isVisible().catch(() => false)) {
      await showPin.click();
    }
    const pinInput = page.locator('#manager_pin');
    if (!(await pinInput.isVisible().catch(() => false))) {
      break;
    }
    // Owner/admin PIN satisfies users.manage for re-pair approval.
    await pinInput.fill(personaPins.admin);
    await page.getByRole('button', { name: /تأكيد الربط/ }).click();
    await page.waitForURL(/pos_barcode\.php/, { timeout: 15_000, waitUntil: 'commit' });
  }
  if (page.url().includes('register_pair.php')) {
    throw new Error('register pairing did not complete: ' + (await page.locator('.error-box, .pair-sub').first().textContent().catch(() => page.url())));
  }
}

export async function unlockPos(page: Page, role: PersonaRole): Promise<void> {
  const { password } = personaCredentials(role);
  const testPin = personaPins[role];

  await gotoAfterLogin(page, '/pos_barcode.php');
  await completeRegisterPairing(page);

  const closeResultModal = page.locator('#shiftCloseResultModal');
  if (await closeResultModal.isVisible({ timeout: 1_500 }).catch(() => false)) {
    await page.locator('#shiftCloseResultDismiss').click();
    await expect(closeResultModal).toBeHidden({ timeout: 5_000 });
  }

  // Local main-PIN mode skips the second unlock; recovery overlay may still block selling.
  const recovery = page.locator('#posShiftRecoveryOverlay');
  if (await recovery.isVisible({ timeout: 1_000 }).catch(() => false)) {
    return;
  }

  const pinPad = page.locator('#pinPadSection');
  if (await pinPad.isVisible().catch(() => false)) {
    for (const digit of testPin.split('')) {
      await page.locator(`#pinGrid [data-key="${digit}"]`).click({ noWaitAfter: true });
    }
    const enter = page.locator('#pinGrid [data-key="دخول"]');
    if (await enter.isVisible().catch(() => false)) {
      await enter.click({ noWaitAfter: true }).catch(() => undefined);
    }
    await page.waitForLoadState('domcontentloaded');
  } else {
    const posCodeInput = page.locator('input[name="pos_barcode"]');
    if (await posCodeInput.isVisible().catch(() => false)) {
      await posCodeInput.fill(password);
      await page.getByRole('button', { name: /دخول/ }).click();
      await page.waitForLoadState('domcontentloaded');
    }
  }

  // Handover mode shows a blocking open-count overlay after unlock; finish it
  // before asserting the sellable POS surface (both can be in the DOM at once).
  const openOverlay = page.locator('#pshOpenOverlay');
  if (await openOverlay.isVisible({ timeout: 2_000 }).catch(() => false)) {
    if (
      await page
        .locator(
          '#pshOpenBranchBlocked, #pshOpenBaselineRequired, #pshOpenPermissionDenied, [data-testid="psh-open-permission-denied"]',
        )
        .first()
        .isVisible()
        .catch(() => false)
    ) {
      return;
    }
    if ((await openOverlay.getAttribute('data-psh-open-denied')) === '1') {
      return;
    }
    await fillOpenShiftCount(page, process.env.POSMAIN_TEST_OPENING_CASH || '100.00');
  }

  await expect(page.locator('#posForm').or(page.locator('#posShiftRecoveryOverlay'))).toBeVisible({
    timeout: 20_000,
  });
}

export async function loginAndUnlockPos(page: Page, role: PersonaRole): Promise<void> {
  await loginAs(page, role);
  await unlockPos(page, role);
}

export async function enterManagerOverridePin(page: Page, role: PersonaRole = 'manager'): Promise<void> {
  const modal = page.locator('#posPinPadModal');
  await expect(modal).toBeVisible({ timeout: 15_000 });
  for (const digit of personaPins[role].split('')) {
    await modal.locator(`[data-key="${digit}"]`).click();
  }
  await modal.locator('[data-key="دخول"]').click();
  await expect(modal).toBeHidden({ timeout: 15_000 });
}

export async function submitManagerOverridePinAttempt(page: Page, pin: string): Promise<void> {
  const modal = page.locator('#posPinPadModal');
  await expect(modal).toBeVisible({ timeout: 15_000 });
  for (const digit of pin.split('')) {
    await modal.locator(`[data-key="${digit}"]`).click();
  }
  await modal.locator('[data-key="دخول"]').click();
}

export async function isPosPinPadMode(page: Page, role: PersonaRole = 'cashier'): Promise<boolean> {
  await loginAs(page, role);
  await gotoAfterLogin(page, '/pos_barcode.php');
  return page.locator('#pinPadSection').isVisible().catch(() => false);
}

export function assertNoFatalText(body: string): void {
  expect(body).not.toMatch(/fatal error|SQL syntax|mysqli_|uncaught exception/i);
}
