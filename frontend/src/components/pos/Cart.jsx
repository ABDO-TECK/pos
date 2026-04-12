import { useState } from 'react'
import { Trash2, Plus, Minus, ShoppingCart, Package } from 'lucide-react'
import useCartStore from '../../store/cartStore'
import { formatCurrency, formatNumber } from '../../utils/formatters'

export default function Cart() {
  const { items, removeItem, updateQuantity, updatePrice, rebillingInvoiceId } = useCartStore()

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
        <CartItem
          key={item.id}
          item={item}
          onRemove={() => removeItem(item.id)}
          onUpdateQty={(qty) => updateQuantity(item.id, qty)}
          onUpdatePrice={(price) => updatePrice(item.id, price)}
        />
      ))}
    </div>
  )
}

// ── CartItem ────────────────────────────────────────────────────────────────

function CartItem({ item, onRemove, onUpdateQty, onUpdatePrice }) {
  const unitsPerBox = Math.max(1, parseInt(item.units_per_box) || 1)
  const hasBox      = unitsPerBox > 1

  // unitMode: 'piece' | 'box'  — local UI state only, doesn't affect cart store
  const [unitMode, setUnitMode] = useState(item.scanned_as_box && hasBox ? 'box' : 'piece')

  // عدد الصناديق المعروض (يُحسب من الكمية الكلية)
  const boxCount   = unitMode === 'box' ? Math.max(1, Math.round(item.quantity / unitsPerBox)) : null
  const displayQty = unitMode === 'box' ? boxCount : item.quantity

  const handleUnitModeChange = (mode) => {
    if (mode === unitMode) return
    setUnitMode(mode)
    // عند التبديل: نُعيد الكمية إلى 1 من الوحدة الجديدة
    // قطعة → 1 قطعة | صندوق → 1 صندوق (= unitsPerBox قطعة)
    if (mode === 'piece') {
      onUpdateQty(1)
    } else {
      onUpdateQty(unitsPerBox)
    }
  }

  const handleDecrement = () => {
    if (unitMode === 'box') {
      const newBoxes = Math.max(1, boxCount - 1)
      onUpdateQty(newBoxes * unitsPerBox)
    } else {
      onUpdateQty(item.quantity - 1)
    }
  }

  const handleIncrement = () => {
    if (unitMode === 'box') {
      onUpdateQty((boxCount + 1) * unitsPerBox)
    } else {
      onUpdateQty(item.quantity + 1)
    }
  }

  const handleInputChange = (raw) => {
    const val = parseInt(raw) || 1
    if (unitMode === 'box') {
      onUpdateQty(Math.max(1, val) * unitsPerBox)
    } else {
      onUpdateQty(Math.max(1, val))
    }
  }

  return (
    <div
      style={{
        padding: '0.6rem 0.75rem',
        background: 'var(--surface)',
        borderRadius: '0.4rem',
        border: '1px solid var(--border)',
      }}
    >
      {/* الصف الأول: اسم + سعر + زر حذف */}
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: '0.5rem' }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div
            style={{
              fontWeight: 600,
              fontSize: '0.88rem',
              lineHeight: 1.4,
              wordBreak: 'break-word',
              overflowWrap: 'anywhere',
            }}
          >
            {item.name}
          </div>
          <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)' }}>{item.barcode}</div>
          <div style={{ fontSize: '0.85rem', color: 'var(--primary)', fontWeight: 700 }}>
            {formatCurrency(item.subtotal)}
          </div>
        </div>

        {/* Unit price (Editable) */}
        <div style={{ minWidth: '60px', textAlign: 'left', flexShrink: 0, marginTop: '0.1rem' }}>
          <input
            type="number"
            min="0"
            step="0.01"
            value={item.price}
            onChange={(e) => onUpdatePrice(e.target.value)}
            style={{
              width: '4.5rem',
              fontSize: '0.8rem',
              padding: '0.2rem 0.1rem',
              border: '1px solid var(--border)',
              borderRadius: '0.3rem',
              textAlign: 'center',
              color: 'var(--text-muted)',
              background: 'var(--surface)'
            }}
            title="تعديل السعر لهذه الفاتورة فقط"
          />
        </div>

        {/* Delete */}
        <button
          className="btn btn-icon"
          style={{ padding: '0.3rem', color: 'var(--danger)', background: 'transparent', border: 'none', flexShrink: 0, marginTop: '0.1rem' }}
          onClick={onRemove}
        >
          <Trash2 size={16} />
        </button>
      </div>

      {/* الصف الثاني: ضوابط الكمية + القائمة المنسدلة */}
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', marginTop: '0.45rem' }}>

        {/* قائمة منسدلة: قطعة / صندوق — تظهر فقط إذا كان للمنتج صندوق */}
        {hasBox && (
          <select
            value={unitMode}
            onChange={(e) => handleUnitModeChange(e.target.value)}
            style={{
              fontSize: '0.75rem',
              fontWeight: 600,
              padding: '0.22rem 0.35rem',
              border: `1px solid ${unitMode === 'box' ? 'var(--secondary)' : 'var(--border)'}`,
              borderRadius: '0.3rem',
              background: unitMode === 'box' ? 'rgba(59,130,246,0.08)' : 'var(--surface)',
              color: unitMode === 'box' ? 'var(--secondary)' : 'var(--text)',
              cursor: 'pointer',
              flexShrink: 0,
              fontFamily: 'inherit',
            }}
          >
            <option value="piece">قطعة</option>
            <option value="box">صندوق ({unitsPerBox})</option>
          </select>
        )}

        {/* أزرار الكمية */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.3rem', flex: 1 }}>
          <button
            className="btn btn-ghost btn-icon"
            style={{ padding: '0.3rem', borderRadius: '0.3rem' }}
            onClick={handleDecrement}
          >
            <Minus size={14} />
          </button>
          <input
            type="number"
            min={1}
            value={displayQty}
            onChange={(e) => handleInputChange(e.target.value)}
            style={{
              width: '3rem', textAlign: 'center', border: '1px solid var(--border)',
              borderRadius: '0.3rem', padding: '0.25rem 0.2rem', fontSize: '0.9rem',
            }}
          />
          <button
            className="btn btn-ghost btn-icon"
            style={{ padding: '0.3rem', borderRadius: '0.3rem' }}
            onClick={handleIncrement}
          >
            <Plus size={14} />
          </button>
        </div>

        {/* وسم الوحدة النشطة */}
        <span style={{
          fontSize: '0.72rem',
          color: unitMode === 'box' ? 'var(--secondary)' : 'var(--text-muted)',
          fontWeight: 600,
          flexShrink: 0,
          display: 'flex',
          alignItems: 'center',
          gap: '0.2rem',
        }}>
          {unitMode === 'box'
            ? <><Package size={11} /> {formatNumber(item.quantity)} قطعة</>
            : null
          }
        </span>
      </div>
    </div>
  )
}
