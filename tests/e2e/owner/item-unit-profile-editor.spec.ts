import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';
import { ITEM_EDITOR_UNITS, selectItemUnit, setPurchaseSectionActive } from '../helpers/item-editor';

test.describe('owner: item unit profile editor', () => {
  test.beforeEach(async ({ page }) => {
    await loginAs(page, 'admin');
    const response = await page.goto('/add_item.php');
    expect(response?.status() ?? 0).toBeLessThan(500);
    assertNoFatalText(await page.content());
  });

  test('sellable shows البيع section and requires sell price', async ({ page }) => {
    await expect(page.locator('#item-sell-section')).toBeVisible();
    await expect(page.locator('#item-purchase-section')).toBeVisible();
    await expect(page.locator('#sell_price1')).toBeVisible();
    await expect(page.locator('#sell-section-toggle-wrap')).toHaveClass(/d-none/);

    await page.fill('#iname', `E2E Sellable ${Date.now()}`);
    await page.fill('input[name="barcode"]', `E2E-S-${Date.now()}`);
    await page.fill('#sell_price1', '0');
    await page.locator('#item-main-form').evaluate((form: HTMLFormElement) => form.requestSubmit());
    page.once('dialog', async (dialog) => {
      expect(dialog.message()).toContain('سعر البيع');
      await dialog.dismiss();
    });
  });

  test('service hides شراء وتخزين', async ({ page }) => {
    await page.locator('.item-type-choice[data-item-type="service"]').click();
    await expect(page.locator('#item-purchase-section')).toHaveClass(/d-none/);
    await expect(page.locator('#sell_price1')).toBeVisible();
  });

  test('ingredient requires storage unit', async ({ page }) => {
    await page.locator('.item-type-choice[data-item-type="ingredient"]').click();
    await expect(page.locator('#storage_unit_id_input')).toBeVisible();
    await expect(page.locator('#sell-section-toggle-wrap')).not.toHaveClass(/d-none/);

    await page.fill('#iname', `E2E Ingredient ${Date.now()}`);
    await page.fill('input[name="barcode"]', `E2E-I-${Date.now()}`);
    await page.locator('#storage_unit_id_input').click();
    await page.locator('#storage_unit_listbox .item-unit-combobox__option').first().click();
    await page.locator('#item-main-form').evaluate((form: HTMLFormElement) => form.requestSubmit());
    page.once('dialog', async (dialog) => {
      await dialog.dismiss();
    });
  });

  test('purchase section exposes cost when activated', async ({ page }) => {
    await page.locator('#purchase_section_checkbox').check();
    await expect(page.locator('#purchase_cost')).toBeVisible();
    await expect(page.locator('#purchase_unit_id_input')).toBeVisible();
  });

  test('can create a new unit inline from sell section', async ({ page }) => {
    const unitName = `E2E Unit ${Date.now() % 100000}`;
    await page.locator('#item-sell-section .item-unit-picker__add').first().click();
    await expect(page.locator('#itemCatalogUnitModal')).toHaveClass(/is-open/);
    await page.locator('#itemCatalogUnitModal .item-unit-modal__input').fill(unitName);

    const saveResponse = page.waitForResponse((resp) =>
      resp.url().includes('ajax/item_catalog_unit_save.php') && resp.request().method() === 'POST',
    );
    await page.locator('#itemCatalogUnitModal .item-unit-modal__save').click();
    const response = await saveResponse;
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body.success).toBeTruthy();
    expect(body.id).toBeGreaterThan(0);

    await expect(page.locator('#itemCatalogUnitModal')).not.toHaveClass(/is-open/);
    await expect(page.locator('#sell_unit_id_input')).toHaveValue(unitName);
    await expect(page.locator('#sell_unit_id')).toHaveValue(String(body.id));

    const dbRow = await page.evaluate(async (name) => {
      const resp = await fetch(`/ajax/item_catalog_unit_save.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ name }),
      });
      const json = await resp.json();
      return json;
    }, unitName);
    expect(dbRow.existing).toBeTruthy();
    expect(dbRow.id).toBe(body.id);
  });

  test('swap conversion direction updates factor and labels', async ({ page }) => {
    await setPurchaseSectionActive(page, true);
    await selectItemUnit(page, 'sell_unit_id', ITEM_EDITOR_UNITS.piece);
    await selectItemUnit(page, 'storage_unit_id', ITEM_EDITOR_UNITS.kg);
    await selectItemUnit(page, 'purchase_unit_id', ITEM_EDITOR_UNITS.kg);
    await page.fill('#purchase_cost', '10');
    await page.fill('#sell_storage_factor', '4');

    const conversion = page.locator('#sell-storage-conversion');
    await expect(conversion).toBeVisible();
    await expect(conversion.locator('[data-role="left-unit"]')).not.toHaveText('—');
    await expect(conversion.locator('[data-role="right-unit"]')).not.toHaveText('—');

    await conversion.locator('.item-unit-conversion__swap').click();
    await expect(page.locator('#sell_storage_factor')).toHaveValue('0.25');
    await expect(conversion).toHaveClass(/is-direction-swapped/);
  });

  test('unit combobox reopen shows full list after selection', async ({ page }) => {
    const listbox = page.locator('#sell_unit_listbox');
    const options = listbox.locator('.item-unit-combobox__option');
    const initialCount = await options.count();
    test.skip(initialCount < 2, 'Need at least two units to verify full-list reopen');

    const firstName = (await options.first().textContent())?.trim() || '';
    await page.locator('#sell_unit_id_input').click();
    await options.first().click();
    await expect(page.locator('#sell_unit_id_input')).toHaveValue(firstName);

    await page.locator('#item-sell-section .item-unit-combobox__toggle').first().click();
    await expect(listbox).toBeVisible();
    await expect(options).toHaveCount(initialCount);
  });
});
