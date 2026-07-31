import { describe, expect, it } from 'vitest'
import { buildPurchaseReceiptInnerHTML, buildReceiptInnerHTML } from './receiptBuilder'

describe('receiptBuilder output encoding', () => {
  it('escapes sale-controlled text and rejects non-allowlisted logo data', () => {
    const invoice: Sale = {
      id: 1,
      user_id: 1,
      customer_id: null,
      total_amount: 10,
      discount: 0,
      tax: 0,
      net_amount: 10,
      payment_method: '<img src=x onerror=alert(1)>',
      paid_amount: 10,
      due_amount: 0,
      items: [{
        product_id: 1,
        product_name: '<script>alert(1)</script>',
        quantity: 1,
        unit_price: 10,
        subtotal: 10,
      }],
    }

    const html = buildReceiptInnerHTML(invoice, 0, {
      storeName: '<svg onload=alert(1)>',
      storeLogo: 'data:image/svg+xml;base64,PHN2Zy8+',
    })

    expect(html).toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
    expect(html).toContain('&lt;svg onload=alert(1)&gt;')
    expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;')
    expect(html).not.toContain('data:image/svg+xml')
  })

  it('escapes supplier and purchase item names', () => {
    const invoice: PurchaseInvoice = {
      id: 2,
      supplier_id: 1,
      supplier_name: '<b>supplier</b>',
      user_id: 1,
      total: 5,
      items: [{
        product_id: 1,
        product_name: '<i>item</i>',
        quantity: 1,
        unit_cost: 5,
        subtotal: 5,
      }],
    }

    const html = buildPurchaseReceiptInnerHTML(invoice)

    expect(html).toContain('&lt;b&gt;supplier&lt;/b&gt;')
    expect(html).toContain('&lt;i&gt;item&lt;/i&gt;')
  })

  it('prints shipping cost and does not repeat an embedded size name', () => {
    const invoice: Sale = {
      id: 3,
      user_id: 1,
      customer_id: null,
      total_amount: 110,
      discount: 0,
      tax: 0,
      net_amount: 110,
      payment_method: 'cash',
      paid_amount: 110,
      due_amount: 0,
      shipping_cost: 10,
      items: [{
        product_id: 1,
        product_name: 'T-shirt - XL',
        size_name: 'XL',
        quantity: 1,
        unit_price: 100,
        subtotal: 100,
      }],
    }

    const html = buildReceiptInnerHTML(invoice)

    expect(html).toContain('تكلفة الشحن')
    expect(html).toContain('10.00')
    expect(html).toContain('T-shirt - XL')
    expect(html).not.toContain('T-shirt - XL (XL)')
  })
})
