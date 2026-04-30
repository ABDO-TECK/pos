import React from 'react'
import { ShoppingCart, Trash2 } from 'lucide-react'
import { formatCurrency, formatNumber, formatPercent } from '../../utils/formatters'
import toast from 'react-hot-toast'

export function CartHeader({ items, clearCart, itemCount }: any) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontWeight: 700 }}>
        <ShoppingCart size={18} />
        السلة
        {itemCount > 0 && <span className="badge badge-green">{formatNumber(itemCount)}</span>}
      </div>
      {items.length > 0 && (
        <button
          className="btn btn-ghost btn-sm"
          onClick={() => { clearCart(); toast('تم مسح السلة') }}
          style={{ color: 'var(--danger)' }}
        >
          <Trash2 size={14} /> مسح
        </button>
      )}
    </div>
  )
}

export function TotalRow({ label, value, bold, green, muted }: { label: React.ReactNode, value: React.ReactNode, bold?: boolean, green?: boolean, muted?: boolean }) {
  return (
    <div style={{
      display: 'flex', justifyContent: 'space-between',
      fontWeight: bold ? 700 : 400,
      fontSize: bold ? '1rem' : '0.88rem',
      color: muted ? 'var(--text-muted)' : green ? 'var(--primary)' : 'var(--text)',
    }}>
      <span>{label}</span>
      <span>{value}</span>
    </div>
  )
}

export function CartTotals({ items, subtotal, tax, total, taxEnabled, taxRate }: any) {
  if (!items.length) return null
  return (
    <div style={{ borderTop: '1px solid var(--border)', paddingTop: '0.75rem', display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
      <TotalRow label="المجموع" value={formatCurrency(subtotal)} />
      {taxEnabled && (
        <TotalRow label={`الضريبة ${formatPercent(taxRate)}`} value={formatCurrency(tax)} muted />
      )}
      <TotalRow label="الإجمالي" value={formatCurrency(total)} bold green />
    </div>
  )
}

export function ProductCard({ product, onAdd }: any) {
  const isOutOfStock = product.quantity <= 0
  const isLowStock   = product.quantity <= product.low_stock_threshold && product.quantity > 0
  const upb          = parseInt(product.units_per_box, 10) || 1

  return (
    <button
      type="button"
      onMouseDown={(e) => e.preventDefault()}
      onClick={onAdd}
      style={{
        background: 'var(--surface)',
        border: `1px solid ${isLowStock ? 'var(--warning)' : isOutOfStock ? '#fca5a5' : 'var(--border)'}`,
        borderRadius: 'var(--radius)',
        padding: '0.5rem 0.6rem',
        cursor: 'pointer',
        textAlign: 'right',
        transition: 'transform .1s, box-shadow .1s',
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'space-between',
        minHeight: '92px',
        touchAction: 'manipulation',
      }}
    >
      <div style={{
        fontSize: '0.78rem', fontWeight: 600, lineHeight: 1.3,
        wordBreak: 'break-word', overflowWrap: 'anywhere',
        color: 'var(--text)',
        marginBottom: '0.2rem',
      }}>
        {product.name}
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', marginTop: 'auto', gap: '0.3rem', width: '100%' }}>
        <div style={{ display: 'flex', gap: '0.2rem', alignItems: 'center', flexWrap: 'wrap' }}>
          {parseInt(product.sell_by_weight) === 1 && (
            <span className="badge badge-green" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>⚖️ وزن</span>
          )}
          {upb > 1 && parseInt(product.sell_by_weight) !== 1 && (
            <span className="badge badge-blue" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }} title={`صندوق: ${formatNumber(upb)} قطعة`}>
              📦 {formatNumber(upb)}
            </span>
          )}
          {isOutOfStock && <span className="badge badge-red" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>نفد</span>}
          {isLowStock   && <span className="badge badge-yellow" style={{ fontSize: '0.6rem', padding: '0.1rem 0.35rem' }}>منخفض</span>}
        </div>
        <span style={{ fontSize: '0.88rem', fontWeight: 700, color: 'var(--primary)' }}>
          {formatCurrency(product.price)}
        </span>
      </div>
    </button>
  )
}
