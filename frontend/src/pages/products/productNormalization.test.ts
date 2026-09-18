import { describe, expect, it } from 'vitest'
import { emptyProduct, normalizeProductPayload, type ProductFormState } from './productNormalization'

describe('productNormalization', () => {
  it('normalizes empty quantity to numeric zero', () => {
    const form: ProductFormState = {
      ...emptyProduct,
      name: 'PROD_EMPTY_QTY',
      price: '100',
      cost: '50',
      quantity: '',
    }
    const payload = normalizeProductPayload(form)
    expect(payload.quantity).toBe(0)
    expect(payload.cost).toBe(50)
  })

  it('normalizes empty cost to numeric zero', () => {
    const form: ProductFormState = {
      ...emptyProduct,
      name: 'PROD_EMPTY_COST',
      price: '100',
      cost: '',
      quantity: '10',
    }
    const payload = normalizeProductPayload(form)
    expect(payload.cost).toBe(0)
    expect(payload.quantity).toBe(10)
  })

  it('normalizes both empty quantity and empty cost to numeric zero', () => {
    const form: ProductFormState = {
      ...emptyProduct,
      name: 'BASELINE_FIRST_RUN',
      price: '100',
      cost: '',
      quantity: '',
      low_stock_threshold: '',
    }
    const payload = normalizeProductPayload(form)
    expect(payload.quantity).toBe(0)
    expect(payload.cost).toBe(0)
    expect(payload.low_stock_threshold).toBe(5)
  })

  it('preserves explicit numeric zero for quantity, cost, and threshold', () => {
    const form: ProductFormState = {
      ...emptyProduct,
      name: 'PROD_ZERO',
      price: '100',
      cost: 0,
      quantity: '0',
      low_stock_threshold: 0,
    }
    const payload = normalizeProductPayload(form)
    expect(payload.quantity).toBe(0)
    expect(payload.cost).toBe(0)
    expect(payload.low_stock_threshold).toBe(0)
  })

  it('preserves explicit nonzero numbers and decimal values', () => {
    const form: ProductFormState = {
      ...emptyProduct,
      name: 'PROD_NONZERO',
      price: '150.75',
      cost: '99.50',
      quantity: '15.25',
      low_stock_threshold: '10',
    }
    const payload = normalizeProductPayload(form)
    expect(payload.price).toBe('150.75')
    expect(payload.cost).toBe(99.5)
    expect(payload.quantity).toBe(15.25)
    expect(payload.low_stock_threshold).toBe(10)
  })

  it('normalizes nested sizes array numeric fields', () => {
    const form: ProductFormState = {
      ...emptyProduct,
      name: 'PROD_SIZES',
      price: '100',
      sizes: [
        {
          id: 1,
          name: 'Size L',
          barcode: '111',
          category_id: null,
          price: '' as any,
          cost: '' as any,
          quantity: '' as any,
          low_stock_threshold: '' as any,
        },
      ],
    }
    const payload = normalizeProductPayload(form)
    expect(payload.sizes[0].price).toBe(0)
    expect(payload.sizes[0].cost).toBe(0)
    expect(payload.sizes[0].quantity).toBe(0)
    expect(payload.sizes[0].low_stock_threshold).toBe(5)
  })
})
