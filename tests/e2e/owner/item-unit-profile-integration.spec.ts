import { test, expect, type Page } from '@playwright/test';
import { loginAs, unlockPos, assertNoFatalText } from '../helpers/auth';
import {
  assertEditProfileState,
  dbItemIdByBarcode,
  dbUnitFlags,
  fillCreateItemForm,
  ITEM_EDITOR_UNITS,
  openItemEditFromCatalog,
  queryLocalDb,
  saveItem,
  uniqueItemLabel,
  type CreateItemProfile,
} from '../helpers/item-editor';
import { saveTakeawayOrder } from '../helpers/pos';

async function createItemViaProfile(page: Page, profile: CreateItemProfile): Promise<number> {
  await fillCreateItemForm(page, profile);
  await saveItem(page);
  return dbItemIdByBarcode(profile.barcode);
}

test.describe.serial('owner: item unit profile full browser integration', () => {
  const fixtures: Record<string, { name: string; barcode: string; itemId?: number; sellPrice?: string }> = {};

  test.beforeEach(async ({ page }) => {
    await loginAs(page, 'admin');
  });

  test('sellable default — قطعة only, sell price required and persisted', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('SellableDefault');
    fixtures.sellableDefault = { name, barcode, sellPrice: '15.5' };

    const itemId = await createItemViaProfile(page, {
      name,
      barcode,
      type: 'sellable',
      sell: { price1: '15.5' },
    });

    fixtures.sellableDefault.itemId = itemId;
    const flags = dbUnitFlags(itemId);
    expect(flags.length).toBe(1);
    expect(flags[0].def_sale).toBe(1);
    expect(flags[0].def_stock).toBe(1);
    expect(Number(flags[0].price1)).toBeCloseTo(15.5, 2);

    const headerPrice = queryLocalDb(`SELECT price1 FROM myitems WHERE id=${itemId}`);
    expect(Number(headerPrice)).toBeCloseTo(15.5, 2);

    await openItemEditFromCatalog(page, barcode);
    await assertEditProfileState(page, { type: 'sellable', sellPrice: '15.5' });
  });

  test.skip('sellable purchase pack — removed with simplified pricing UI', async () => {});

  test.skip('sellable sell≠storage conversion — removed with simplified pricing UI', async () => {});

  test('ingredient — default unit stock, sell off', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('IngredientDefault');
    fixtures.ingredient = { name, barcode };

    const itemId = await createItemViaProfile(page, {
      name,
      barcode,
      type: 'ingredient',
      sellActive: false,
    });

    fixtures.ingredient.itemId = itemId;
    const flags = dbUnitFlags(itemId);
    const stock = flags.find((row) => row.def_stock === 1);
    expect(stock?.unit_id).toBe(Number(ITEM_EDITOR_UNITS.piece));
    expect(flags.every((row) => row.def_sale === 0)).toBeTruthy();

    const preferred = queryLocalDb(`SELECT preferred_unit_id FROM myitems WHERE id=${itemId}`);
    expect(Number(preferred)).toBe(Number(ITEM_EDITOR_UNITS.piece));

    await openItemEditFromCatalog(page, barcode);
    await assertEditProfileState(page, { type: 'ingredient', sellActive: false });
  });

  test('ingredient with sell section enabled', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('IngredientSell');
    const itemId = await createItemViaProfile(page, {
      name,
      barcode,
      type: 'ingredient',
      sellActive: true,
      sell: { price1: '3.5' },
    });

    const flags = dbUnitFlags(itemId);
    const sell = flags.find((row) => row.def_sale === 1);
    expect(sell).toBeTruthy();
    expect(Number(sell!.price1)).toBeCloseTo(3.5, 2);

    await openItemEditFromCatalog(page, barcode);
    await assertEditProfileState(page, { type: 'ingredient', sellActive: true, sellPrice: '3.5' });
  });

  test('made — sell always on, type stored as made', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('MadeItem');
    const itemId = await createItemViaProfile(page, {
      name,
      barcode,
      type: 'made',
      sell: { price1: '25' },
    });

    const storedType = queryLocalDb(`SELECT item_type FROM myitems WHERE id=${itemId}`);
    expect(storedType.trim()).toBe('made');

    const flags = dbUnitFlags(itemId);
    const sell = flags.find((row) => row.def_sale === 1);
    expect(sell).toBeTruthy();
    expect(Number(sell!.price1)).toBeCloseTo(25, 2);

    await openItemEditFromCatalog(page, barcode);
    await assertEditProfileState(page, { type: 'made', sellPrice: '25' });
  });

  test('service — sell only, no purchase panel', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('Service');
    fixtures.service = { name, barcode, sellPrice: '12' };

    const itemId = await createItemViaProfile(page, {
      name,
      barcode,
      type: 'service',
      sell: { price1: '12' },
    });

    fixtures.service.itemId = itemId;
    const trackStock = queryLocalDb(`SELECT track_stock FROM myitems WHERE id=${itemId}`);
    expect(Number(trackStock)).toBe(0);

    await openItemEditFromCatalog(page, barcode);
    await expect(page.locator('#item-pricing-section')).toBeVisible();
    await assertEditProfileState(page, { type: 'service', sellPrice: '12' });
  });

  test('recipe editor picks ingredient stock unit on lookup', async ({ page }) => {
    test.skip(!fixtures.ingredient?.name || !fixtures.ingredient?.itemId, 'ingredient fixture missing');

    const draftRecipeId = Number(
      queryLocalDb(`SELECT id FROM recipe_headers WHERE status='draft' ORDER BY id DESC LIMIT 1`),
    );
    expect(draftRecipeId).toBeGreaterThan(0);

    await page.goto(`/recipe_manage.php?recipe_id=${draftRecipeId}`);
    assertNoFatalText(await page.content());

    await expect(page.locator('#recipe-details')).toBeVisible();
    const lookup = page.locator('form[data-recipe-save-form="add-component"] .recipe-lookup-input').first();
    await expect(lookup, 'draft recipe must expose add-component lookup').toBeVisible({ timeout: 15_000 });

    const searchTerm = fixtures.ingredient!.barcode.slice(0, 12);
    const lookupResponse = page.waitForResponse(
      (resp) =>
        resp.url().includes('ajax/recipe_editor_lookup.php')
        && resp.url().includes('type=components')
        && resp.status() === 200,
      { timeout: 15_000 },
    );
    await lookup.fill(searchTerm);
    const response = await lookupResponse;
    const payload = await response.json();
    expect(Array.isArray(payload.items)).toBeTruthy();

    const match = (payload.items as Array<{ id: number; stock_unit_id?: number }>).find(
      (item) => item.id === fixtures.ingredient!.itemId,
    );
    expect(match, 'lookup must return the ingredient fixture').toBeTruthy();
    expect(Number(match!.stock_unit_id)).toBe(Number(ITEM_EDITOR_UNITS.piece));

    const resultButton = page
      .locator('form[data-recipe-save-form="add-component"] .recipe-lookup-results button')
      .filter({ hasText: fixtures.ingredient!.barcode })
      .first();
    await expect(resultButton).toBeVisible({ timeout: 5_000 });
    await resultButton.click();

    const unitSelect = page.locator('form[data-recipe-save-form="add-component"] select[name="unit_id"]');
    await expect(unitSelect).toHaveValue(ITEM_EDITOR_UNITS.piece);
  });

  test('POS barcode scan adds sellable item with correct price', async ({ page }) => {
    test.skip(!fixtures.sellableDefault?.barcode, 'sellable fixture missing');

    await unlockPos(page, 'admin');
    const barcode = fixtures.sellableDefault!.barcode;
    const expectedPrice = Number(fixtures.sellableDefault!.sellPrice ?? '15.5');

    const searchResponse = page.waitForResponse((response) =>
      response.url().includes('ajax/search_item.php') && response.request().method() === 'POST',
    );
    await page.locator('#posUnifiedSearch').fill(barcode);
    await page.locator('#posUnifiedSearch').press('Enter');
    const response = await searchResponse;
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.success).toBeTruthy();
    expect(Number(body.item.price)).toBeCloseTo(expectedPrice, 2);

    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1, { timeout: 10_000 });

    const { orderId } = await saveTakeawayOrder(page);
    expect(orderId).toBeGreaterThan(0);
  });

  test.skip('conversion factor exposed to POS search — removed with simplified pricing UI', async () => {});

  test.skip('client validation blocks purchase section — removed with simplified pricing UI', async () => {});

  test.skip('swap conversion direction — removed with simplified pricing UI', async () => {});
});
