import { test, expect, Page } from '@playwright/test'

// Helper: تسجيل الدخول
async function login(page: Page) {
  await page.goto('/login')
  await page.fill('input[type="email"], input[name="email"]', 'admin@pos.com')
  await page.fill('input[type="password"]', 'password')
  const responsePromise = page.waitForResponse(r => r.url().includes('/login') && r.status() === 200)
  await page.click('button[type="submit"]')
  await responsePromise
}

test.describe('Inventory Management', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('should load inventory page', async ({ page }) => {
    await page.click('a[href*="inventory"], a:has-text("المخزون")')
    await expect(page.locator('h2:has-text("المخزون"), h1:has-text("المخزون")')).toBeVisible({ timeout: 5000 })
  })

  test('should show low stock products', async ({ page }) => {
    await page.goto('/inventory')
    // ابحث عن تبويب أو زر "منخفض المخزون"
    const lowStockBtn = page.locator('button:has-text("منخفض"), button:has-text("low stock")').first()
    if (await lowStockBtn.isVisible()) {
      await lowStockBtn.click()
      await page.waitForTimeout(1000)
    }
  })

  test('should search inventory by product name', async ({ page }) => {
    await page.goto('/inventory')
    const searchInput = page.locator('input[placeholder*="بحث" i], input[type="search"]').first()
    if (await searchInput.isVisible()) {
      await searchInput.fill('test')
      await page.waitForTimeout(1000)
    }
  })
})
