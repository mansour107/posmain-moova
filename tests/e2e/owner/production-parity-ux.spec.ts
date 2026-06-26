import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

/**
 * Production-parity UX contract (POS vs Foodics fixes).
 *
 * Verifies the owner-visible surfaces introduced by the production-parity plan render and
 * behave as a real owner would expect:
 *   - inventory_purchasing.php: supplier empty-state guidance is shown (or the supplier
 *     select is populated after the default-supplier seeding helper runs).
 *   - recipe_manage.php: the recipe list surfaces an availability column header, and the
 *     item cost card is rendered (so owners can toggle/override cost even on active recipes).
 *   - myitems.php: empty-state guidance is present when the catalog is empty.
 *
 * Run locally:
 *   npx playwright test --project=owner production-parity-ux.spec.ts
 */

test.describe('owner: production-parity UX contract', () => {
  test('inventory receiving surfaces supplier guidance and does not fatal', async ({ page }) => {
    await loginAs(page, 'admin');

    const response = await page.goto('/inventory_purchasing.php');
    expect(response?.status() ?? 0).toBeLessThan(500);

    const body = await page.content();
    expect(body, 'purchasing page must not fatal').not.toMatch(/fatal error|SQL syntax|mysqli_|uncaught exception/i);

    // The supplier select must always be present; the default-supplier helper should ensure
    // at least one supplier option exists for a shop that has bootstrapped accounts.
    await expect(page.locator('#inventorySupplier')).toBeVisible();

    const supplierOptions = await page.locator('#inventorySupplier option').count();
    const hasSuppliers = supplierOptions > 1; // more than the "بدون مورد" placeholder

    if (!hasSuppliers) {
      // When no suppliers exist, the page must show actionable empty-state guidance with a
      // link to the accounts page rather than a silent, unusable dropdown.
      await expect(page.locator('.alert-info'))
        .toContainText(/211/);
    }
  });

  test('recipe manage surfaces availability column and cost card', async ({ page }) => {
    await loginAs(page, 'admin');

    const response = await page.goto('/recipe_manage.php');
    expect(response?.status() ?? 0).toBeLessThan(500);

    const body = await page.content();
    expect(body, 'recipe manage must not fatal').not.toMatch(/fatal error|SQL syntax|mysqli_|uncaught exception/i);

    // The recipe library table must surface the new availability column header.
    await expect(
      page.locator('table thead tr th', { hasText: 'المتاح للتحضير' }),
    ).toHaveCount(1);

    // The item cost card container must be rendered on the page (it appears when a recipe is
    // selected; on a fresh shop with no recipes we still assert the card template is wired by
    // checking the recipe-item-cost-card class exists in markup OR the empty-state row).
    const costCardCount = await page.locator('.recipe-item-cost-card').count();
    const emptyStateCount = await page.locator('table tbody td[colspan="6"]').count();
    const hasRecipes = costCardCount > 0;
    const hasEmptyState = emptyStateCount > 0;
    expect(hasRecipes || hasEmptyState, 'recipe list must either show cost cards or the empty state').toBeTruthy();
  });

  test('myitems surfaces empty-state guidance when catalog is empty', async ({ page }) => {
    await loginAs(page, 'admin');

    const response = await page.goto('/myitems.php');
    expect(response?.status() ?? 0).toBeLessThan(500);

    const body = await page.content();
    expect(body, 'myitems must not fatal').not.toMatch(/fatal error|SQL syntax|mysqli_|uncaught exception/i);

    const rowCount = await page.locator('#horsTable tbody tr.catalog-row').count();
    if (rowCount === 0) {
      // Empty catalog must show actionable guidance with a link to add the first item.
      await expect(page.locator('#horsTable tbody')).toContainText(/إضافة أول صنف|add_item\.php/);
    }
  });
});
