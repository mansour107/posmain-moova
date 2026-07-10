import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { isAccessBlocked, isHandlerRejected } from '../helpers/rbac';
import { prepareCleanShift, skipUnlessHandoverEnabled } from '../helpers/handover';
import type { PersonaRole } from '../helpers/env';

const SHIFT_PAGE_DENIALS: Array<{ role: PersonaRole; path: string }> = [
  { role: 'cashier', path: '/cash_flow_report.php' },
  { role: 'cashier', path: '/drawer_session.php?id=1' },
  { role: 'waiter', path: '/cash_flow_report.php' },
  { role: 'waiter', path: '/closed_sessions.php' },
  { role: 'waiter', path: '/drawer_session.php?id=1' },
  { role: 'kitchen', path: '/cash_flow_report.php' },
  { role: 'kitchen', path: '/closed_sessions.php' },
  { role: 'kitchen', path: '/drawer_session.php?id=1' },
];

const SHIFT_HANDLER_DENIALS: Array<{ role: PersonaRole; path: string }> = [
  { role: 'cashier', path: '/do/do_record_shift_payin.php' },
  { role: 'cashier', path: '/do/do_record_shift_safe_drop.php' },
  { role: 'cashier', path: '/do/do_force_close_drawer.php' },
  { role: 'cashier', path: '/do/do_resolve_drawer_session.php' },
  { role: 'cashier', path: '/do/do_set_opening_float_baseline.php' },
  { role: 'waiter', path: '/do/do_record_shift_payin.php' },
  { role: 'waiter', path: '/do/do_record_shift_safe_drop.php' },
  { role: 'waiter', path: '/do/do_record_shift_expense.php' },
  { role: 'kitchen', path: '/do/do_record_shift_expense.php' },
  { role: 'kitchen', path: '/do/do_record_shift_payin.php' },
];

test.describe('shift money RBAC denials', () => {
  test.beforeAll(() => {
    prepareCleanShift();
  });

  test('pages and handlers deny unauthorized roles', async ({ page }, testInfo) => {
    await loginAs(page, 'manager');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    for (const row of SHIFT_PAGE_DENIALS) {
      await loginAs(page, row.role);
      const response = await page.goto(row.path);
      const body = await page.content();
      expect(
        isAccessBlocked(response, body, page.url(), row.path.split('?')[0]),
        `${row.role} must not access ${row.path}`,
      ).toBeTruthy();
    }

    for (const row of SHIFT_HANDLER_DENIALS) {
      await loginAs(page, row.role);
      const response = await page.request.post(row.path, {
        form: {
          amount: '1.00',
          reason: 'rbac-shift-denied',
          counted_cash: '1.00',
          notes: 'rbac',
          opening_float_baseline: '100',
          drawer_session_id: '1',
        },
        failOnStatusCode: false,
        maxRedirects: 0,
      });
      const json = await response.json().catch(() => null) as { success?: boolean } | null;
      const denied = isHandlerRejected(response) || response.status() >= 400 || json?.success === false;
      expect(denied, `${row.role} POST ${row.path}`).toBeTruthy();
    }

    for (const role of ['waiter', 'kitchen'] as const) {
      await loginAs(page, role);
      await page.goto('/dashboard.php');
      await expect(page.locator('a[href="cash_flow_report.php"]')).toHaveCount(0);
      await expect(page.locator('a[href="closed_sessions.php"]')).toHaveCount(0);
    }
  });
});
