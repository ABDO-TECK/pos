import { create } from 'zustand'

const useCartStore = create((set, get) => ({
  items: [],
  discount: 0,
  paymentMethod: 'cash',
  amountPaid: 0,
  /** When set, checkout updates this invoice instead of creating a new one (مرتجع / إعادة فوترة) */
  rebillingInvoiceId: null,

  addItem: (product) => {
    const items = get().items
    // Always store price as a proper float to avoid NaN from API strings
    const price = parseFloat(product.price) || 0
    const unitsPerBox = Math.max(1, parseInt(product.units_per_box) || 1)
    const existing = items.find((i) => i.id === product.id)
    if (existing) {
      set({
        items: items.map((i) =>
          i.id === product.id
            ? { ...i, quantity: i.quantity + 1, subtotal: (i.quantity + 1) * i.price }
            : i
        ),
      })
    } else {
      set({
        items: [...items, { ...product, price, quantity: 1, subtotal: price, units_per_box: unitsPerBox }],
      })
    }
  },

  removeItem: (id) =>
    set((state) => {
      const items = state.items.filter((i) => i.id !== id)
      return { items, rebillingInvoiceId: items.length === 0 ? null : state.rebillingInvoiceId }
    }),

  updateQuantity: (id, qty) => {
    set((state) => {
      let items
      if (qty <= 0) {
        items = state.items.filter((i) => i.id !== id)
      } else {
        items = state.items.map((i) =>
          i.id === id ? { ...i, quantity: qty, subtotal: qty * parseFloat(i.price) } : i
        )
      }
      return { items, rebillingInvoiceId: items.length === 0 ? null : state.rebillingInvoiceId }
    })
  },

  setDiscount: (discount) => set({ discount: parseFloat(discount) || 0 }),
  setPaymentMethod: (method) => set({ paymentMethod: method }),
  setAmountPaid: (amount) => set({ amountPaid: parseFloat(amount) || 0 }),

  clearCart: () =>
    set({ items: [], discount: 0, amountPaid: 0, paymentMethod: 'cash', rebillingInvoiceId: null }),

  /** Merge invoice lines into cart; `invoiceId` links checkout to that invoice (same رقم فاتورة). */
  mergeInvoiceLines: (lines, invoiceId = null) => {
    if (!lines?.length) return
    set((state) => {
      let items = [...state.items]
      for (const line of lines) {
        const id = Number(line.product_id)
        const price = parseFloat(line.price) || 0
        const qty = parseInt(line.quantity, 10) || 0
        if (qty <= 0 || !id) continue
        const name = line.product_name ?? line.name ?? 'منتج'
        const barcode = line.barcode ?? ''
        const idx = items.findIndex((i) => i.id === id)
        if (idx >= 0) {
          const i = items[idx]
          const nq = i.quantity + qty
          items[idx] = { ...i, name, barcode, price, quantity: nq, subtotal: nq * price }
        } else {
          items.push({
            id,
            name,
            barcode,
            price,
            quantity: qty,
            subtotal: price * qty,
          })
        }
      }
      const rid = invoiceId != null && invoiceId !== '' ? Number(invoiceId) : null
      return {
        items,
        rebillingInvoiceId: Number.isFinite(rid) && rid > 0 ? rid : state.rebillingInvoiceId,
      }
    })
  },

  // Computed subtotal only — tax is read from settingsStore in components
  get subtotal() {
    return get().items.reduce((s, i) => s + (parseFloat(i.subtotal) || 0), 0)
  },
  get itemCount() {
    return get().items.reduce((s, i) => s + i.quantity, 0)
  },
}))

export default useCartStore
