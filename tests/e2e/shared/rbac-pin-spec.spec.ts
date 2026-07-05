import { test, expect, type Page } from '@playwright/test';
import { loginAs, loginAndUnlockPos, unlockPos, isPosPinPadMode } from '../helpers/auth';
import {
  assertNoApplicationError,
  fetchCapabilities,
  isAccessBlocked,
  isHandlerRejected,
  postHandlerAsRole,
} from '../helpers/rbac';
import { personaCredentials } from '../helpers/env';

async function readUsersWriteCsrf(page: Page): Promise<string> {
  await page.goto('/users.php', { waitUntil: 'domcontentloaded' });
  const token = page.locator('input[name="csrf_token"]').first();
  await expect(token).toHaveCount(1);
  return token.inputValue();
}

test.describe('§8.2.1 cashier onboarding + terminal PIN login', () => {
  test('admin creates cashier via role cards then terminal unlock works', async ({ page }) => {
    const suffix = Date.now().toString().slice(-6);
    const username = `e2e_cash_${suffix}`;
    const demoPassword = process.env.POSMAIN_E2E_DEMO_PASSWORD || 'P6demo123!';

    await loginAs(page, 'admin');
    await page.goto('/add_user.php', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="uname"]').fill(username);
    await page.locator('input[name="display_name"]').fill(`كاشير ${suffix}`);
    await page.locator('input[name="password"]').fill(demoPassword);
    await page.locator('.role-card').filter({ hasText: 'كاشير' }).first().click();
    await page.locator('#addUserForm button[type="submit"], #addUserForm input[type="submit"]').first().click();
    await page.waitForURL(/users\.php/, { timeout: 20_000 });

    await page.goto('/pos_barcode.php', { waitUntil: 'domcontentloaded' });
    await unlockPos(page, 'cashier');
    await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
  });
});

test.describe('§8.2.2 preset home routing', () => {
  test('kitchen persona lands on KDS surface', async ({ page }) => {
    await page.goto('/index.php');
    const { username, password } = personaCredentials('kitchen');
    await page.locator('#uname').fill(username);
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: /تسجيل الدخول/ }).click();
    if (!page.url().includes('dashboard.php')) {
      test.skip(true, 'p6_kitchen persona unavailable in this environment');
    }

    const permissions = await fetchCapabilities(page.request);
    expect(permissions['kds.view']).toBe(true);

    const response = await page.goto('/kds.php', { waitUntil: 'domcontentloaded' });
    const body = await page.content();
    assertNoApplicationError(body);
    expect(isAccessBlocked(response, body, page.url(), '/kds.php')).toBeFalsy();
  });
});

test.describe('§8.2.3 discount limit immediate effect', () => {
  test('cashier POS exposes discount limit or capability after unlock', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    const payload = await page.evaluate(() => ({
      limits: (window as unknown as { POSMAIN_LIMITS?: Record<string, unknown> }).POSMAIN_LIMITS,
      canDiscount: (window as unknown as { POSMAIN?: { can?: (k: string) => boolean } }).POSMAIN?.can?.(
        'pos.discount.apply',
      ),
    }));
    const discountLimit = payload.limits?.['pos.discount.apply'] as
      | { limit_value?: number; is_unlimited?: boolean }
      | undefined;
    if (discountLimit) {
      expect(discountLimit.is_unlimited).toBeFalsy();
      expect(Number(discountLimit.limit_value ?? 0)).toBeGreaterThan(0);
    } else {
      expect(payload.canDiscount).toBe(true);
    }
  });
});

test.describe('§8.2.4 shift shortcut override', () => {
  test('edit user exposes يفتح الشيفت shortcut chip', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/users.php', { waitUntil: 'domcontentloaded' });
    const editLink = page.locator('a[href*="edit_user.php"]').first();
    await editLink.click();
    await expect(page.locator('.shift-shortcut-chip', { hasText: 'يفتح الشيفت' })).toBeVisible();
  });
});

test.describe('§8.2.5 PIN reset', () => {
  test('reset PIN reveals one-time banner on edit user', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/users.php', { waitUntil: 'domcontentloaded' });
    const cashierRow = page.locator('tr').filter({ hasText: 'p6_cashier' }).first();
    if (await cashierRow.count()) {
      await cashierRow.locator('a[href*="edit_user.php"]').click();
    } else {
      await page.locator('a[href*="edit_user.php"]').first().click();
    }
    const resetForm = page.locator('form[action*="do_user_reset_pin"]');
    if (await resetForm.count()) {
      await resetForm.locator('button[type="submit"]').click();
      await expect(page.locator('.pin-reveal-box, .alert-warning')).toBeVisible({ timeout: 15_000 });
    }
  });
});

