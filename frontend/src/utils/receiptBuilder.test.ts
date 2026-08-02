import { describe, expect, it } from 'vitest'
import {
  buildPurchaseReceiptHTML,
  buildPurchaseReceiptInnerHTML,
  buildReceiptHTML,
  buildReceiptInnerHTML,
} from './receiptBuilder'

describe('receiptBuilder output encoding', () => {
  it('prints an optional customer name only when it is supplied', () => {
    const invoice: Sale = {
      id: 8,
      user_id: 1,
      customer_id: null,
      customer_name: 'عميل <مميز>',
      total_amount: 10,
      discount: 0,
      tax: 0,
      net_amount: 10,
      payment_method: 'cash',
      paid_amount: 10,
      due_amount: 0,
      items: [],
    }

    const html = buildReceiptInnerHTML(invoice)
    expect(html).toContain('اسم العميل:')
    expect(html).toContain('عميل &lt;مميز&gt;')
    expect(buildReceiptInnerHTML({ ...invoice, customer_name: undefined })).not.toContain('اسم العميل:')
  })

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

  it('keeps invoice headings Arabic without an English INVOICE label', () => {
    const sale: Sale = {
      id: 6,
      user_id: 1,
      customer_id: null,
      total_amount: 10,
      discount: 0,
      tax: 0,
      net_amount: 10,
      payment_method: 'cash',
      paid_amount: 10,
      due_amount: 0,
      items: [],
    }
    const purchase: PurchaseInvoice = {
      id: 7,
      supplier_id: 1,
      supplier_name: 'Supplier',
      user_id: 1,
      total: 10,
      discount: 0,
      shipping_cost: 0,
      items: [],
    }

    for (const html of [buildReceiptHTML(sale), buildPurchaseReceiptHTML(purchase)]) {
      expect(html).not.toContain("content: 'INVOICE'")
      expect(html).not.toContain('>INVOICE<')
    }
  })

  it('escapes supplier and purchase item names', () => {
    const invoice: PurchaseInvoice = {
      id: 2,
      supplier_id: 1,
      supplier_name: '<b>supplier</b>',
      user_id: 1,
      total: 6,
      discount: 1,
      shipping_cost: 2,
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
    expect(html).toContain('خصم المورد')
    expect(html).toContain('تكلفة الشحن')
    expect(html).toContain('6')
    expect(html).not.toContain('6.00')
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
    expect(html).toContain('10')
    expect(html).not.toContain('10.00')
    expect(html).toContain('T-shirt - XL')
    expect(html).not.toContain('T-shirt - XL (XL)')
  })

  it('keeps weighted quantities and the kg unit inside one quantity cell', () => {
    const invoice: Sale = {
      id: 5,
      user_id: 1,
      customer_id: null,
      total_amount: 25,
      discount: 0,
      tax: 0,
      net_amount: 25,
      payment_method: 'cash',
      paid_amount: 25,
      due_amount: 0,
      items: [{
        product_id: 1,
        product_name: 'Bulk item',
        quantity: 1.25,
        unit_type: 'weight',
        unit_price: 20,
        subtotal: 25,
      }],
    }

    const html = buildReceiptInnerHTML(invoice)
    expect(html).toContain('<td><span class="quantity-value" dir="ltr">1.25 kg</span></td>')
  })

  it('renders invoice time with a 12-hour clock and Latin digits', () => {
    const invoice: Sale = {
      id: 4,
      user_id: 1,
      customer_id: null,
      total_amount: 10,
      discount: 0,
      tax: 0,
      net_amount: 10,
      payment_method: 'cash',
      paid_amount: 10,
      due_amount: 0,
      created_at: '2026-08-02 13:05:00',
      items: [],
    }

    const html = buildReceiptInnerHTML(invoice)
    const expectedTime = new Intl.DateTimeFormat('ar-EG-u-nu-latn', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
    }).format(new Date(invoice.created_at!))

    expect(html).toContain(expectedTime)
    expect(html).not.toContain('13:05')
  })
})
