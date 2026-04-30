import { create } from 'zustand'

export interface CartItem {
  id: number;
  name: string;
  barcode?: string;
  price: number;
  quantity: number;
  subtotal: number;
  units_per_box?: number;
  sell_by_weight?: number;
  scanned_as_box?: boolean;
}

interface CartState {
  items: CartItem[];
  discount: number;
  paymentMethod: string;
  amountPaid: number;
  rebillingInvoiceId: number | null;
  rebillingCustomerId: number | null;
  rebillingAmountPaid: number;
  subtotal: number;
  itemCount: number;

  addItem: (product: any) => void;
  removeItem: (id: number) => void;
  updateQuantity: (id: number, qty: number) => void;
  updatePrice: (id: number, newPrice: number) => void;
  setDiscount: (discount: number) => void;
  setPaymentMethod: (method: string) => void;
  setAmountPaid: (amount: number) => void;
  clearCart: () => void;
  mergeInvoiceLines: (lines: any[], invoiceId?: number | null, customerId?: number | null, amountPaid?: number) => void;
}

const useCartStore = create<CartState>((set, get) => ({
  items: [],
  discount: 0,
  paymentMethod: 'cash',
  amountPaid: 0,
  rebillingInvoiceId: null,
  rebillingCustomerId: null,
  rebillingAmountPaid: 0,

  addItem: (product: { id: number; name: string; barcode?: string; price: string | number; units_per_box?: string | number; sell_by_weight?: string | number; scanned_as_box?: boolean; [key: string]: unknown }) => {
    const items = get().items
    const price = parseFloat(String(product.price)) || 0
    const unitsPerBox = Math.max(1, parseInt(String(product.units_per_box)) || 1)
    const isByWeight = parseInt(String(product.sell_by_weight)) === 1
    
    const qtyToAdd = product.scanned_as_box ? unitsPerBox : (isByWeight ? 1 : 1)

    const existing = items.find((i) => i.id === product.id)
    if (existing) {
      set({
        items: items.map((i) =>
          i.id === product.id
            ? { ...i, quantity: i.quantity + qtyToAdd, subtotal: (i.quantity + qtyToAdd) * i.price }
            : i
        ),
      })
    } else {
      set({
        items: [...items, { ...product, price, quantity: qtyToAdd, subtotal: price * qtyToAdd, units_per_box: unitsPerBox, sell_by_weight: isByWeight ? 1 : 0 }],
      })
    }
  },

  removeItem: (id: number) =>
    set((state) => {
      const items = state.items.filter((i) => i.id !== id)
      return { items, rebillingInvoiceId: items.length === 0 ? null : state.rebillingInvoiceId }
    }),

  updateQuantity: (id: number, qty: number) => {
    set((state) => {
      let items
      if (qty <= 0) {
        items = state.items.filter((i) => i.id !== id)
      } else {
        items = state.items.map((i) =>
          i.id === id ? { ...i, quantity: parseFloat(qty.toString()) || 0, subtotal: (parseFloat(qty.toString()) || 0) * i.price } : i
        )
      }
      return { items, rebillingInvoiceId: items.length === 0 ? null : state.rebillingInvoiceId }
    })
  },

  updatePrice: (id: number, newPrice: number) => {
    set((state) => {
      const price = parseFloat(newPrice.toString()) || 0;
      const items = state.items.map((i) =>
        i.id === id ? { ...i, price, subtotal: i.quantity * price } : i
      )
      return { items }
    })
  },

  setDiscount: (discount: number) => set({ discount: parseFloat(discount.toString()) || 0 }),
  setPaymentMethod: (method: string) => set({ paymentMethod: method }),
  setAmountPaid: (amount: number) => set({ amountPaid: parseFloat(amount.toString()) || 0 }),

  clearCart: () =>
    set({ items: [], discount: 0, amountPaid: 0, paymentMethod: 'cash', rebillingInvoiceId: null, rebillingCustomerId: null, rebillingAmountPaid: 0 }),

  mergeInvoiceLines: (lines: Array<{ product_id?: number; price?: string | number; quantity?: string | number; product_name?: string; name?: string; barcode?: string }>, invoiceId: number | null = null, customerId: number | null = null, amountPaid: number = 0) => {
    if (!lines?.length) return
    set((state) => {
      let items = [...state.items]
      for (const line of lines) {
        const id = Number(line.product_id)
        const price = parseFloat(String(line.price)) || 0
        const qty = parseFloat(String(line.quantity)) || 0
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
      const rid = invoiceId != null ? Number(invoiceId) : null
      const cid = customerId != null ? Number(customerId) : null
      return {
        items,
        rebillingInvoiceId: Number.isFinite(rid) && (rid as number) > 0 ? rid : state.rebillingInvoiceId,
        rebillingCustomerId: Number.isFinite(cid) && (cid as number) > 0 ? cid : state.rebillingCustomerId,
        rebillingAmountPaid: amountPaid > 0 ? amountPaid : state.rebillingAmountPaid,
      }
    })
  },

  get subtotal() {
    return get().items.reduce((s: number, i: CartItem) => s + (parseFloat(i.subtotal.toString()) || 0), 0)
  },
  get itemCount() {
    return get().items.reduce((s: number, i: CartItem) => s + i.quantity, 0)
  },
}))

export default useCartStore