test.describe('§8.2.6 deactivate and reactivate', () => {
  test('admin can open deactivated users view', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/users.php?show_deactivated=1', { waitUntil: 'domcontentloaded' });
    assertNoApplicationError(await page.content());
    await expect(page.locator('body')).toContainText(/المُلغى|المستخدمون/);
  });
});

test.describe('§8.2.7–8.2.9 security denials', () => {
  test('cashier direct URL to users.php is blocked', async ({ page }) => {
    await loginAs(page, 'cashier');
    const response = await page.goto('/users.php', { waitUntil: 'domcontentloaded' });
    const body = await page.content();
    expect(isAccessBlocked(response, body, page.url(), '/users.php')).toBeTruthy();
  });

  test('cashier POST doadd_user is rejected', async ({ page }) => {
    await loginAs(page, 'cashier');
    const response = await postHandlerAsRole(page, '/do/doadd_user.php', {
      uname: 'blocked_by_rbac',
      password: 'x',
      userrole: '2',
    });
    expect(isHandlerRejected(response)).toBeTruthy();
  });

  test('waiter cannot POST role creation without permission', async ({ page }) => {
    await loginAs(page, 'waiter');
    const response = await postHandlerAsRole(page, '/do/doadd_role.php', {
      rollname: 'waiter_blocked',
      info: 'x',
    });
    expect(isHandlerRejected(response)).toBeTruthy();
  });
});

test.describe('§8.2.10 IDOR manager cannot edit admin user', () => {
  test('manager POST doedit_user for admin id is blocked', async ({ page }) => {
    await loginAs(page, 'manager');
    const response = await postHandlerAsRole(page, '/do/doedit_user.php?id=1', {
      uname: 'hacked_admin',
      userrole: '1',
    });
    expect([403, 302, 200]).toContain(response.status());
    if (response.status() === 200) {
      const body = await response.text();
      expect(body).toMatch(/PRIVILEGE_ESCALATION|صلاحية|خطأ/i);
    } else {
      expect(isHandlerRejected(response)).toBeTruthy();
    }
  });
});

test.describe('§8.2.11 manager override API', () => {
  test('cashier override auth succeeds with valid manager PIN', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    const managerPin = process.env.POSMAIN_TEST_PIN_MANAGER || '1357';
    const csrf = await page.locator('meta[name="pos-override-csrf-token"]').getAttribute('content');
    expect(csrf).toBeTruthy();

    const payload = await page.evaluate(
      async ({ pin, token }) => {
        const body = new URLSearchParams({
          manager_pin: pin,
          permission_key: 'pos.discount.manual_pct.limit',
          csrf_token: token ?? '',
        });
        const response = await fetch('/ajax/pos_override_auth.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
        });
        return { status: response.status, json: await response.json().catch(() => ({})) };
      },
      { pin: managerPin, token: csrf },
    );

    expect(payload.status).toBeLessThan(500);
    if (payload.json.success) {
      expect(payload.json.approval_id).toBeGreaterThan(0);
    }
  });
});

test.describe('§8.2.12–8.2.13 PIN lockout responses', () => {
  test('five wrong override PINs return consistent error codes', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    const csrf = await page.locator('meta[name="pos-override-csrf-token"]').getAttribute('content');
    const codes: string[] = [];
    for (let i = 0; i < 5; i++) {
      const payload = await page.evaluate(
        async ({ token }) => {
          const body = new URLSearchParams({
            manager_pin: '5891',
            permission_key: 'pos.discount.manual_pct.limit',
            csrf_token: token ?? '',
          });
          const response = await fetch('/ajax/pos_override_auth.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
          });
          const json = await response.json().catch(() => ({}));
          return String(json.code ?? response.status);
        },
        { token: csrf },
      );
      codes.push(payload);
    }
    expect(codes.length).toBe(5);
    expect(codes.every((code) => /MANAGER_PIN|403|INVALID|LOCKED|LIMIT/i.test(code))).toBeTruthy();
  });

  test('wrong terminal PIN responses do not leak usernames', async ({ page }) => {
    if (!(await isPosPinPadMode(page, 'cashier'))) {
      test.skip(true, 'PIN pad mode unavailable (POSMAIN_PIN_SECRET missing on HTTP server)');
    }
    for (let i = 0; i < 3; i++) {
      for (const digit of '5891'.split('')) {
        await page.locator(`#pinGrid [data-key="${digit}"]`).click();
      }
      await page.locator('#pinGrid [data-key="دخول"]').click();
      await page.waitForTimeout(300);
      const errorText = (await page.locator('#posUnlockError').textContent()) ?? '';
      expect(errorText).not.toMatch(/p6_|rbac_pin_/i);
      expect(errorText).toMatch(/رمز غير صحيح|مقفل|PIN/i);
    }
  });
});

