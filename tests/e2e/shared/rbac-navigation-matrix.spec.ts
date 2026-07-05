import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';
import { RBAC_DENIED_PAGE_MATRIX, isAccessBlocked, assertNoApplicationError, fetchCapabilities } from '../helpers/rbac';

for (const entry of RBAC_DENIED_PAGE_MATRIX) {
  test(`${entry.role} cannot access ${entry.path}`, async ({ page }) => {
    await loginAs(page, entry.role);
    const permissions = await fetchCapabilities(page.request);
    test.skip(permissions[entry.permission] === true, `${entry.role} has ${entry.permission} in this environment`);

    const response = await page.goto(entry.path, { waitUntil: 'domcontentloaded' });
    const body = await page.content();
    assertNoApplicationError(body);
    expect(isAccessBlocked(response, body, page.url(), entry.path)).toBeTruthy();
  });
}
