import { test, expect, type Locator } from '@playwright/test';
import path from 'path';
import { loginAndUnlockPos } from '../helpers/auth';
import {
  clickFirstAddableItem,
  openPaymentModal,
  openRecentOrdersFromCorner,
} from '../helpers/pos';

const screenshotDir = path.join(process.cwd(), 'tests/e2e/screenshots');

async function expectDarkSurface(locator: Locator) {
  const bg = await locator.evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(bg).not.toBe('rgb(255, 255, 255)');
  expect(bg).not.toBe('rgba(0, 0, 0, 0)');
}

test.describe('cashier: premium dark visual compliance', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
  });

  test('order type tabs use dark bar without white pill background', async ({ page }) => {
    const tabsBar = page.locator('.pos-order-type-tabs');
    await expect(tabsBar).toBeVisible();

    const barBg = await tabsBar.evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(barBg).not.toBe('rgb(248, 251, 255)');

    const activeTab = page.locator('.pos-order-type-tabs .pos-mode-tab.active');
    await expect(activeTab).toBeVisible();
    const activeBg = await activeTab.evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(activeBg).not.toBe('rgb(255, 255, 255)');
    expect(activeBg).not.toBe('rgb(18, 63, 125)');

    const borderBottom = await activeTab.evaluate((el) => getComputedStyle(el).borderBottomColor);
    expect(borderBottom).toMatch(/rgb/);

    await page.screenshot({
      path: path.join(screenshotDir, 'pos-order-panel.png'),
      fullPage: false,
    });
  });

  test('payment modal uses dark surfaces', async ({ page }) => {
    await clickFirstAddableItem(page);
    await openPaymentModal(page);

    const modalBody = page.locator('#paymentModal .modal-body');
    await expectDarkSurface(modalBody);

    const paymentCard = page.locator('#paymentModal .card').first();
    await expectDarkSurface(paymentCard);

    await page.screenshot({
      path: path.join(screenshotDir, 'pos-payment-modal.png'),
      fullPage: false,
    });
  });

  test('recent orders modal uses dark table', async ({ page }) => {
    await openRecentOrdersFromCorner(page);
    await page.waitForSelector('#recentOrdersList tr', { timeout: 15000 });

    const modalBody = page.locator('#recentOrdersModal .pos-recent-orders-body');
    await expectDarkSurface(modalBody);

    const firstRow = page.locator('#recentOrdersList tr').first();
    const rowBg = await firstRow.evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(rowBg).not.toBe('rgb(255, 255, 255)');

    await firstRow.hover();
    const hoveredRowBg = await firstRow.evaluate((el) => getComputedStyle(el).backgroundColor);
    const hoveredCellBg = await firstRow.locator('td').first().evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(hoveredRowBg).not.toBe('rgb(227, 242, 253)'); // pos.css light-blue hover
    expect(hoveredCellBg).not.toBe('rgb(227, 242, 253)');
    expect(hoveredCellBg).not.toBe('rgb(255, 255, 255)');

    const totalCell = firstRow.locator('td.text-success').first();
    if (await totalCell.count()) {
      const totalColor = await totalCell.evaluate((el) => getComputedStyle(el).color);
      expect(totalColor).toMatch(/rgb\(74, 222, 128\)|rgb\(46, 204, 113\)/);
    }

    await page.screenshot({
      path: path.join(screenshotDir, 'pos-recent-orders.png'),
      fullPage: false,
    });
  });

  test('cart row layout matches premium dark order panel', async ({ page }) => {
    await clickFirstAddableItem(page);
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1);

    const cartRow = page.locator('#itemData .pos-cart-row').first();
    await expect(cartRow).toBeVisible();

    await page.screenshot({
      path: path.join(screenshotDir, 'pos-order-with-cart.png'),
      fullPage: false,
    });
  });
});
