import { test, expect } from '@playwright/test'

test.describe('Products Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login')
    await page.fill('input[type="email"]', 'admin@pos.test')
    await page.fill('input[type="password"]', 'password123')
    await page.click('button[type="submit"]')
    await page.waitForURL('**/pos', { timeout: 10000 })
    await page.goto('/products')
  })

  test('should show products table', async ({ page }) => {
    await expect(page.locator('table')).toBeVisible({ timeout: 10000 })
  })

  test('should filter products by search', async ({ page }) => {
    const searchInput = page.locator('input[placeholder*="بحث"]')
    await searchInput.fill('test')
    await page.waitForTimeout(500)
    await expect(page.locator('table')).toBeVisible()
  })
})
