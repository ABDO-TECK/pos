import { test, expect, Page } from '@playwright/test'

async function login(page: Page) {
  await page.goto('/login')
  await page.fill('input[type="email"], input[name="email"]', 'admin@pos.com')
  await page.fill('input[type="password"]', 'password')
  const responsePromise = page.waitForResponse(r => r.url().includes('/login') && r.status() === 200)
  await page.click('button[type="submit"]')
  await responsePromise
}

test.describe('Reports', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('should load reports page', async ({ page }) => {
    await page.click('a[href*="reports"], a:has-text("التقارير")')
    await expect(page.locator('h2:has-text("التقارير"), h1:has-text("التقارير")')).toBeVisible({ timeout: 5000 })
  })

  test('should show dashboard summary stats', async ({ page }) => {
    // تحقق من وجود بطاقات الإحصائيات في الصفحة الرئيسية
    await page.goto('/')
    await expect(page.locator('.stat-card, [class*="stat"]').first()).toBeVisible({ timeout: 5000 })
  })
})
