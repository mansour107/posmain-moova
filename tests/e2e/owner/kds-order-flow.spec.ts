import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { clickFirstAddableItem, readCartNet, saveOrderOnly } from '../helpers/pos';

/**
 * End-to-end KDS flow: an order placed in POS appears on the kitchen board,
 * can be completed with one click, and then surfaces in the station history.
 * Runs as admin (full KDS access via admin bypass).
 */
test.describe('kds: order reaches the kitchen board and completes', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'admin');
  });

  test('place order in POS, complete it on the KDS station board', async ({ page }) => {
    // 1. Place a takeaway order in POS.
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
    expect(body.order_id).toBeGreaterThan(0);

    // 2. Open the KDS launcher and enter the first available station.
    await page.goto('/kds.php');
    const stationLink = page.locator('a[href^="kds_station.php?station="]').first();
    await expect(stationLink).toBeVisible({ timeout: 15_000 });
    await stationLink.click();

    await expect(page.locator('#kdsScreen')).toBeVisible({ timeout: 15_000 });

    // 3. A ticket should arrive on the board within a couple of poll cycles.
    const cards = page.locator('.kds-card');
    await expect.poll(async () => cards.count(), { timeout: 20_000 }).toBeGreaterThan(0);

    // 4. One-click complete a specific ticket and assert THAT ticket leaves the
    // board. (Other orders may keep streaming in on a busy board, so a total
    // count assertion would be flaky; we track the exact ticket id instead.)
    const firstCard = cards.first();
    const ticketId = await firstCard.getAttribute('data-id');
    expect(ticketId).toBeTruthy();
    const targetCard = page.locator(`.kds-card[data-id="${ticketId}"]`);
    await targetCard.locator('.kds-act--done').click();
    await expect(targetCard).toHaveCount(0, { timeout: 15_000 });

    // 5. The history drawer should open and list completed/cancelled tickets.
    await page.locator('#kdsHistoryBtn').click();
    await expect(page.locator('#kdsDrawer')).toBeVisible();
    await expect.poll(
      async () => page.locator('#kdsHistoryList .kds-history-card').count(),
      { timeout: 10_000 },
    ).toBeGreaterThan(0);
    await page.locator('#kdsHistoryList .kds-history-card').first().click();
    await expect(page.locator('#kdsDetailModal')).toBeVisible();
    await page.locator('#kdsDetailClose').click();
  });
});
