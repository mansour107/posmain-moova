import { test, expect, type Page } from '@playwright/test';
import { execSync } from 'child_process';
import path from 'path';
import { loginAs, unlockPos } from '../helpers/auth';
import { assertNoApplicationError, fetchCapabilities, isAccessBlocked } from '../helpers/rbac';

async function setCashierUsersManageOverride(page: Page, enabled: boolean): Promise<void> {
  await page.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });
  const card = page.locator('#staffGrid .team-hub-card').filter({ hasText: 'p6_cashier' }).first();
  await card.click();
  await page.locator('[data-staff-tab="permissions"]').click();
  await expect(page.locator('#staffTabOverrides .team-hub-accordion').first()).toBeVisible({ timeout: 10_000 });
  const adminGroup = page.locator('details.team-hub-accordion').filter({ hasText: 'الإدارة' });
  await adminGroup.evaluate((el) => { el.open = true; });
  const row = page.locator('.team-hub-toggle-row:has(input[data-perm="users.manage"])');
  const toggle = row.locator('input[data-perm="users.manage"]');
  const isOn = await toggle.isChecked();
  if (isOn !== enabled) {
    await row.locator('.team-hub-switch').click({ force: true });
    await expect(toggle).toBeChecked({ checked: enabled });
    await page.locator('#staffPermSaveBtn').click();
    await expect(page.locator('#teamToast.is-visible')).toBeVisible({ timeout: 15_000 });
  }
}

test.beforeAll(async ({ browser }) => {
  const context = await browser.newContext();
  const page = await context.newPage();
  await loginAs(page, 'admin');
  await setCashierUsersManageOverride(page, false);
  await context.close();
});

