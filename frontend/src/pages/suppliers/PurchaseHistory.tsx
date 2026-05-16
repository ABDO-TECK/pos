// @ts-nocheck
import { useState, useEffect, useRef } from 'react'
import { Trash2, ShoppingCart, X, Eye, Printer } from 'lucide-react'
import useAuthStore from '../../store/authStore'
import useSettingsStore from '../../store/settingsStore'
import { browserPrintPurchase, buildPurchaseReceiptHTML } from '../../utils/receiptBuilder'
import useQZPrinter from '../../hooks/useQZPrinter'
import { QZPrinterPicker, QZPrintButton } from '../../components/QZPrinterUI'
import Pagination from '../../components/Pagination'
import toast from 'react-hot-toast'
import {
  getSuppliers, getPurchaseInvoices, getPurchaseInvoice, deletePurchaseInvoice,
} from '../../api/endpoints'
import { formatCurrency, formatNumber, formatDate } from '../../utils/formatters'
import { useConfirmStore } from '../../store/confirmStore'
import { extractApiError } from '../../utils/apiError'

const labelSt = {
  display: 'block',
  fontSize: '0.8rem',
  fontWeight: 600,
  color: 'var(--text-muted)',
  marginBottom: '0.3rem',
}

function InfoCard({ label, value }) {
  return (
    <div style={{ background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '0.6rem 0.8rem' }}>
      <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginBottom: '0.15rem' }}>{label}</p>
      <p style={{ fontWeight: 600 }}>{value}</p>
    </div>
  )
}

function TotalRow({ label, value, bold, green, danger }: { label: React.ReactNode, value: React.ReactNode, bold?: boolean, green?: boolean, danger?: boolean }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between' }}>
      <span style={{ color: 'var(--text-muted)' }}>{label}</span>
      <span style={{
        fontWeight: bold ? 700 : 500,
        color: green ? 'var(--primary-d)' : danger ? 'var(--danger)' : 'var(--text)',
        fontSize: bold ? '1rem' : undefined,
      }}>{value}</span>
    </div>
  )
}

