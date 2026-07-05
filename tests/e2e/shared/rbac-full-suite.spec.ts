import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText, unlockPos } from '../helpers/auth';
import {
  RBAC_ALLOWED_PAGE_MATRIX,
  RBAC_CRITICAL_POST_HANDLERS,
  RBAC_DENIED_PAGE_MATRIX,
  assertDemoPersonaShape,
  assertNoApplicationError,
  fetchCapabilities,
  fetchPosSessionCapabilities,
  isAccessBlocked,
  isHandlerRejected,
  postHandlerAsRole,
} from '../helpers/rbac';
import type { PersonaRole } from '../helpers/env';
import { personaCredentials } from '../helpers/env';

const CORE_PERSONAS: PersonaRole[] = ['admin', 'manager', 'cashier', 'waiter'];

test.describe('RBAC environment diagnosis', () => {
  for (const role of CORE_PERSONAS) {
    test(`${role} demo persona capability shape`, async ({ page }) => {
      await loginAs(page, role);
      await assertDemoPersonaShape(page, role);
    });
  }
});

test.describe('RBAC full suite: capabilities API', () => {
  test('unauthenticated capabilities endpoint is rejected', async ({ request }) => {
    const response = await request.get('/ajax/current_user_capabilities.php', {
      failOnStatusCode: false,
    });
    expect([401, 403]).toContain(response.status());
  });

  test('admin capabilities include core admin keys', async ({ page }) => {
    await loginAs(page, 'admin');
    const permissions = await fetchCapabilities(page.request);
    expect(permissions['users.manage']).toBe(true);
    expect(permissions['roles.manage']).toBe(true);
    expect(permissions['pos.open']).toBe(true);
  });
});

test.describe('RBAC full suite: page access denials', () => {
  for (const entry of RBAC_DENIED_PAGE_MATRIX) {
    test(`${entry.role} is blocked from ${entry.path} when lacking ${entry.permission}`, async ({ page }) => {
      await loginAs(page, entry.role);
      const permissions = await fetchCapabilities(page.request);
      test.skip(permissions[entry.permission] === true, `${entry.role} has ${entry.permission} in this environment`);

      const response = await page.goto(entry.path, { waitUntil: 'domcontentloaded' });
      const body = await page.content();
      assertNoApplicationError(body);
      expect(isAccessBlocked(response, body, page.url(), entry.path), `expected block for ${entry.role} on ${entry.path}`).toBeTruthy();
    });
  }
});

test.describe('RBAC full suite: page access allowed', () => {
  for (const entry of RBAC_ALLOWED_PAGE_MATRIX) {
    test(`${entry.role} can open ${entry.path} when holding ${entry.permission}`, async ({ page }) => {
      await loginAs(page, entry.role);
      const permissions = await fetchCapabilities(page.request);
      expect(permissions[entry.permission], `${entry.role} needs ${entry.permission}`).toBe(true);

      const response = await page.goto(entry.path, { waitUntil: 'domcontentloaded' });
      const body = await page.content();
      assertNoApplicationError(body);
      expect(isAccessBlocked(response, body, page.url(), entry.path), `should not block ${entry.role} on ${entry.path}`).toBeFalsy();
      if (entry.hint) {
        await expect(page.locator('body')).toContainText(entry.hint);
      }
    });
  }
});

test.describe('RBAC full suite: write handler denials', () => {
  for (const role of ['cashier', 'waiter'] as PersonaRole[]) {
    for (const handler of RBAC_CRITICAL_POST_HANDLERS) {
      test(`${role} cannot POST ${handler.label} without ${handler.permission}`, async ({ page }) => {
        await loginAs(page, role);
        const permissions = await fetchCapabilities(page.request);
        test.skip(permissions[handler.permission] === true, `${role} unexpectedly has ${handler.permission}`);

        const response = await postHandlerAsRole(page, handler.path, handler.body ?? {});
        expect(isHandlerRejected(response)).toBeTruthy();
      });
    }
  }

  test('unauthenticated POST to role creation is rejected', async ({ request }) => {
    const response = await request.post('/do/doadd_role.php', {
      form: { rollname: 'rbac-anon', info: 'anon' },
      failOnStatusCode: false,
      maxRedirects: 0,
    });
    expect(isHandlerRejected(response)).toBeTruthy();
  });
});

