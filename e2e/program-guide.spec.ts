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
