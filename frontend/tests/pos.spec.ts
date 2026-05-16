import { test, expect } from '@playwright/test'

async function login(page: any) {
  await page.goto('/login')
  await page.fill('input[type="email"]', 'admin@pos.test')
  await page.fill('input[type="password"]', 'password123')
  await page.click('button[type="submit"]')
  await page.waitForURL('**/pos', { timeout: 10000 })
}

test.describe('POS Page', () => {
  test('should load products grid after login', async ({ page }) => {
    await login(page)
    await expect(page.locator('.product-grid, [class*="productGrid"]')).toBeVisible({ timeout: 10000 })
  })
})
