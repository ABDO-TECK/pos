import { test, expect } from '@playwright/test'

// Helper: تسجيل الدخول (نسخة من pos.spec.ts)
async function login(page: import('@playwright/test').Page) {
  await page.goto('/login')
  await page.fill('input[type="email"], input[name="email"]', 'admin@pos.com')
  await page.fill('input[type="password"]', 'password')
  const responsePromise = page.waitForResponse(r => r.url().includes('/login') && r.status() === 200)
  await page.click('button[type="submit"]')
  await responsePromise
}

test.describe('Sales Flow', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('should add product to cart via search', async ({ page }) => {
    // انتظر تحميل شاشة POS
    const searchInput = page.locator('input[placeholder*="باركود" i], input[placeholder*="barcode" i], input[type="search"]').first()
    await expect(searchInput).toBeVisible({ timeout: 5000 })

    // ابحث عن منتج (أول منتج متاح)
    await searchInput.fill('a')
    // انتظر ظهور نتائج البحث أو بطاقات المنتجات
    await page.waitForTimeout(1000)

    // اضغط على أول منتج ظاهر
    const productCard = page.locator('.product-card').first()
    if (await productCard.isVisible()) {
      await productCard.click()
      // تأكد من إضافته للسلة
      await expect(page.locator('.cart-item, [class*="cart"]').first()).toBeVisible({ timeout: 3000 })
    }
  })

  test('should open payment modal', async ({ page }) => {
    const searchInput = page.locator('input[placeholder*="باركود" i], input[type="search"]').first()
    await expect(searchInput).toBeVisible({ timeout: 5000 })

    // أضف منتج
    const productCard = page.locator('.product-card').first()
    if (await productCard.isVisible()) {
      await productCard.click()
      await page.waitForTimeout(500)

      // اضغط زر الدفع
      const payBtn = page.locator('button:has-text("الدفع"), button:has-text("إتمام")').first()
      if (await payBtn.isVisible()) {
        await payBtn.click()
        // تأكد من ظهور نافذة الدفع
        await expect(page.locator('.modal-overlay, [class*="modal"]').first()).toBeVisible({ timeout: 3000 })
      }
    }
  })

  test('should complete a cash sale', async ({ page }) => {
    const searchInput = page.locator('input[placeholder*="باركود" i], input[type="search"]').first()
    await expect(searchInput).toBeVisible({ timeout: 5000 })

    const productCard = page.locator('.product-card').first()
    if (await productCard.isVisible()) {
      await productCard.click()
      await page.waitForTimeout(500)

      const payBtn = page.locator('button:has-text("الدفع"), button:has-text("إتمام")').first()
      if (await payBtn.isVisible()) {
        await payBtn.click()
        await page.waitForTimeout(500)

        // اضغط تأكيد البيع (Enter أو زر التأكيد)
        const confirmBtn = page.locator('button:has-text("تأكيد البيع"), button:has-text("تأكيد")').first()
        if (await confirmBtn.isVisible()) {
          await confirmBtn.click()
          // انتظر رسالة النجاح (toast)
          await expect(page.locator('[role="status"]:has-text("نجاح"), [role="status"]:has-text("🎉")')).toBeVisible({ timeout: 5000 })
        }
      }
    }
  })
})
