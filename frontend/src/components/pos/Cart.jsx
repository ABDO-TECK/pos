import { Trash2, Plus, Minus, ShoppingCart } from 'lucide-react'
import useCartStore from '../../store/cartStore'
import { formatCurrency, formatNumber } from '../../utils/formatters'

export default function Cart() {
  const { items, removeItem, updateQuantity, rebillingInvoiceId } = useCartStore()

  if (items.length === 0) {
    return (
      <div className="empty-state" style={{ height: '100%' }}>
        <ShoppingCart size={48} color="var(--border)" />
        <p>السلة فارغة</p>
        <small>امسح باركود منتج للبدء</small>
      </div>
    )
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem', overflowY: 'auto', flex: 1 }}>
      {rebillingInvoiceId != null && rebillingInvoiceId > 0 && (
        <div
          style={{
            fontSize: '0.78rem',
            fontWeight: 600,
            color: '#92400e',
            background: '#fef9c3',
            border: '1px solid #fbbf24',
            borderRadius: 'var(--radius)',
            padding: '0.45rem 0.6rem',
            textAlign: 'center',
          }}
        >
          تعديل الفاتورة #{formatNumber(rebillingInvoiceId)} — عند الدفع يُحفظ على نفس رقم الفاتورة
        </div>
      )}
      {items.map((item) => (
        <div
          key={item.id}
          style={{
            display: 'flex', alignItems: 'center', gap: '0.5rem',
            padding: '0.6rem 0.75rem', background: 'var(--surface)',
            borderRadius: '0.4rem', border: '1px solid var(--border)',
          }}
        >
          {/* Product info */}
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontWeight: 600, fontSize: '0.88rem', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
              {item.name}
            </div>
            <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)' }}>{item.barcode}</div>
            <div style={{ fontSize: '0.85rem', color: 'var(--primary)', fontWeight: 700 }}>
              {formatCurrency(item.subtotal)}
            </div>
          </div>

          {/* Qty controls */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.3rem', flexShrink: 0 }}>
            <button
              className="btn btn-ghost btn-icon"
              style={{ padding: '0.3rem', borderRadius: '0.3rem' }}
              onClick={() => updateQuantity(item.id, item.quantity - 1)}
            >
              <Minus size={14} />
            </button>
            <input
              type="number"
              min={1}
              max={item.quantity_in_stock || 9999}
              value={item.quantity}
              onChange={(e) => updateQuantity(item.id, parseInt(e.target.value) || 1)}
              style={{
                width: '3rem', textAlign: 'center', border: '1px solid var(--border)',
                borderRadius: '0.3rem', padding: '0.25rem 0.2rem', fontSize: '0.9rem',
              }}
            />
            <button
              className="btn btn-ghost btn-icon"
              style={{ padding: '0.3rem', borderRadius: '0.3rem' }}
              onClick={() => updateQuantity(item.id, item.quantity + 1)}
            >
              <Plus size={14} />
            </button>
          </div>

          {/* Unit price */}
          <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)', minWidth: '55px', textAlign: 'left', flexShrink: 0 }}>
            {formatCurrency(item.price)}
          </div>

          {/* Delete */}
          <button
            className="btn btn-icon"
            style={{ padding: '0.3rem', color: 'var(--danger)', background: 'transparent', border: 'none', flexShrink: 0 }}
            onClick={() => removeItem(item.id)}
          >
            <Trash2 size={16} />
          </button>
        </div>
      ))}
    </div>
  )
}