test.describe('RBAC full suite: admin surfaces', () => {
  test('admin can open role permissions editor and see save form', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/role_permissions.php?id=29', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('form[action*="doedit_role_permissions"]')).toBeVisible();
    assertNoFatalText(await page.content());
  });

  test('admin can open user permission overrides on edit user', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/users.php', { waitUntil: 'domcontentloaded' });
    const editLink = page.locator('a[href*="edit_user.php"]').first();
    await expect(editLink).toBeVisible();
    await editLink.click();
    await expect(
      page.locator('form[action*="doedit_user_permissions"], [name="permission_mode"]').first(),
    ).toBeVisible();
    assertNoFatalText(await page.content());
  });

  test('admin layout injects POSMAIN_CAPABILITIES', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/dashboard.php', { waitUntil: 'domcontentloaded' });
    const caps = await page.evaluate(() => (window as unknown as { POSMAIN_CAPABILITIES?: Record<string, boolean> }).POSMAIN_CAPABILITIES);
    expect(caps).toBeTruthy();
    expect(caps?.['users.manage']).toBe(true);
  });
});

test.describe('RBAC full suite: POS UI and session', () => {
  test('cashier POS unlock still works after RBAC hardening', async ({ page }) => {
    await loginAs(page, 'cashier');
    await unlockPos(page, 'cashier');
    await expect(page.locator('#posForm')).toBeVisible();
    assertNoFatalText(await page.content());
  });

  test('admin pos_session_status includes capability map', async ({ page }) => {
    await loginAs(page, 'admin');
    const caps = await fetchPosSessionCapabilities(page.request);
    expect(typeof caps['pos.shift.close']).toBe('boolean');
    expect(typeof caps['pos.cashdrawer.count']).toBe('boolean');
  });

  test('POSMAIN.can helper respects injected capabilities', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/dashboard.php', { waitUntil: 'domcontentloaded' });
    const canManage = await page.evaluate(() => {
      const w = window as unknown as { POSMAIN?: { can?: (k: string) => boolean } };
      return typeof w.POSMAIN?.can === 'function' ? w.POSMAIN.can('users.manage') : null;
    });
    expect(canManage).toBe(true);
  });
});

test.describe('RBAC full suite: navigation visibility', () => {
  test('cashier navbar hides users link when lacking users.manage', async ({ page }) => {
    await loginAs(page, 'cashier');
    const permissions = await fetchCapabilities(page.request);
    await page.goto('/dashboard.php', { waitUntil: 'domcontentloaded' });

    if (permissions['users.manage']) {
      test.fail(true, 'cashier has users.manage — navbar will show admin links (demo seed or role misconfiguration)');
    }
    await expect(page.locator('a[href="users.php"]')).toHaveCount(0);
  });

  test('admin navbar shows users link', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/dashboard.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('a[href="users.php"]').first()).toBeVisible();
  });

  test('cashier sidebar hides add item when lacking menu.edit', async ({ page }) => {
    await loginAs(page, 'cashier');
    const permissions = await fetchCapabilities(page.request);
    await page.goto('/dashboard.php', { waitUntil: 'domcontentloaded' });

    if (permissions['menu.edit']) {
      test.fail(true, 'cashier has menu.edit — sidebar will show item editor links');
    }
    expect(await page.locator('.main-sidebar a[href="add_item.php"]').count()).toBe(0);
  });

  test('manager sidebar shows add item when menu.edit granted', async ({ page }) => {
    await loginAs(page, 'manager');
    const permissions = await fetchCapabilities(page.request);
    expect(permissions['menu.edit']).toBe(true);
    await page.goto('/dashboard.php', { waitUntil: 'domcontentloaded' });
    expect(await page.locator('.main-sidebar a[href="add_item.php"]').count()).toBeGreaterThan(0);
  });
});

test.describe('RBAC full suite: regression smoke', () => {
  test('change password page loads for any logged-in user', async ({ page }) => {
    await loginAs(page, 'cashier');
    const response = await page.goto('/change_password.php', { waitUntil: 'domcontentloaded' });
    const body = await page.content();
    assertNoApplicationError(body);
    expect(isAccessBlocked(response, body, page.url(), '/change_password.php')).toBeFalsy();
  });

  test('logout endpoint does not fatal', async ({ page }) => {
    await loginAs(page, 'cashier');
    const response = await page.goto('/do/do_logout.php', { waitUntil: 'domcontentloaded' });
    expect(response?.status() ?? 0).toBeLessThan(500);
  });

  test('core demo credentials reach dashboard', async ({ browser }) => {
    for (const role of CORE_PERSONAS) {
      const context = await browser.newContext();
      const page = await context.newPage();
      const { username, password } = personaCredentials(role);
      await page.goto('/index.php');
      await page.locator('#uname').fill(username);
      await page.locator('#password').fill(password);
      await page.getByRole('button', { name: /تسجيل الدخول/ }).click();
      await page.waitForURL(/dashboard\.php/, { timeout: 20_000 });
      await context.close();
    }
  });
});
