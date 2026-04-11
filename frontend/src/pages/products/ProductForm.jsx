import { useState, useEffect, useRef, useMemo } from 'react'
import { Plus, X, Search, ChevronDown, Camera } from 'lucide-react'
import { formatNumber } from '../../utils/formatters'
import toast from 'react-hot-toast'

/* ── Barcode conflict helpers ── */

/** منتج يملك هذا الباركود (أساسي أو إضافي)، مع استثناء منتج أثناء التعديل */
export function findProductOwningBarcode(allProducts, barcode, excludeProductId) {
  const t = String(barcode).trim()
  if (!t) return null
  for (const p of allProducts) {
    if (excludeProductId != null && Number(p.id) === Number(excludeProductId)) continue
    if (String(p.barcode ?? '') === t) return p
    if (String(p.box_barcode ?? '') === t) return p
    if ((p.additional_barcodes || []).some((b) => String(b) === t)) return p
  }
  return null
}

export function getBarcodeRowConflict(barcodes, rowIndex, excludeProductId, allProducts, form) {
  const bc = rowIndex === 'box'
    ? String(form?.box_barcode ?? '').trim()
    : String(barcodes[rowIndex] ?? '').trim()
  if (!bc) return null
  const dupElsewhere = rowIndex === 'box'
    ? barcodes.some(b => String(b).trim() === bc)
    : barcodes.some((b, j) => j !== rowIndex && String(b).trim() === bc) || String(form?.box_barcode ?? '').trim() === bc
  if (dupElsewhere) {
    return {
      kind: 'duplicate',
      title: 'باركود مكرر في النموذج',
      line: 'هذا الباركود مُدخل في أكثر من حقل؛ احذف التكرار أو غيّر أحد القيمتين.',
    }
  }
  const owner = findProductOwningBarcode(allProducts, bc, excludeProductId)
  if (owner) {
    return {
      kind: 'taken',
      title: `مسجّل للمنتج: «${owner.name}»`,
      line: `هذا الباركود يخص المنتج «${owner.name}» بالفعل.`,
      productName: owner.name,
    }
  }
  return null
}

export function formatProductApiError(err) {
  const d = err?.response?.data
  if (!d) return 'حدث خطأ'
  if (typeof d.message === 'string' && d.message.trim()) return d.message
  const errors = d.errors
  if (errors && typeof errors === 'object') {
    const lines = []
    for (const [field, msgs] of Object.entries(errors)) {
      const arr = Array.isArray(msgs) ? msgs : [msgs]
      const label = field === 'name' ? 'اسم المنتج' : field === 'price' ? 'سعر البيع' : field
      arr.forEach((raw) => {
        const m = String(raw)
        if (m.includes('required')) lines.push(`${label} مطلوب`)
        else if (m.includes('numeric')) lines.push(`${label} يجب أن يكون رقماً`)
        else lines.push(`${label}: ${m}`)
      })
    }
    if (lines.length) return lines.join(' — ')
  }
  return 'حدث خطأ'
}

/* ── Label ── */
function Label({ children }) {
  return <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>{children}</label>
}