export default function PurchaseHistory({ onReturnToCart }) {
  const [invoices, setInvoices]       = useState<any[]>([])
  const [loading, setLoading]         = useState(false)
  const [selected, setSelected]       = useState<any>(null)
  const [detailLoading, setDL]        = useState(false)
  const [deleting, setDeleting]       = useState(false)
  const [filterSupplier, setSupplier] = useState('')
  const [filters, setFilters]         = useState({ date: '', month: '', year: '', search: '' })
  const [currentPage, setCurrentPage] = useState(1)
  const [totalPages, setTotalPages]   = useState(1)
  const [suppliers, setSuppliers]     = useState<any[]>([])
  const searchTimer                   = useRef<any>(null)
  const user                          = useAuthStore((s) => s.user)
  const isAdmin                       = user?.role === 'admin'
  const settings                      = useSettingsStore()
  const { confirm }                   = useConfirmStore()
  const qz = useQZPrinter()

  useEffect(() => {
    getSuppliers().then(r => { const d = (r.data as any).data; setSuppliers(Array.isArray(d) ? d : (d?.data ?? [])) }).catch(console.error)
  }, [])

  const load = async (f = filters, supId = filterSupplier, p = 1) => {
    setLoading(true)
    try {
      const params: Record<string, string | number> = { page: p, limit: 15 }
      if (f.date)   params.date   = f.date
      if (f.month)  params.month  = f.month
      if (f.year)   params.year   = f.year
      if (f.search) params.search = f.search
      if (supId)    params.supplier_id = supId
      const res = await getPurchaseInvoices(params)
      setInvoices((res.data.data as any[]) ?? [])
      
      const pg = res.data.pagination
      if (pg) {
        setTotalPages(pg.pages || 1)
        setCurrentPage(pg.page || 1)
      } else {
        setTotalPages(1)
        setCurrentPage(1)
      }
    } catch (err) { toast.error(extractApiError(err, 'فشل تحميل فواتير المشتريات')) } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  const handleFilter = (key, val) => {
    const next = { ...filters, [key]: val }
    setFilters(next)
    setCurrentPage(1)
    clearTimeout(searchTimer.current)
    searchTimer.current = setTimeout(() => load(next, filterSupplier, 1), 400)
  }

  const handleSupplierFilter = (val) => {
    setSupplier(val)
    setCurrentPage(1)
    clearTimeout(searchTimer.current)
    searchTimer.current = setTimeout(() => load(filters, val, 1), 400)
  }

  const clearFilters = () => {
    const cleared = { date: '', month: '', year: '', search: '' }
    setFilters(cleared)
    setSupplier('')
    setCurrentPage(1)
    load(cleared, '', 1)
  }

  const openDetail = async (id) => {
    setDL(true)
    try {
      const res = await getPurchaseInvoice(id)
      setSelected(res.data.data)
    } catch (err) { toast.error(extractApiError(err, 'فشل تحميل تفاصيل فاتورة المشتريات')) } finally {
      setDL(false)
    }
  }

  const handleDeleteInvoice = async () => {
    if (!selected?.id) return
    if (!(await confirm('سيتم حذف فاتورة المشتريات نهائياً ورجوع مخزون المنتجات إلى ما قبل الشراء. هل أنت متأكد؟'))) return
    setDeleting(true)
    try {
      await deletePurchaseInvoice(selected.id)
      toast.success('تم حذف الفاتورة واسترجاع المخزون')
      setSelected(null)
      load(filters, filterSupplier, currentPage)
    } catch (err) {
      toast.error(err.response?.data?.message ?? 'فشل حذف الفاتورة')
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      {/* Filters */}
      <div className="card" style={{ padding: '1rem' }}>
        <div className="filter-bar">
          <div className="form-group" style={{ flex: '1 1 220px' }}>
            <label style={labelSt}>بحث</label>
            <input
              type="text"
              className="input"
              placeholder="رقم فاتورة أو اسم منتج…"
              value={filters.search}
              onChange={e => handleFilter('search', e.target.value)}
            />
          </div>
          <div className="form-group">
            <label style={labelSt}>المورد</label>
            <select className="input" value={filterSupplier} onChange={e => handleSupplierFilter(e.target.value)}>
              <option value="">الكل</option>
              {suppliers.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </div>
          <div className="form-group">
            <label style={labelSt}>تاريخ محدد</label>
            <input type="date" className="input" value={filters.date} onChange={e => handleFilter('date', e.target.value)} />
          </div>
          <button onClick={clearFilters} className="btn btn-ghost" style={{ alignSelf: 'flex-end', height: '42px', padding: '0 1.5rem' }}>مسح الفلاتر</button>
        </div>
      </div>

      {/* Table */}
      <div className="card">
        {loading ? (
          <div style={{ padding: '3rem', textAlign: 'center' }}><span className="spinner" /></div>
        ) : invoices.length === 0 ? (
          <div className="empty-state">لا توجد مشتريات</div>
        ) : (
          <div className="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th># الفاتورة</th>
                  <th>المورد</th>
                  <th>إجمالي التكلفة</th>
                  <th>عدد الأصناف</th>
                  <th>التاريخ</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {invoices.map(inv => (
                  <tr key={inv.id}>
                    <td style={{ color: 'var(--text-muted)' }}>#{formatNumber(inv.id)}</td>
                    <td>{inv.supplier_name}</td>
                    <td style={{ fontWeight: 700, color: 'var(--primary-d)' }}>{formatCurrency(inv.total)}</td>
                    <td>{formatNumber(inv.items_count)}</td>
                    <td style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>{formatDate(inv.created_at)}</td>
                    <td>
                      <button className="btn btn-ghost btn-sm" onClick={() => openDetail(inv.id)} style={{ gap: '0.3rem' }}>
                        <Eye size={14}/> عرض
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <Pagination 
              current={currentPage} 
              total={totalPages} 
              onPage={(p) => load(filters, filterSupplier, p)} 
            />
          </div>
        )}
      </div>

      {/* Detail Modal */}
      {(selected || detailLoading) && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setSelected(null)}>
          <div className="modal" style={{ maxWidth: '600px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
              <h2 style={{ fontWeight: 700, fontSize: '1.1rem' }}>
                {selected ? `فاتورة مشتريات #${formatNumber(selected.id)}` : 'جاري التحميل…'}
              </h2>
              <div style={{ display: 'flex', gap: '0.4rem', alignItems: 'center' }}>
                {selected && (
                  <QZPrintButton
                    multiSize={true}
                    qzReady={qz.qzReady}
                    printing={qz.printing}
                    onQZPrint={async (paperSize) => {
                      const html = buildPurchaseReceiptHTML(selected, settings, paperSize)
                      const r = await qz.qzPrint(html)
                      if (r.ok) toast.success(`تمت الطباعة بنجاح (${paperSize})`)
                      else if (r.error) toast.error('فشل الطباعة: ' + r.error)
                    }}
                    onPickPrinter={() => qz.setShowPrinterPicker(true)}
                    onBrowserPrint={(paperSize) => browserPrintPurchase(selected, settings, paperSize)}
                  />
                )}
                <button className="btn btn-ghost btn-icon" onClick={() => setSelected(null)}><X size={18}/></button>
              </div>
            </div>

            {detailLoading && (
              <div style={{ padding: '2rem', textAlign: 'center' }}><span className="spinner" /></div>
            )}

            {selected && (
              <>
                <div className="resp-2col" style={{ marginBottom: '1rem' }}>
                  <InfoCard label="المورد" value={selected.supplier_name} />
                  <InfoCard label="التاريخ" value={formatDate(selected.created_at)} />
                  <InfoCard label="إجمالي الفاتورة" value={formatCurrency(selected.total)} />
                  <InfoCard label="إجمالي عدد الجرعات / الأصناف" value={formatNumber(selected.items_count)} />
                </div>

                <div className="table-wrapper" style={{ marginBottom: '1rem' }}>
                  <table style={{ fontSize: '0.88rem' }}>
                    <thead>
                      <tr>
                        <th>المنتج</th>
                        <th>الباركود</th>
                        <th>الكمية</th>
                        <th>التكلفة</th>
                        <th>الإجمالي</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(selected.items ?? []).map((item, idx) => (
                        <tr key={idx}>
                          <td>{item.product_name}</td>
                          <td style={{ color: 'var(--text-muted)' }}>{item.product_barcode || '—'}</td>
                          <td>{formatNumber(item.quantity)}</td>
                          <td>{formatCurrency(item.cost)}</td>
                          <td style={{ fontWeight: 600 }}>{formatCurrency(item.cost * item.quantity)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <div style={{ background: 'var(--bg)', borderRadius: 'var(--radius)', padding: '1rem', fontSize: '0.9rem', display: 'flex', flexDirection: 'column', gap: '0.4rem' }}>
                  <TotalRow label="إجمالي تكلفة المشتريات" value={formatCurrency(selected.total)} bold green />
                </div>

                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', marginTop: '1rem' }}>
                  <button
                      type="button"
                      className="btn btn-primary"
                      style={{ flex: '1 1 160px', justifyContent: 'center' }}
                      onClick={() => {
                        if (!selected?.items?.length) {
                          toast.error('لا توجد أصناف في الفاتورة')
                          return
                        }
                        onReturnToCart(selected.items, selected.supplier_id, selected.id)
                        setSelected(null)
                        toast.success('تم إرجاع المنتجات لسلة الاستلام')
                      }}
                    >
                      <ShoppingCart size={16} />
                      إرجاع المنتجات للسلة
                    </button>
                  {isAdmin && (
                    <button
                      type="button"
                      className="btn btn-danger"
                      style={{ flex: '1 1 160px', justifyContent: 'center' }}
                      onClick={handleDeleteInvoice}
                      disabled={deleting}
                    >
                      {deleting ? <span className="spinner" /> : <Trash2 size={16} />}
                      حذف الفاتورة والتراجع عن الشراء
                    </button>
                  )}
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {qz.showPrinterPicker && (
        <QZPrinterPicker
          printers={qz.printers}
          selectedPrinter={qz.selectedPrinter}
          onSelect={(name) => { qz.handlePrinterSelect(name); toast.success(`تم اختيار الطابعة: ${name}`) }}
          onClose={() => qz.setShowPrinterPicker(false)}
        />
      )}
    </div>
  )
}
