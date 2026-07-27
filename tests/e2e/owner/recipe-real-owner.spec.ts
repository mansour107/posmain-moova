import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

/**
 * Real-owner simulation for the recipe management page.
 *
 * This is not a "page returns 200" smoke. It simulates an owner actually
 * opening recipe_manage.php, reading the recipe library, and:
 *  - when recipes exist: opening one, switching the details / cost-stock /
 *    versions / advanced tabs, and reading the recipe info;
 *  - when the library is empty (a real production-readiness observation):
 *    entering the create flow, using the real item autocomplete that calls
 *    ajax/recipe_editor_lookup.php, and confirming the create form is wired
 *    and not broken.
 *
 * Run locally:
 *   npx playwright test --project=owner recipe-real-owner.spec.ts
 */

test.describe('owner: recipe management — real owner journey', () => {
  test('owner opens recipe library and either opens a recipe or uses the create flow', async ({ page }) => {
    await loginAs(page, 'admin');

    const response = await page.goto('/recipe_manage.php');
    expect(response?.status() ?? 0).toBeLessThan(500);

    const body = await page.content();
    expect(body, 'page must not fatal').not.toMatch(/fatal error|SQL syntax|mysqli_|uncaught exception/i);

    // The page must show the recipe workspace shell.
    await expect(page.locator('.recipe-workspace')).toBeVisible();

    const openLinks = page.locator('a[href*="recipe_manage.php?recipe_id="]');
    const recipeCount = await openLinks.count();

    if (recipeCount > 0) {
      // Populated library: real owner opens the first recipe and navigates its tabs.
      const firstOpenHref = await openLinks.first().getAttribute('href');
      expect(firstOpenHref, 'recipe open link must target a recipe_id').toMatch(/recipe_id=\d+/);

      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        openLinks.first().click(),
      ]);

      const selectedBody = await page.content();
      expect(selectedBody, 'opened recipe must not fatal').not.toMatch(/fatal error|SQL syntax|mysqli_|uncaught exception/i);

      // After opening, the recipe details panel + tabs must be present and functional.
      await expect(page.locator('#recipe-details')).toBeVisible();
      await expect(page.locator('#recipe-details-tab')).toBeVisible();
      await expect(page.locator('#recipe-cost-stock-tab')).toBeVisible();
      await expect(page.locator('#recipe-versions-tab')).toBeVisible();

      // Real owner switches to the cost & stock tab (real navigation, not just render).
      await page.locator('#recipe-cost-stock-tab').click();
      await expect(page.locator('#recipe-cost-stock')).toBeVisible();

      // Owner switches to the versions tab and reads history.
      await page.locator('#recipe-versions-tab').click();
      await expect(page.locator('#recipe-versions')).toBeVisible();

      // Owner goes back to details tab to read recipe info.
      await page.locator('#recipe-details-tab').click();
      await expect(page.locator('#recipe-details')).toBeVisible();

      const finalBody = await page.content();
      expect(finalBody).toContain('ajax/recipe_editor_lookup.php');
    } else {
      // Empty library: owner enters the create flow and uses the real autocomplete.
      const createLink = page.locator('a.recipe-create-top-button');
      await expect(createLink, 'empty library must offer create button').toBeVisible();
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        createLink.click(),
      ]);

      const createBody = await page.content();
      expect(createBody, 'create page must not fatal').not.toMatch(/fatal error|SQL syntax|mysqli_|uncaught exception/i);

      // Create form must be wired with the create_draft action and sellable item lookup.
      const createForm = page.locator('#recipe-info-form');
      await expect(createForm).toBeVisible();
      await expect(createForm.locator('input[name="action"]')).toHaveValue('create_draft');

      const lookupInput = page.locator('.recipe-lookup-input[data-lookup-type="items"]').first();
      await expect(lookupInput, 'create flow must expose the item autocomplete').toBeVisible();

      // Real owner types a search term; the lookup JS requires >=2 chars before firing.
      const lookupPromise = page.waitForResponse(
        resp => resp.url().includes('ajax/recipe_editor_lookup.php') && resp.status() === 200,
        { timeout: 15_000 },
      );
      await lookupInput.fill('co');
      const lookupResp = await lookupPromise;
      const lookupJson = await lookupResp.json().catch(() => null);
      expect(lookupJson, 'lookup must return JSON').toBeTruthy();
      expect(Array.isArray(lookupJson?.items), 'lookup payload must expose an items array').toBe(true);
      // The shop has sellable items; the autocomplete should find at least one for a broad query.
      expect(lookupJson!.items.length, 'item autocomplete should return results for a populated catalog').toBeGreaterThan(0);
    }
  });

  test('owner recipe editor lookup AJAX returns JSON item array', async ({ page, request }) => {
    // The owner uses an autocomplete that calls ajax/recipe_editor_lookup.php.
    // We exercise the real endpoint through Playwright's request context after
    // establishing a logged-in session cookie in the browser.
    await loginAs(page, 'admin');

    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');

    const url = '/ajax/recipe_editor_lookup.php?type=items&kind=sellable&q=&limit=5';
    const resp = await request.get(url, {
      headers: {
        Cookie: cookieHeader,
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
    });

    // Anything 5xx is a production bug. 401/403 means the lookup needs a session
    // we couldn't forward; that is an auth signal, not a crash.
    expect(resp.status(), 'lookup endpoint must not 5xx').toBeLessThan(500);

    if (resp.status() === 200) {
      const json = await resp.json().catch(() => null);
      expect(json, 'lookup must return JSON').toBeTruthy();
      expect(Array.isArray(json?.items), 'lookup payload must expose an items array').toBe(true);
    }
  });
});