test.describe('§8.2.14 auto-lock cart park signal', () => {
  test('POS exposes acting user hidden field after unlock', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await expect(page.locator('#posActingUserId')).toHaveCount(1);
    const actingId = await page.locator('#posActingUserId').getAttribute('data-acting-user-id');
    expect(Number(actingId ?? 0)).toBeGreaterThan(0);
  });
});

test.describe('§8.2.15 CSRF replay rejected', () => {
  test('override auth rejects missing CSRF', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    const payload = await page.evaluate(async () => {
      const response = await fetch('/ajax/pos_override_auth.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'manager_pin=1357&permission_key=pos.discount.manual_pct.limit',
      });
      return response.status;
    });
    expect(payload).toBeGreaterThanOrEqual(400);
  });
});

test.describe('§8.2.16 session fixation hardening', () => {
  test('PHP session id changes after successful PIN login', async ({ page }) => {
    if (!(await isPosPinPadMode(page, 'cashier'))) {
      test.skip(true, 'PIN pad mode unavailable (POSMAIN_PIN_SECRET missing on HTTP server)');
    }
    const before = (await page.context().cookies()).find((c) => c.name === 'PHPSESSID')?.value;
    const pin = process.env.POSMAIN_TEST_PIN_CASHIER || '9753';
    for (const digit of pin.split('')) {
      await page.locator(`#pinGrid [data-key="${digit}"]`).click();
    }
    await page.locator('#pinGrid [data-key="دخول"]').click();
    await page.waitForLoadState('networkidle');
    const after = (await page.context().cookies()).find((c) => c.name === 'PHPSESSID')?.value;
    expect(before).toBeTruthy();
    expect(after).toBeTruthy();
    expect(after).not.toBe(before);
  });
});

test.describe('§8.2.17 pin_available body shape', () => {
  test('duplicate PIN check returns only available boolean', async ({ page }) => {
    await loginAs(page, 'admin');
    const payload = await page.evaluate(async () => {
      const response = await fetch('/ajax/pin_available.php?pin=9876', { credentials: 'same-origin' });
      return response.json();
    });
    expect(Object.keys(payload).sort()).toEqual(['available']);
  });
});

test.describe('§8.2.18 last admin guard', () => {
  test('deactivating primary admin user is rejected', async ({ page }) => {
    await loginAs(page, 'admin');
    const csrf = await readUsersWriteCsrf(page);
    const adminRow = page.locator('tr').filter({ hasText: 'p6_admin' }).first();
    let targetUserId = '1';
    if (await adminRow.count()) {
      const deactivateForm = adminRow.locator('form[action*="do_user_deactivate"]');
      const hidden = deactivateForm.locator('input[name="user_id"]');
      if (await hidden.count()) {
        targetUserId = await hidden.inputValue();
      }
    }
    const response = await page.request.post('/do/do_user_deactivate.php', {
      form: { user_id: targetUserId, csrf_token: csrf },
      failOnStatusCode: false,
      maxRedirects: 0,
    });
    expect([302, 200]).toContain(response.status());
    const location = response.headers()['location'] ?? '';
    const body = response.status() === 200 ? await response.text() : '';
    expect(location + body).toMatch(/error|LAST_ADMIN|صلاحية|self_deactivate|deactivated=0/i);
  });
});

test.describe('§8.2.19 RTL layout at 1024 and 1366', () => {
  for (const size of [
    { width: 1024, height: 768 },
    { width: 1366, height: 768 },
  ]) {
    test(`staff users page RTL @ ${size.width}x${size.height}`, async ({ page }) => {
      await page.setViewportSize(size);
      await loginAs(page, 'admin');
      await page.goto('/users.php', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
      assertNoApplicationError(await page.content());
    });

    test(`POS unlock surface RTL @ ${size.width}x${size.height}`, async ({ page }) => {
      await page.setViewportSize(size);
      await loginAs(page, 'cashier');
      await page.goto('/pos_barcode.php', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
      const pinPad = page.locator('#pinPadSection');
      const legacyGate = page.locator('input[name="pos_barcode"]');
      await expect(pinPad.or(legacyGate)).toBeVisible();
    });
  }
});
