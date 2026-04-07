import { create } from 'zustand'

const useCartStore = create((set, get) => ({
  items: [],
  discount: 0,
  paymentMethod: 'cash',
  amountPaid: 0,

  addItem: (product) => {
    const items = get().items
    // Always store price as a proper float to avoid NaN from API strings
    const price = parseFloat(product.price) || 0
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
        items: [...items, { ...product, price, quantity: 1, subtotal: price }],
      })
    }
  },

  removeItem: (id) => set({ items: get().items.filter((i) => i.id !== id) }),

  updateQuantity: (id, qty) => {
    if (qty <= 0) {
      set({ items: get().items.filter((i) => i.id !== id) })
      return
    }
    set({
      items: get().items.map((i) =>
        i.id === id ? { ...i, quantity: qty, subtotal: qty * parseFloat(i.price) } : i
      ),
    })
  },

  setDiscount: (discount) => set({ discount: parseFloat(discount) || 0 }),
  setPaymentMethod: (method) => set({ paymentMethod: method }),
  setAmountPaid: (amount) => set({ amountPaid: parseFloat(amount) || 0 }),

  clearCart: () => set({ items: [], discount: 0, amountPaid: 0, paymentMethod: 'cash' }),

  // Computed subtotal only — tax is read from settingsStore in components
  get subtotal() {
    return get().items.reduce((s, i) => s + (parseFloat(i.subtotal) || 0), 0)
  },
  get itemCount() {
    return get().items.reduce((s, i) => s + i.quantity, 0)
  },
}))

export default useCartStore