test.describe('Team Hub UI', () => {
  test('admin sees team hub with staff and roles tabs', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/team.php', { waitUntil: 'domcontentloaded' });
    const body = await page.content();
    assertNoApplicationError(body);

    await expect(page.locator('#teamHubRoot')).toBeVisible();
    await expect(page.locator('#tabStaff')).toBeVisible();
    await expect(page.locator('#tabRoles')).toBeVisible();
    await expect(page.locator('.team-hub-breadcrumb strong')).toHaveText('الفريق');
    await expect(page.locator('#staffGrid .team-hub-card, #addStaffCard').first()).toBeVisible();
  });

  test('legacy users.php redirects to team hub', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/users.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/team\.php\?tab=staff/);
    await expect(page.locator('#teamHubRoot')).toBeVisible();
  });

  test('legacy myroles.php redirects to roles tab', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/myroles.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/team\.php\?tab=roles/);
    await expect(page.locator('#rolesGrid')).toBeVisible();
  });

  test('admin creates staff as manager with generated PIN from panel', async ({ page }) => {
    const suffix = Date.now().toString().slice(-6);
    const displayName = `أحمد ${suffix}`;

    await loginAs(page, 'admin');
    await page.goto('/team.php?tab=staff&panel=new', { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#teamPanel.is-open')).toBeVisible({ timeout: 10_000 });
    await page.locator('#staffDisplayName').fill(displayName);
    await page.locator('#rolePickRow .team-hub-role-pick').filter({ hasText: 'مدير' }).first().click();
    await expect(page.locator('#pinDisplay')).not.toHaveText('····');
    await page.locator('#staffSaveBtn').click();

    await expect(page.locator('#teamToast.is-visible')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#staffGrid')).toContainText(displayName);
    await expect(page.locator('#staffGrid')).toContainText('مدير');
  });

  test('admin opens role permissions panel for manager', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/team.php?tab=roles', { waitUntil: 'domcontentloaded' });

    await page.locator('#rolesGrid .team-hub-card').filter({ hasText: 'مدير' }).first().click();
    await expect(page.locator('#teamPanel.is-open')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#panelTitle')).toContainText('مدير');
    await expect(page.locator('.team-hub-accordion summary').first()).toBeVisible();
    await expect(page.locator('.team-hub-switch').first()).toBeVisible();
  });

  test('admin creates custom role from roles tab', async ({ page }) => {
    const roleName = `مشرف ${Date.now().toString().slice(-5)}`;
    await loginAs(page, 'admin');
    await page.goto('/team.php?tab=roles&panel=new_role', { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#teamPanel.is-open')).toBeVisible({ timeout: 10_000 });
    await page.locator('#newRoleName').fill(roleName);
    await page.locator('#clonePills .team-hub-template-pill').filter({ hasText: 'كاشير' }).click();
    await page.locator('#roleCreateBtn').click();

    await page.waitForURL(/team\.php\?tab=roles/, { timeout: 15_000 });
    await expect(page.locator('#rolesGrid')).toContainText(roleName);
  });

  test('cashier cannot access team hub', async ({ page }) => {
    await loginAs(page, 'cashier');
    const permissions = await fetchCapabilities(page.request);
    expect(permissions['users.manage'], 'cashier must not have users.manage').toBe(false);
    expect(permissions['roles.manage'], 'cashier must not have roles.manage').toBe(false);
    const response = await page.goto('/team.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#teamHubRoot')).toHaveCount(0);
    const body = await page.content();
    expect(isAccessBlocked(response, body, page.url(), '/team.php')).toBeTruthy();
  });

  test('navbar shows الفريق link for admin', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/index.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('a[href="team.php"]')).toHaveText('الفريق');
  });

  test('does not show نظامي badge anywhere', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/team.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).not.toContainText('نظامي');
  });

  test('owner role panel is read-only', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/team.php?tab=roles', { waitUntil: 'domcontentloaded' });
    await page.locator('#rolesGrid .team-hub-card').filter({ hasText: 'مالك' }).first().click();
    await expect(page.locator('#teamPanel.is-open')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#roleSaveBtn')).toHaveCount(0);
    await expect(page.locator('input[data-perm]:not([disabled])')).toHaveCount(0);
  });

  test('staff search filters grid', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });
    await page.locator('#staffSearch').fill('p6_admin');
    await expect(page.locator('#staffGrid .team-hub-card[data-staff-id]')).toHaveCount(1);
    await expect(page.locator('#staffGrid')).toContainText('p6_admin');
  });

  test('tabs switch between staff and roles', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });
    await page.locator('#tabRoles').click();
    await expect(page.locator('#sectionRoles')).toBeVisible();
    await expect(page.locator('#sectionStaff')).toHaveClass(/team-hub-hidden/);
    await page.locator('#tabStaff').click();
    await expect(page.locator('#sectionStaff')).toBeVisible();
  });

  test('staff and role cards show role icons not letter avatars', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });
    const cashierCard = page.locator('#staffGrid .team-hub-card--person').filter({ hasText: 'p6_cashier' }).first();
    await expect(cashierCard.locator('.team-hub-role-icon--cashier .fa-cash-register')).toBeVisible();
    await page.locator('#tabRoles').click();
    await expect(page.locator('#rolesGrid .team-hub-role-icon--manager .fa-user-shield').first()).toBeVisible();
  });

  test('admin sees decrypted PIN codes on staff cards', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });
    const cashierCard = page.locator('#staffGrid .team-hub-card--person').filter({ hasText: 'p6_cashier' }).first();
    await expect(cashierCard.locator('.team-hub-pin-code')).toHaveText(/^\d{4}$/);
  });

  test('users.manage without admin session sees masked PINs only', async ({ browser }) => {
    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    await loginAs(adminPage, 'admin');

    try {
      await setCashierUsersManageOverride(adminPage, true);

      const delegateContext = await browser.newContext();
      const delegatePage = await delegateContext.newPage();
      await loginAs(delegatePage, 'cashier');
      await delegatePage.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });
      await expect(delegatePage.locator('#teamHubRoot')).toBeVisible({ timeout: 15_000 });
      const cashierCard = delegatePage.locator('#staffGrid .team-hub-card--person').filter({ hasText: 'p6_cashier' }).first();
      await expect(cashierCard).toContainText('PIN');
      await expect(cashierCard.locator('.team-hub-pin-code')).toHaveCount(0);
      await delegateContext.close();
    } finally {
      await setCashierUsersManageOverride(adminPage, false).catch(() => {});
      await adminContext.close();
    }
  });

  test('legacy add_role.php redirects to team hub new role panel', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/add_role.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/team\.php\?tab=roles/);
    await expect(page.locator('#teamPanel.is-open')).toBeVisible({ timeout: 10_000 });
  });

  test('staff card click opens edit panel', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });
    const card = page.locator('#staffGrid .team-hub-card--person').filter({ hasText: 'p6_cashier' }).first();
    await card.click();
    await expect(page.locator('#teamPanel.is-open')).toBeVisible();
    await expect(page.locator('#panelTitle')).toContainText('تعديل موظف');
  });

  test('closing staff panel and refreshing does not reopen sidebar', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });
    const card = page.locator('#staffGrid .team-hub-card--person').filter({ hasText: 'p6_cashier' }).first();
    await card.click();
    await expect(page.locator('#teamPanel.is-open')).toBeVisible({ timeout: 10_000 });
    await page.locator('#panelCloseBtn').click();
    await expect(page.locator('#teamPanel.is-open')).toHaveCount(0);
    await expect(page).not.toHaveURL(/[?&]user=/);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('#teamPanel.is-open')).toHaveCount(0);
  });

  test('staff permissions tab shows effective toggles and changes access', async ({ browser }) => {
    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    await loginAs(adminPage, 'admin');

    try {
      await setCashierUsersManageOverride(adminPage, true);

      const cashierContext = await browser.newContext();
      const cashierPage = await cashierContext.newPage();
      await loginAs(cashierPage, 'cashier');
      await cashierPage.goto('/team.php', { waitUntil: 'domcontentloaded' });
      await expect(cashierPage.locator('#teamHubRoot')).toBeVisible({ timeout: 15_000 });
      await cashierContext.close();

      await setCashierUsersManageOverride(adminPage, false);

      const cashierContext2 = await browser.newContext();
      const cashierPage2 = await cashierContext2.newPage();
      await loginAs(cashierPage2, 'cashier');
      const blocked = await cashierPage2.goto('/team.php', { waitUntil: 'domcontentloaded' });
      const body = await cashierPage2.content();
      expect(isAccessBlocked(blocked, body, cashierPage2.url(), '/team.php')).toBeTruthy();
      await cashierContext2.close();
    } finally {
      await setCashierUsersManageOverride(adminPage, false).catch(() => {});
      await adminContext.close();
    }
  });

  test('staff without PIN auto-generates PIN on edit and shows on card after save', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });

    const card = page.locator('#staffGrid .team-hub-card--person').filter({
      has: page.locator('.team-hub-card-name', { hasText: /^admin$/ }),
    }).first();
    if ((await card.count()) === 0) {
      test.skip(true, 'legacy admin user not present in fixtures');
    }
    await card.click();
    await expect(page.locator('#teamPanel.is-open')).toBeVisible({ timeout: 10_000 });

    const cardPin = (await card.locator('.team-hub-pin-code').textContent())?.trim() || '';
    const pinDisplay = page.locator('#pinDisplay');
    if (/^\d{4}$/.test(cardPin)) {
      await expect(pinDisplay).toHaveText(cardPin);
    } else {
      await expect(pinDisplay).not.toHaveText('····');
      await expect(pinDisplay).not.toHaveText('—');
      await page.locator('#staffSaveBtn').click();
      await expect(page.locator('#teamToast.is-visible')).toBeVisible({ timeout: 15_000 });
    }

    await page.reload({ waitUntil: 'domcontentloaded' });
    const refreshed = page.locator('#staffGrid .team-hub-card--person').filter({
      has: page.locator('.team-hub-card-name', { hasText: /^admin$/ }),
    }).first();
    await expect(refreshed.locator('.team-hub-pin-code')).toHaveText(/^\d{4}$/);
    const savedPin = (await refreshed.locator('.team-hub-pin-code').textContent())?.trim() || '';
    await refreshed.click();
    await expect(pinDisplay).toHaveText(savedPin);
  });

  test('user permission deny blocks POS access for owner persona', async ({ browser }) => {
    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    await loginAs(adminPage, 'admin');
    await adminPage.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });

    const card = adminPage.locator('#staffGrid .team-hub-card').filter({ hasText: 'p6_admin' }).first();
    await card.click();
    await adminPage.locator('[data-staff-tab="permissions"]').click();
    await expect(adminPage.locator('#staffTabOverrides .team-hub-accordion').first()).toBeVisible({ timeout: 10_000 });

    const posGroup = adminPage.locator('details.team-hub-accordion').filter({ hasText: 'نقطة البيع' });
    await posGroup.evaluate((el) => { el.open = true; });
    const posOpenRow = adminPage.locator('.team-hub-toggle-row:has(input[data-perm="pos.open"])');
    const posOpenToggle = posOpenRow.locator('input[data-perm="pos.open"]');
    const hadPosOpen = await posOpenToggle.isChecked();
    if (hadPosOpen) {
      await posOpenRow.locator('.team-hub-switch').click({ force: true });
      await expect(posOpenToggle).not.toBeChecked();
      await adminPage.locator('#staffPermSaveBtn').click();
      await expect(adminPage.locator('#teamToast.is-visible')).toBeVisible({ timeout: 15_000 });
    }

    const targetContext = await browser.newContext();
    const targetPage = await targetContext.newPage();
    await loginAs(targetPage, 'admin');
    const blocked = await targetPage.goto('/pos_barcode.php', { waitUntil: 'domcontentloaded' });
    const body = await targetPage.content();
    expect(isAccessBlocked(blocked, body, targetPage.url(), '/pos_barcode.php')).toBeTruthy();

    await targetContext.close();

    if (hadPosOpen) {
      await adminPage.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });
      await adminPage.locator('#staffGrid .team-hub-card').filter({ hasText: 'p6_admin' }).first().click();
      await adminPage.locator('[data-staff-tab="permissions"]').click();
      await expect(adminPage.locator('#staffTabOverrides .team-hub-accordion').first()).toBeVisible({ timeout: 10_000 });
      const restoreGroup = adminPage.locator('details.team-hub-accordion').filter({ hasText: 'نقطة البيع' });
      await restoreGroup.evaluate((el) => { el.open = true; });
      const restoreRow = adminPage.locator('.team-hub-toggle-row:has(input[data-perm="pos.open"])');
      const restoreToggle = restoreRow.locator('input[data-perm="pos.open"]');
      if (!(await restoreToggle.isChecked())) {
        await restoreRow.locator('.team-hub-switch').click({ force: true });
        await expect(restoreToggle).toBeChecked();
      }
      await adminPage.locator('#staffPermSaveBtn').click();
      await expect(adminPage.locator('#teamToast.is-visible')).toBeVisible({ timeout: 15_000 });
    }
    await adminContext.close();
  });

  test('suspend with open POS drawer shows styled lifecycle modal', async ({ browser }) => {
    const repoRoot = path.join(__dirname, '../../..');
    const cashierContext = await browser.newContext();
    const cashierPage = await cashierContext.newPage();

    try {
      await loginAs(cashierPage, 'cashier');
      await unlockPos(cashierPage, 'cashier');

      const adminContext = await browser.newContext();
      const adminPage = await adminContext.newPage();
      try {
        await loginAs(adminPage, 'admin');
        await adminPage.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });

        const card = adminPage.locator('#staffGrid .team-hub-card').filter({ hasText: 'p6_cashier' }).first();
        await expect(card).toBeVisible({ timeout: 10_000 });
        const staffId = await card.getAttribute('data-staff-id');
        expect(staffId).toBeTruthy();

        const blockersRes = await adminPage.request.get(
          `/ajax/team_hub.php?action=staff_lifecycle_blockers&user_id=${encodeURIComponent(staffId || '')}`,
        );
        const blockers = await blockersRes.json();
        const drawerBlock = (blockers.blockers || []).find(
          (entry: { code?: string }) => entry.code === 'DRAWER_SESSION_OPEN',
        );
        if (!drawerBlock) {
          test.skip(true, 'drawer_sessions not blocking in this environment');
        }

        await card.click();
        await expect(adminPage.locator('#teamPanel.is-open')).toBeVisible({ timeout: 10_000 });
        await adminPage.locator('#staffDeactivateBtn').click();

        await expect(adminPage.locator('#teamHubConfirm.is-open')).toBeVisible({ timeout: 10_000 });
        await expect(adminPage.locator('#teamConfirmTitle')).toHaveText('لا يمكن الإيقاف الآن');
        await expect(adminPage.locator('#teamConfirmMsg')).toContainText('وردية نقاط بيع مفتوحة');
        await expect(adminPage.locator('#teamConfirmCancel')).toHaveClass(/team-hub-hidden/);
        await adminPage.locator('#teamConfirmOk').click();
        await expect(adminPage.locator('#teamHubConfirm.is-open')).toHaveCount(0);
      } finally {
        await adminContext.close();
      }
    } finally {
      await cashierContext.close();
      try {
        execSync('php tests/e2e/helpers/close_open_drawers_cli.php', { cwd: repoRoot, stdio: 'pipe' });
      } catch {
        // best-effort hygiene
      }
    }
  });
});