/* ── Category Combobox ── */
export function CategoryCombobox({ categories, value, onChange }) {
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const rootRef = useRef(null)

  useEffect(() => {
    const close = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [])

  useEffect(() => {
    if (!open) setQ('')
  }, [open])

  const filtered = useMemo(() => {
    const t = q.trim().toLowerCase()
    if (!t) return categories
    return categories.filter((c) => (c.name || '').toLowerCase().includes(t))
  }, [categories, q])

  const selected = categories.find((c) => String(c.id) === String(value))
  const displayLabel =
    value === '' || value == null ? 'بدون فئة' : (selected?.name ?? 'فئة غير معروفة')

  const pickNone = () => {
    onChange('')
    setOpen(false)
  }
  const pick = (id) => {
    onChange(String(id))
    setOpen(false)
  }

  return (
    <div ref={rootRef} style={{ position: 'relative' }}>
      <button
        type="button"
        className="input"
        onClick={() => setOpen((o) => !o)}
        style={{
          width: '100%',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: '0.5rem',
          cursor: 'pointer',
          textAlign: 'right',
          background: 'var(--surface)',
        }}
      >
        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{displayLabel}</span>
        <ChevronDown size={18} style={{ flexShrink: 0, opacity: 0.6, transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .15s' }} />
      </button>
      {open && (
        <div
          className="card"
          style={{
            position: 'absolute',
            zIndex: 50,
            left: 0,
            right: 0,
            top: 'calc(100% + 4px)',
            padding: '0.5rem',
            maxHeight: 'min(320px, 70vh)',
            display: 'flex',
            flexDirection: 'column',
            gap: '0.4rem',
            boxShadow: 'var(--shadow-lg, 0 12px 40px rgba(0,0,0,.12))',
          }}
          onMouseDown={(e) => e.preventDefault()}
        >
          <div style={{ position: 'relative' }}>
            <Search size={16} style={{ position: 'absolute', top: '50%', transform: 'translateY(-50%)', right: '0.65rem', color: 'var(--text-muted)', pointerEvents: 'none' }} />
            <input
              className="input"
              style={{ paddingRight: '2.2rem', fontSize: '0.88rem' }}
              placeholder="ابحث عن فئة…"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              autoFocus
            />
          </div>
          <div style={{ overflowY: 'auto', flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', gap: '0.15rem' }}>
            <button
              type="button"
              className={`btn btn-sm ${value === '' || value == null ? 'btn-primary' : 'btn-ghost'}`}
              style={{ justifyContent: 'flex-start', fontWeight: 600 }}
              onClick={pickNone}
            >
              بدون فئة
            </button>
            {filtered.length === 0 ? (
              <div style={{ padding: '0.75rem', textAlign: 'center', fontSize: '0.82rem', color: 'var(--text-muted)' }}>
                لا توجد فئات تطابق البحث
              </div>
            ) : (
              filtered.map((c) => (
                <button
                  key={c.id}
                  type="button"
                  className={`btn btn-sm ${String(value) === String(c.id) ? 'btn-primary' : 'btn-ghost'}`}
                  style={{ justifyContent: 'flex-start' }}
                  onClick={() => pick(c.id)}
                >
                  {c.name}
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  )
}

/* ── Product Form ── */
export default function ProductForm({ form, setForm, categories, modalKey, allProducts = [], editingProductId = null }) {
  const [barcodeCameraRow, setBarcodeCameraRow] = useState(null)
  const [BarcodeScannerLazy, setBarcodeScannerLazy] = useState(null)

  const openBarcodeCamera = async (target) => {
    try {
      if (!BarcodeScannerLazy) {
        const m = await import('../../components/BarcodeCameraScanner')
        setBarcodeScannerLazy(() => m.default)
      }
      setBarcodeCameraRow(target)
    } catch {
      toast.error('تعذر تحميل ماسح الباركود')
    }
  }
  const f = (k) => ({ value: form[k] ?? '', onChange: (e) => setForm((p) => ({ ...p, [k]: e.target.value })) })
  const barcodes = Array.isArray(form.barcodes) ? form.barcodes : [form.barcode || '']

  const setBarcodeAt = (i, v) => {
    setForm((p) => {
      const b = Array.isArray(p.barcodes) ? [...p.barcodes] : [p.barcode || '']
      b[i] = v
      return { ...p, barcodes: b }
    })
  }
  const addBarcodeRow = () =>
    setForm((p) => {
      const b = Array.isArray(p.barcodes) ? [...p.barcodes] : [p.barcode || '']
      return { ...p, barcodes: [...b, ''] }
    })
  const removeBarcodeRow = (i) => {
    if (i === 0) return
    setForm((p) => {
      const b = Array.isArray(p.barcodes) ? [...p.barcodes] : [p.barcode || '']
      const next = b.filter((_, j) => j !== i)
      return { ...p, barcodes: next.length ? next : [''] }
    })
  }

  /** عدد الصناديق المكافئة للمخزون (الكمية ÷ قطع/صندوق) */
  const stockBoxesHint = useMemo(() => {
    const rawQ = form.quantity
    const rawU = form.units_per_box
    const qty =
      rawQ === '' || rawQ === null || rawQ === undefined
        ? null
        : parseInt(String(rawQ).trim(), 10)
    const upb = Math.max(1, parseInt(String(rawU ?? '1').trim(), 10) || 1)

    if (qty === null || Number.isNaN(qty)) {
      return 'بعد إدخال «الكمية» أعلاه، يُعرض هنا كم صندوقًا يمثّلها المخزون.'
    }
    if (qty < 0) {
      return 'أدخل كمية صحيحة في حقل «الكمية».'
    }
    if (qty === 0) {
      return 'الكمية الحالية 0 — لا يوجد مخزون يعادل صناديق بعد.'
    }
    if (upb <= 1) {
      return `المخزون ${formatNumber(qty)} قطعة؛ صندوق البيع = قطعة واحدة (لا تجميع)، أي ${formatNumber(qty)} وحدة بيع بالصندوق.`
    }
    const full = Math.floor(qty / upb)
    const rem = qty % upb
    if (rem === 0) {
      return `مخزونك ${formatNumber(qty)} قطعة يعادل ${formatNumber(full)} صندوقًا كاملاً (${formatNumber(upb)} قطعة في كل صندوق).`
    }
    return `مخزونك ${formatNumber(qty)} قطعة يعادل ${formatNumber(full)} صندوقًا كاملاً + ${formatNumber(rem)} قطعة لا تكمل صندوقًا (${formatNumber(upb)} قطعة/صندوق).`
  }, [form.quantity, form.units_per_box])

  return (
    <>
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '0.75rem' }}>
      <div style={{ gridColumn: 'span 2' }}>
        <Label>اسم المنتج *</Label>
        <input className="input" {...f('name')} placeholder="مثال: أرز بسمتي 1كغ" required />
      </div>
      <div style={{ gridColumn: 'span 2' }}>
      <Label>الباركود</Label>
        <p style={{ fontSize: '0.78rem', color: 'var(--text-muted)', margin: '0 0 0.5rem' }}>
          اختياري — إذا تركته فارغًا سيُولد باركود تلقائيًا، والباركودات الإضافية اختيارية. على الهاتف يمكنك الضغط على أيقونة الكاميرا لمسح الباركود.
        </p>
        {barcodes.map((bc, idx) => {
          const conflict = getBarcodeRowConflict(barcodes, idx, editingProductId, allProducts, form)
          return (
            <div key={idx} style={{ marginBottom: '0.45rem' }}>
              <div style={{ display: 'flex', gap: '0.45rem', alignItems: 'flex-start' }}>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <input
                    className="input"
                    style={{
                      width: '100%',
                      borderColor: conflict ? '#dc2626' : undefined,
                      boxShadow: conflict ? '0 0 0 1px rgba(220, 38, 38, 0.35)' : undefined,
                    }}
                    value={bc}
                    onChange={(e) => setBarcodeAt(idx, e.target.value)}
                    placeholder={idx === 0 ? 'باركود المنتج (أو اتركه فارغًا لتوليد تلقائي)' : 'باركود إضافي'}
                    title={conflict ? conflict.title : undefined}
                    aria-invalid={conflict ? true : undefined}
                  />
                  {conflict && (
                    <div
                      className="barcode-conflict-hint"
                      style={{
                        fontSize: '0.72rem',
                        color: '#991b1b',
                        marginTop: '0.3rem',
                        lineHeight: 1.45,
                        padding: '0.35rem 0.5rem',
                        background: '#fee2e2',
                        border: '1px solid #fecaca',
                        borderRadius: '6px',
                      }}
                      title={conflict.line}
                    >
                      {conflict.line}
                    </div>
                  )}
                </div>
                <button
                  type="button"
                  className="btn btn-ghost btn-icon"
                  style={{ flexShrink: 0, marginTop: '0.15rem' }}
                  title="مسح الباركود بالكاميرا"
                  aria-label="مسح الباركود بالكاميرا"
                  onClick={() => openBarcodeCamera(idx)}
                >
                  <Camera size={18} />
                </button>
                {idx > 0 ? (
                  <button
                    type="button"
                    className="btn btn-ghost btn-icon"
                    style={{ flexShrink: 0, marginTop: '0.15rem' }}
                    title="حذف هذا الباركود"
                    onClick={() => removeBarcodeRow(idx)}
                  >
                    <X size={16} />
                  </button>
                ) : null}
              </div>
            </div>
          )
        })}
        <button type="button" className="btn btn-ghost btn-sm" onClick={addBarcodeRow} style={{ marginTop: '0.15rem' }}>
          <Plus size={14} style={{ marginLeft: '0.25rem' }} />
          إضافة باركود
        </button>
      </div>
      <div>
        <Label>سعر البيع *</Label>
        <input className="input" type="number" step="0.01" min="0" {...f('price')} placeholder="0.00" required />
      </div>
      <div>
        <Label>سعر التكلفة</Label>
        <input className="input" type="number" step="0.01" min="0" {...f('cost')} placeholder="0.00" />
      </div>
      <div>
        <Label>الكمية</Label>
        <input className="input" type="number" min="0" {...f('quantity')} placeholder="0" />
      </div>
      <div>
        <Label>حد التنبيه المنخفض</Label>
        <input className="input" type="number" min="0" {...f('low_stock_threshold')} placeholder="5" />
      </div>
      <div style={{ gridColumn: 'span 2', background: 'rgba(59,130,246,0.06)', border: '1px dashed var(--secondary)', borderRadius: 'var(--radius)', padding: '0.65rem 0.75rem' }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.75rem', marginBottom: '0.65rem' }}>
          <div>
            <Label>📦 عدد القطع في الصندوق</Label>
            <input
              className="input"
              type="number"
              min="1"
              step="1"
              {...f('units_per_box')}
              placeholder="1"
              style={{ width: '100%' }}
            />
          </div>
          <div>
            <Label>باركود الصندوق (اختياري)</Label>
            <div style={{ display: 'flex', gap: '0.45rem', alignItems: 'flex-start' }}>
              <div style={{ flex: 1, minWidth: 0 }}>
                <input
                  className="input"
                  {...f('box_barcode')}
                  placeholder="امسح باركود الكرتونة"
                  style={{
                    width: '100%',
                    borderColor: getBarcodeRowConflict(barcodes, 'box', editingProductId, allProducts, form) ? '#dc2626' : undefined,
                    boxShadow: getBarcodeRowConflict(barcodes, 'box', editingProductId, allProducts, form) ? '0 0 0 1px rgba(220, 38, 38, 0.35)' : undefined,
                  }}
                  title={getBarcodeRowConflict(barcodes, 'box', editingProductId, allProducts, form)?.title}
                />
              </div>
              <button
                type="button"
                className="btn btn-ghost btn-icon"
                onClick={() => openBarcodeCamera('box')}
                title="مسح بالكاميرا"
              >
                <Camera size={18} />
              </button>
            </div>
          </div>
        </div>
        <p style={{ fontSize: '0.76rem', color: 'var(--text-muted)', margin: '0 0 0.45rem' }}>
          عند مسح "باركود الصندوق" في نقطة البيع، سيتم إضافة كمية الصندوق المعرفة دفعةً واحدة إلى الفاتورة.
        </p>
        <div style={{ display: 'flex', gap: '0.65rem', alignItems: 'stretch', flexWrap: 'wrap' }}>
          <div
            style={{
              flex: 1,
              minWidth: '200px',
              fontSize: '0.76rem',
              fontWeight: 600,
              color: 'var(--secondary)',
              lineHeight: 1.5,
              padding: '0.45rem 0.55rem',
              background: 'rgba(59,130,246,0.1)',
              borderRadius: '6px',
              border: '1px solid rgba(59,130,246,0.22)',
              alignSelf: 'center',
            }}
          >
            {stockBoxesHint}
          </div>
        </div>
      </div>
      <div style={{ gridColumn: 'span 2' }}>
        <Label>الفئة</Label>
        <CategoryCombobox
          key={modalKey}
          categories={categories}
          value={form.category_id ?? ''}
          onChange={(id) => setForm((p) => ({ ...p, category_id: id }))}
        />
      </div>
    </div>

    {BarcodeScannerLazy && barcodeCameraRow !== null && (
      <BarcodeScannerLazy
        onResult={(text) => {
          if (barcodeCameraRow === 'box') {
            setForm((p) => ({ ...p, box_barcode: text }))
          } else {
            setBarcodeAt(barcodeCameraRow, text)
          }
          setBarcodeCameraRow(null)
          toast.success('تمت قراءة الباركود')
        }}
        onClose={() => setBarcodeCameraRow(null)}
      />
    )}
    </>
  )
}
