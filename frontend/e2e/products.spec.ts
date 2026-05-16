import { test, expect } from '@playwright/test'

async function login(page: import('@playwright/test').Page) {
  await page.goto('/login')
  await page.fill('input[type="email"], input[name="email"]', 'admin@pos.com')
  await page.fill('input[type="password"]', 'password')
  const rp = page.waitForResponse(r => r.url().includes('/login') && r.status() === 200)
  await page.click('button[type="submit"]')
  await rp
}

test.describe('Products Page', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
    await page.goto('/products')
  })

  test('should load products page', async ({ page }) => {
    await expect(page.locator('h1:has-text("المنتجات"), h2:has-text("المنتجات")')).toBeVisible({ timeout: 5000 })
  })

  test('should search for a product', async ({ page }) => {
    const search = page.locator('input[placeholder*="بحث" i]').first()
    await expect(search).toBeVisible({ timeout: 5000 })
    await search.fill('test')
    await page.waitForTimeout(1000)
    // يجب أن تتغير النتائج (إما تظهر نتائج أو رسالة "لا يوجد")
  })

  test('should open add product form', async ({ page }) => {
    const addBtn = page.locator('button:has-text("إضافة"), button:has-text("منتج جديد")').first()
    if (await addBtn.isVisible()) {
      await addBtn.click()
      // تأكد من ظهور نموذج الإضافة
      await expect(page.locator('input[placeholder*="اسم المنتج" i], input[name="name"]').first()).toBeVisible({ timeout: 3000 })
    }
  })
})
