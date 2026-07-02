import { test, expect } from '@playwright/test';
import { loginAs, loginAndUnlockPos, unlockPos, assertNoFatalText } from '../helpers/auth';
import { clickFirstAddableItem, readCartNet, saveOrderOnly } from '../helpers/pos';
import {
  closeKdsHistory,
  completeKdsTicket,
  expectKdsBoardReady,
  kdsCardForProId,
  kdsCardForOrder,
  openFirstKdsStation,
  openKdsHistory,
  openKdsLauncher,
  waitForKdsPoll,
} from '../helpers/kds';

test.describe('kds: full system browser verification', () => {
  test.beforeEach(async ({ page }) => {
    await loginAs(page, 'admin');
  });

  test('launcher lists at least one station with open link', async ({ page }) => {
    await openKdsLauncher(page);
    const stations = page.locator('a[href^="kds_station.php?station="]');
    await expect(stations.first()).toBeVisible();
    await expect(page.getByRole('link', { name: /إعدادات الشاشات/ })).toBeVisible();
    const html = await page.content();
    assertNoFatalText(html);
  });

  test('settings page loads stations table and category routing form', async ({ page }) => {
    await page.goto('/kds_settings.php');
    await expect(page.getByRole('heading', { name: /إعدادات شاشة المطبخ/ })).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#stations table')).toBeVisible();
    await expect(page.locator('form[action="do/kds_category_map_save.php"]')).toBeVisible();
    assertNoFatalText(await page.content());
  });

  test('station board loads premium UI and poll feed succeeds', async ({ page }) => {
    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);

    const pollResponse = await page.waitForResponse((response) =>
      response.url().includes('ajax/kds_tickets_list.php') && response.ok(),
    );
    const feed = await pollResponse.json();
    expect(feed.success).toBeTruthy();
    expect(typeof feed.cursor).toBe('number');
    expect(Array.isArray(feed.changes)).toBeTruthy();

    await expect(page.locator('.kds-topbar')).toBeVisible();
    await expect(page.locator('#kdsSoundToggle')).toBeVisible();
    await expect(page.locator('#kdsHistoryBtn')).toBeVisible();
    await expect(page.locator('body')).toHaveClass(/kds-body/);
  });

  test('history drawer opens, loads entries, and closes without blocking board clicks', async ({ page }) => {
    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);

    const historyResponse = page.waitForResponse((response) =>
      response.url().includes('ajax/kds_history.php') && response.ok(),
    );
    await openKdsHistory(page);
    const response = await historyResponse;
    const body = await response.json();
    expect(body.success).toBeTruthy();
    expect(Array.isArray(body.tickets)).toBeTruthy();

    if (body.tickets.length > 0) {
      await page.locator('#kdsHistoryList .kds-history-card').first().click();
      await expect(page.locator('#kdsDetailModal')).toBeVisible();
      await expect(page.locator('#kdsDetailBody .kds-line').first()).toBeVisible();
      await page.locator('#kdsDetailClose').click();
      await expect(page.locator('#kdsDetailModal')).toBeHidden();
    }

    await closeKdsHistory(page);
    await expect(page.locator('#kdsDrawer')).toBeHidden();

    const cards = page.locator('.kds-card');
    if (await cards.count() > 0) {
      const first = cards.first();
      await expect(first.locator('.kds-act--done')).toBeVisible();
      await expect(first.locator('.kds-act--done')).toBeEnabled();
    }
  });

  test('ticket workflow: start then complete on board', async ({ page }) => {
    await unlockPos(page, 'admin');
    const netBefore = await readCartNet(page);
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(netBefore);
    const saveResponse = page.waitForResponse((response) =>
      response.url().includes('api/pos/index.php')
      && response.request().method() === 'POST'
      && response.url().includes('route=orders.takeaway'),
    );
    await saveOrderOnly(page);
    const response = await saveResponse;
    expect(response.ok()).toBeTruthy();

    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);

    const card = page.locator('.kds-card').first();
    await expect(card).toBeVisible({ timeout: 20_000 });
    const ticketId = await card.getAttribute('data-id');
    expect(ticketId).toBeTruthy();

    const startBtn = card.locator('.kds-act--start');
    if (await startBtn.isVisible().catch(() => false)) {
      const startResp = page.waitForResponse((r) =>
        r.url().includes('do/kds_ticket_action.php') && r.request().postData()?.includes('action=start'),
      );
      await startBtn.click();
      await startResp;
      await expect(card).toHaveClass(/status-in_progress/, { timeout: 10_000 });
    }

    await completeKdsTicket(page, page.locator(`.kds-card[data-id="${ticketId}"]`));
  });
});

test.describe('kds: POS integration end-to-end', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'admin');
  });

  test('saved POS order creates a ticket on the default station board', async ({ page }) => {
    const netBefore = await readCartNet(page);
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(netBefore);

    const saveResponse = page.waitForResponse((response) =>
      response.url().includes('api/pos/index.php')
      && response.request().method() === 'POST'
      && response.url().includes('route=orders.takeaway'),
    );
    await saveOrderOnly(page);
    const response = await saveResponse;
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.success).toBeTruthy();
    const orderId = Number(body.order_id);
    const proId = Number(body.pro_id);
    expect(orderId).toBeGreaterThan(0);
    expect(proId).toBeGreaterThan(0);

    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);

    const orderCard = kdsCardForProId(page, proId);
    await expect(orderCard).toBeVisible({ timeout: 25_000 });
    await expect(orderCard.locator('.kds-card__lines .kds-line').first()).toBeVisible();

    await completeKdsTicket(page, orderCard);

    await openKdsHistory(page);
    await expect.poll(
      async () => page.locator('#kdsHistoryList .kds-history-card').count(),
      { timeout: 10_000 },
    ).toBeGreaterThan(0);
    await page.locator('#kdsHistoryList .kds-history-card').first().click();
    await expect(page.locator('#kdsDetailModal')).toBeVisible();
    await page.locator('#kdsDetailClose').click();
    await closeKdsHistory(page);

    await waitForKdsPoll(page);
    await expect(kdsCardForProId(page, proId)).toHaveCount(0);
  });
});
