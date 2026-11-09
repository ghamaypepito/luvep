import { expect, test } from '@playwright/test';

const guide = '/international-student-program-guide';
const nextSpread = /\/program-guide\/pages\/0[23]\.webp$/;

test('keeps the cover visible until the next spread is decoded', async ({ page }) => {
  let release!: () => void;
  const pendingImages = new Promise<void>((resolve) => { release = resolve; });
  await page.route(nextSpread, async (route) => {
    await pendingImages;
    await route.continue();
  });

  await page.goto(guide, { waitUntil: 'domcontentloaded' });
  const pages = page.locator('#spread img');
  await expect(pages).toHaveCount(1);
  await expect.poll(() => pages.first().evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
  try {
    await page.getByRole('button', { name: 'Next page' }).click();
    await expect(page.locator('#stage')).toHaveAttribute('aria-busy', 'true');
    await expect(pages).toHaveCount(1);
    await expect(pages.first()).toHaveAttribute('src', /01\.webp$/);
  } finally {
    release();
  }

  await expect(pages).toHaveCount(2);
  expect(await pages.evaluateAll((images: HTMLImageElement[]) =>
    images.every((image) => image.complete && image.naturalWidth > 0))).toBe(true);
  await expect(page.locator('.outgoing-spread')).toHaveCount(0);
});

test('a failed page load preserves the current page and allows retry', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.route(nextSpread, (route) => route.fulfill({ status: 503 }));

  await page.goto(guide);
  const next = page.getByRole('button', { name: 'Next page' });
  await next.click();
  await expect(page.getByText('That page couldn’t load.', { exact: false })).toBeVisible();
  await expect(page.locator('#spread img')).toHaveAttribute('src', /01\.webp$/);

  await page.unroute(nextSpread);
  await next.click();
  await expect(page.locator('#spread img')).toHaveCount(2);
  await expect(page.getByText('That page couldn’t load.', { exact: false })).toBeHidden();
  await expect(page.locator('.outgoing-spread')).toHaveCount(0);
});

test('clicking the paper turns forward and backward', async ({ page }) => {
  await page.goto(guide);
  const spread = page.locator('#spread');
  await spread.locator('img').click();
  await expect(spread).toHaveAttribute('data-pages', '2,3');
  await spread.locator('img').first().click();
  await expect(spread).toHaveAttribute('data-pages', '1');
});

test('drag follows the pointer and commits only when pulled far enough', async ({ page }) => {
  await page.goto(guide);
  const spread = page.locator('#spread');
  await spread.locator('img').click();
  await expect(spread).toHaveAttribute('data-pages', '2,3');
  await expect(page.locator('.turn-preview')).toHaveCount(0);

  const right = await spread.locator('img').last().boundingBox();
  if (!right) throw new Error('Expected a visible right-hand page');
  const x = right.x + right.width - 12;
  const y = right.y + right.height / 2;
  const sheet = page.locator('.turning-sheet');

  await page.mouse.move(x, y);
  await page.mouse.down();
  await page.mouse.move(x - 35, y, { steps: 4 });
  await expect(sheet).toBeVisible();
  const firstAngle = await sheet.evaluate((element: HTMLElement) => element.style.transform);
  await page.mouse.move(x - 55, y, { steps: 4 });
  await expect.poll(() => sheet.evaluate((element: HTMLElement) => element.style.transform)).not.toBe(firstAngle);
  await expect(spread).toHaveAttribute('data-pages', '2,3');
  await page.mouse.move(x - 15, y, { steps: 4 });
  await page.mouse.up();
  await expect(sheet).toHaveCount(0);
  await expect(spread).toHaveAttribute('data-pages', '2,3');

  await page.mouse.move(x, y);
  await page.mouse.down();
  await page.mouse.move(x - right.width * 1.4, y, { steps: 12 });
  await expect(sheet.locator('img').last()).toHaveAttribute('src', /04\.webp$/);
  await expect(spread).toHaveAttribute('data-pages', '2,3');
  await page.mouse.up();
  await expect(spread).toHaveAttribute('data-pages', '4,5');
  await expect(sheet).toHaveCount(0);
});

test('touch drag moves the paper before release', async ({ browser }) => {
  const page = await browser.newPage({
    viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true,
  });
  try {
    const base = test.info().project.use.baseURL;
    await page.goto(new URL(guide, base).href);
    const spread = page.locator('#spread');
    const bounds = await spread.locator('img').boundingBox();
    if (!bounds) throw new Error('Expected a visible cover');
    const y = bounds.y + bounds.height / 2;
    const session = await page.context().newCDPSession(page);
    await session.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: 320, y }] });
    await session.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: 190, y }] });
    await expect(page.locator('.outgoing-spread img')).toHaveAttribute('style', /rotateY/);
    await expect(spread).toHaveAttribute('data-pages', '1');
    await session.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await expect(spread).toHaveAttribute('data-pages', '2');
  } finally {
    await page.close();
  }
});
