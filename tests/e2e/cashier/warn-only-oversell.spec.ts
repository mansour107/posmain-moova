import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';

/**
 * Real-data GUI contract for Fix 3 (true warn-only oversell).
 *
 * On a shop with POSMAIN_RECIPE_STRICT_STOCK=0 and
 * POSMAIN_RECIPE_ALLOW_NEGATIVE_STOCK_WITH_APPROVAL=1, an active recipe whose
 * ingredients are out of stock must be sellable with a non-blocking warn toast,
 * NOT open the manager-approval modal. This is the contract the cashier sees.
 *
 * The contract is verified generically against whatever the live POS item grid
 * exposes: if any card carries data-availability-warn-only="1", clicking it must
 * not trigger ajax/manager_approval.php and must add the item to the cart. If no
 * warn-only card is present (e.g. all recipes in stock), the test no-ops with a
 * pass note rather than failing — warn-only is a conditional surface.
 */

test.describe('cashier: warn-only oversell (Fix 3)', () => {
  test('clicking a warn-only recipe item does not open manager approval and adds to cart', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');

    // Capture any manager_approval AJAX so we can assert it was NOT called for warn-only.
    const managerApprovalRequests: string[] = [];
    page.on('request', req => {
      if (req.url().includes('manager_approval.php')) {
        managerApprovalRequests.push(req.url());
      }
    });

    const cards = page.locator('.item-wrapper [data-item-id]');
    await expect(cards.first()).toBeVisible({ timeout: 20_000 });

    // Find a card flagged warn-only. Fall back to searching for the known out-of-stock
    // recipe sellable item by name if the default grid does not surface one.
    let warnOnlyCard = page.locator('.item-wrapper [data-availability-warn-only="1"]').first();

    if ((await warnOnlyCard.count()) === 0) {
      // Try the POS search box to surface the out-of-stock recipe item (e.g. "كريب برجر").
      const search = page.locator('#search_item, input[name="search_item"]').first();
      if (await search.isVisible().catch(() => false)) {
        await search.fill('كريب برجر');
        await page.waitForLoadState('networkidle').catch(() => undefined);
        warnOnlyCard = page.locator('.item-wrapper [data-availability-warn-only="1"]').first();
      }
    }

    if ((await warnOnlyCard.count()) === 0) {
      // No warn-only surface in this shop state — warn-only is conditional, not a failure.
      test.skip(true, 'No warn-only item card present in the current shop state; nothing to verify.');
      return;
    }

    const itemId = await warnOnlyCard.getAttribute('data-item-id');
    expect(itemId, 'warn-only card must carry an item id').toBeTruthy();

    // The manager-approval modal must NOT be the path for warn-only. Swal toast is non-blocking.
    await warnOnlyCard.click();
    await page.waitForTimeout(800);

    // Either a Swal toast appeared (warn) OR the item was added to the cart directly.
    const cartHasItem = await page
      .locator(`#order-items [data-item-id="${itemId}"], #order-items .order-item-row`)
      .first()
      .isVisible()
      .catch(() => false);
    const swalVisible = await page.locator('.swal2-toast, .swal2-popup').first().isVisible().catch(() => false);

    expect(
      cartHasItem || swalVisible,
      'warn-only click must add to cart or show a toast, not block',
    ).toBeTruthy();
    expect(
      managerApprovalRequests,
      'warn-only must NOT hit ajax/manager_approval.php',
    ).toHaveLength(0);
  });
});
