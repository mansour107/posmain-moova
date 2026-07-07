import { test, expect } from '@playwright/test';
import { enterManagerOverridePin, loginAndUnlockPos, loginAs, submitManagerOverridePinAttempt } from '../helpers/auth';
import {
  clickFirstAddableItem,
  clickNthAddableItem,
  openOrderForEdit,
  saveEditedOrder,
  saveTakeawayOrder,
} from '../helpers/pos';
import { openTeamHubStaffPermissions } from '../helpers/team-hub';

test.describe('locked POS actions with manager PIN override', () => {
  test('cashier removing persisted line prompts for manager PIN then saves', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');

    await clickFirstAddableItem(page);
    await clickNthAddableItem(page, 1);
    const { orderId } = await saveTakeawayOrder(page);
    expect(orderId).toBeGreaterThan(0);

    await openOrderForEdit(page, orderId);
    const firstLine = page.locator('#itemData .item-card-order').first();
    await expect(firstLine).toHaveAttribute('data-persisted-line', '1');

    const overrideRequest = page.waitForResponse((response) =>
      response.url().includes('/ajax/pos_override_auth.php')
      && response.request().method() === 'POST',
    );
    await firstLine.locator('.delRow').click();
    await enterManagerOverridePin(page, 'manager');
    const overrideResponse = await overrideRequest;
    const overrideBody = await overrideResponse.json();
    expect(overrideBody.success).toBeTruthy();
    expect(overrideBody.approval_id).toBeGreaterThan(0);

    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);
    const editSave = page.waitForResponse((response) =>
      response.url().includes('api/pos/index.php')
      && response.request().method() === 'POST',
    );
    await saveEditedOrder(page);
    const saveResponse = await editSave;
    const saveBody = await saveResponse.json();
    expect(saveResponse.ok(), JSON.stringify(saveBody)).toBeTruthy();
    expect(saveBody.success).toBeTruthy();
  });

  test('cashier cancel on PIN prompt keeps persisted line', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');

    await clickFirstAddableItem(page);
    const { orderId } = await saveTakeawayOrder(page);
    await openOrderForEdit(page, orderId);

    const firstLine = page.locator('#itemData .item-card-order').first();
    await firstLine.locator('.delRow').click();
    await expect(page.locator('#posPinPadModal')).toBeVisible();
    await page.locator('#posPinPadModal [data-pin-cancel]').click();
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);
  });

  test('server rejects removed persisted line without approval id', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');

    await clickFirstAddableItem(page);
    await clickNthAddableItem(page, 1);
    const { orderId } = await saveTakeawayOrder(page);
    await openOrderForEdit(page, orderId);

    await page.evaluate(() => {
      const form = document.getElementById('posForm') as HTMLFormElement | null;
      form?.querySelectorAll('input[name="manager_approval_id"], input[name="itmmanagerapproval[]"]').forEach((input) => {
        (input as HTMLInputElement).value = '';
      });
      const line = document.querySelector('#itemData .item-card-order');
      line?.remove();
      const w = window as unknown as { recalculateOrderTotals?: () => void };
      if (typeof w.recalculateOrderTotals === 'function') {
        w.recalculateOrderTotals();
      }
    });

    const tamperResponse = await page.evaluate(async () => {
      const form = document.getElementById('posForm') as HTMLFormElement | null;
      if (!form) {
        return { status: 0, body: null };
      }
      const data = new FormData(form);
      const editId = (form.querySelector('input[name="edit_id"]') as HTMLInputElement | null)?.value
        || (document.getElementById('edit_order_id') as HTMLInputElement | null)?.value
        || '';
      if (editId) {
        data.set('edit_id', editId);
        data.set('order_id', editId);
      }
      data.set('idempotency_key', `e2e-void-tamper:${editId}:${Date.now()}`);
      data.set('action', 'save');
      const response = await fetch('/api/pos/index.php?route=orders.edit', {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
      });
      const body = await response.json().catch(() => null);
      return { status: response.status, body };
    });

    expect(tamperResponse.status, JSON.stringify(tamperResponse.body)).toBe(403);
    expect(tamperResponse.body?.code).toBe('MANAGER_APPROVAL_REQUIRED');
  });

  test('recent orders show locked reversal button for cashier on paid orders', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    const payload = await page.evaluate(async () => {
      const response = await fetch('/ajax/get_recent_orders.php?limit=20', { credentials: 'same-origin' });
      return response.json();
    });
    expect(payload.success).toBeTruthy();

    const reversible = (payload.orders || []).find((order: { refund_eligible?: boolean; void_eligible?: boolean }) =>
      order.refund_eligible || order.void_eligible,
    );
    if (!reversible) {
      test.skip(true, 'no refund/void-eligible paid order in recent list');
    }

    await page.locator('#cornerRecentOrdersBtn').click();
    await expect(page.locator('#recentOrdersModal')).toBeVisible();
    const row = page.locator(`#recentOrdersList tr[data-order-id="${reversible.id}"]`);
    await expect(row).toBeVisible();
    const reversalBtn = row.locator('.reverse-paid-order');
    await expect(reversalBtn).toBeVisible();
    await expect(reversalBtn).toHaveClass(/pos-action-locked/);
  });

  test('table tab stays visible but locked when pos.table.open is denied', async ({ page }) => {
    await loginAs(page, 'admin');
    await openTeamHubStaffPermissions(page, 'p6_cashier');
    await page.locator('.team-hub-accordion:has([data-perm="pos.table.open"])').evaluate((el) => {
      (el as HTMLDetailsElement).open = true;
    });
    const tableToggle = page.locator('input[data-perm="pos.table.open"]').first();
    await expect(tableToggle).toBeAttached({ timeout: 10_000 });
    if (await tableToggle.isChecked()) {
      await page.locator('.team-hub-toggle-row[data-perm-row="pos.table.open"] .team-hub-switch').click();
    }
    await page.locator('#staffPermSaveBtn').click();
    await expect(page.locator('#teamToast.is-visible')).toBeVisible({ timeout: 15_000 });

    await page.goto('/do/do_logout.php', { waitUntil: 'domcontentloaded' });
    await loginAndUnlockPos(page, 'cashier');
    const tableTab = page.locator('.pos-mode-tab[data-age-target="age2"]');
    await expect(tableTab).toBeVisible();
    await expect(tableTab).toHaveClass(/pos-action-locked/);

    await tableTab.click();
    await enterManagerOverridePin(page, 'manager');
    await expect(page.locator('#tablesModal')).toBeVisible({ timeout: 15_000 });
  });

  test('wrong manager PIN keeps modal open with error message', async ({ page }) => {
    await loginAs(page, 'admin');
    await openTeamHubStaffPermissions(page, 'p6_cashier');
    await page.locator('.team-hub-accordion:has([data-perm="pos.table.open"])').evaluate((el) => {
      (el as HTMLDetailsElement).open = true;
    });
    const tableToggle = page.locator('input[data-perm="pos.table.open"]').first();
    if (await tableToggle.isChecked()) {
      await page.locator('.team-hub-toggle-row[data-perm-row="pos.table.open"] .team-hub-switch').click();
    }
    await page.locator('#staffPermSaveBtn').click();
    await expect(page.locator('#teamToast.is-visible')).toBeVisible({ timeout: 15_000 });

    await page.goto('/do/do_logout.php', { waitUntil: 'domcontentloaded' });
    await loginAndUnlockPos(page, 'cashier');
    await page.locator('.pos-mode-tab[data-age-target="age2"]').click();

    const modal = page.locator('#posPinPadModal');
    await expect(modal).toBeVisible({ timeout: 15_000 });
    await submitManagerOverridePinAttempt(page, '0000');
    await expect(modal).toBeVisible();
    await expect(modal.locator('#posPinPadError')).toContainText(/غير صحيح/i);

    await submitManagerOverridePinAttempt(page, '9753');
    await expect(modal).toBeVisible();
    await expect(modal.locator('#posPinPadError')).toContainText(/غير مصرح/i);

    await enterManagerOverridePin(page, 'manager');
    await expect(page.locator('#tablesModal')).toBeVisible({ timeout: 15_000 });
  });
});
