import { test, expect } from '@playwright/test'

async function login(page: import('@playwright/test').Page) {
  await page.goto('/login')
  await page.fill('input[type="email"], input[name="email"]', 'admin@pos.com')
  await page.fill('input[type="password"]', 'password')
  const rp = page.waitForResponse(r => r.url().includes('/login') && r.status() === 200)
  await page.click('button[type="submit"]')
  await rp
}

const pages = [
  { path: '/',          title: 'نقطة البيع' },
  { path: '/products',  title: 'المنتجات' },
  { path: '/sales',     title: 'المبيعات' },
  { path: '/customers', title: 'العملاء' },
  { path: '/inventory', title: 'المخزون' },
  { path: '/settings',  title: 'الإعدادات' },
]

test.describe('Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  for (const p of pages) {
    test(`should load ${p.path}`, async ({ page }) => {
      await page.goto(p.path)
      // تأكد من عدم ظهور خطأ 404
      await expect(page.locator('text=Route not found')).not.toBeVisible({ timeout: 3000 })
    })
  }
})
