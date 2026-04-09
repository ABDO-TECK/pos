import { useState, useEffect, useMemo, useRef } from 'react'
import { Plus, Pencil, Trash2, Search, X, Tag, AlertTriangle, Warehouse, SlidersHorizontal, ChevronDown, Camera } from 'lucide-react'
import {
  getProducts, createProduct, updateProduct, deleteProduct,
  getCategories, createCategory, updateCategory, deleteCategory,
  getLowStock,
} from '../api/endpoints'

import { formatCurrency, formatNumber } from '../utils/formatters'
import toast from 'react-hot-toast'

const emptyProduct = {
  name: '',
  barcodes: [''],
  price: '',
  cost: '',
  quantity: '',
  low_stock_threshold: 5,
  units_per_box: 1,
  category_id: '',
}

const STOCK_FILTERS = [
  { id: 'all', label: 'الكل' },
  { id: 'available', label: 'متوفر' },
  { id: 'low', label: 'مخزون منخفض' },
  { id: 'low_available', label: 'منخفض (يوجد رصيد)' },
  { id: 'out', label: 'نفد المخزون' },
]

/** منتج يملك هذا الباركود (أساسي أو إضافي)، مع استثناء منتج أثناء التعديل */
function findProductOwningBarcode(allProducts, barcode, excludeProductId) {
  const t = String(barcode).trim()
  if (!t) return null
  for (const p of allProducts) {
    if (excludeProductId != null && Number(p.id) === Number(excludeProductId)) continue
    if (String(p.barcode ?? '') === t) return p
    if ((p.additional_barcodes || []).some((b) => String(b) === t)) return p
  }
  return null
}

function getBarcodeRowConflict(barcodes, rowIndex, excludeProductId, allProducts) {
  const bc = String(barcodes[rowIndex] ?? '').trim()
  if (!bc) return null
  const dupElsewhere = barcodes.some(
    (b, j) => j !== rowIndex && String(b).trim() === bc
  )
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

function formatProductApiError(err) {
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

const SORT_OPTIONS = [
  { id: 'name_asc', label: 'الاسم (أ ← ي)' },
  { id: 'name_desc', label: 'الاسم (ي ← أ)' },
  { id: 'newest', label: 'الأحدث إضافة' },
  { id: 'oldest', label: 'الأقدم إضافة' },
  { id: 'updated_desc', label: 'آخر تعديل' },
  { id: 'qty_desc', label: 'الكمية (الأعلى أولاً)' },
  { id: 'qty_asc', label: 'الكمية (الأدنى أولاً)' },
  { id: 'price_desc', label: 'السعر (الأعلى أولاً)' },
  { id: 'price_asc', label: 'السعر (الأدنى أولاً)' },
]

export default function Products() {
  const [tab, setTab] = useState('products')

  // ── Products ───────────────────────────────────────────────
  const [allProducts,     setAllProducts]     = useState([])   // full list from API
  const [search,          setSearch]          = useState('')
  const [loadingProducts, setLoadingProducts] = useState(false)
  const [productModal,    setProductModal]    = useState(null)
  const [productForm,     setProductForm]     = useState(emptyProduct)
  const [editProductId,   setEditProductId]   = useState(null)
  const [savingProduct,   setSavingProduct]   = useState(false)
  const [lowStock,        setLowStock]        = useState([])
  const [categoryFilter, setCategoryFilter]  = useState('')
  const [stockFilter,    setStockFilter]     = useState('all')
  const [sortKey,        setSortKey]         = useState('name_asc')
  const [searchCameraOpen, setSearchCameraOpen] = useState(false)
  const [SearchScannerLazy, setSearchScannerLazy] = useState(null)

  const q = search.trim().toLowerCase()

  const displayProducts = useMemo(() => {
    let list = allProducts

    if (categoryFilter) {
      list = list.filter((p) => String(p.category_id ?? '') === categoryFilter)
    }

    if (q) {
      list = list.filter((p) => {
        const extra = (p.additional_barcodes || []).some((b) => String(b).toLowerCase().includes(q))
        return (
          p.name.toLowerCase().includes(q) ||
          (p.barcode && p.barcode.toLowerCase().includes(q)) ||
          extra
        )
      })
    }

    switch (stockFilter) {
      case 'low':
        list = list.filter((p) => Number(p.quantity) <= Number(p.low_stock_threshold ?? 5))
        break
      case 'out':
        list = list.filter((p) => Number(p.quantity) <= 0)
        break
      case 'available':
        list = list.filter((p) => Number(p.quantity) > 0)
        break
      case 'low_available':
        list = list.filter((p) => {
          const qn = Number(p.quantity)
          const th = Number(p.low_stock_threshold ?? 5)
          return qn > 0 && qn <= th
        })
        break
      default:
        break
    }

    const sorted = [...list]
    const nameCmp = (a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'ar', { sensitivity: 'base' })

    switch (sortKey) {
      case 'name_desc':
        sorted.sort((a, b) => nameCmp(b, a))
        break
      case 'newest':
        sorted.sort((a, b) => Number(b.id) - Number(a.id))
        break
      case 'oldest':
        sorted.sort((a, b) => Number(a.id) - Number(b.id))
        break
      case 'updated_desc':
        sorted.sort((a, b) => {
          const ta = new Date(a.updated_at || 0).getTime()
          const tb = new Date(b.updated_at || 0).getTime()
          if (tb !== ta) return tb - ta
          return Number(b.id) - Number(a.id)
        })
        break
      case 'qty_desc':
        sorted.sort((a, b) => Number(b.quantity) - Number(a.quantity) || nameCmp(a, b))
        break
      case 'qty_asc':
        sorted.sort((a, b) => Number(a.quantity) - Number(b.quantity) || nameCmp(a, b))
        break
      case 'price_desc':
        sorted.sort((a, b) => Number(b.price) - Number(a.price) || nameCmp(a, b))
        break
      case 'price_asc':
        sorted.sort((a, b) => Number(a.price) - Number(b.price) || nameCmp(a, b))
        break
      case 'name_asc':
      default:
        sorted.sort(nameCmp)
        break
    }

    return sorted
  }, [allProducts, q, categoryFilter, stockFilter, sortKey])

  // ── Categories ─────────────────────────────────────────────
  const [categories,        setCategories]        = useState([])
  const [loadingCategories, setLoadingCategories] = useState(false)
  const [categoryModal,     setCategoryModal]     = useState(null)
  const [categoryForm,      setCategoryForm]      = useState({ name: '' })
  const [editCategoryId,    setEditCategoryId]    = useState(null)
  const [savingCategory,    setSavingCategory]    = useState(false)
  const [categoryTabSearch, setCategoryTabSearch] = useState('')

  const categoryTabQ = categoryTabSearch.trim().toLowerCase()
  const filteredCategoriesTab = useMemo(() => {
    if (!categoryTabQ) return categories
    return categories.filter((c) => (c.name || '').toLowerCase().includes(categoryTabQ))
  }, [categories, categoryTabQ])

  // ── Load ───────────────────────────────────────────────────
  const loadProducts = async () => {
    setLoadingProducts(true)
    try { setAllProducts((await getProducts({ limit: 9999 })).data.data ?? []) }
    finally { setLoadingProducts(false) }
  }

  const loadCategories = async () => {
    setLoadingCategories(true)
    try { setCategories((await getCategories()).data.data) }
    finally { setLoadingCategories(false) }
  }

  useEffect(() => {
      loadProducts()
      loadCategories()
      getLowStock().then(r => setLowStock(r.data.data ?? []))

  }, [])

  // ── Product actions ────────────────────────────────────────
  const openCreateProduct = () => {
    setProductForm({ ...emptyProduct })
    setEditProductId(null)
    setProductModal('create')
  }
  const openEditProduct = (p) => {
    const extras = p.additional_barcodes || []
    setProductForm({
      ...p,
      category_id: p.category_id ?? '',
      units_per_box: p.units_per_box ?? 1,
      barcodes: [p.barcode || '', ...extras],
    })
    setEditProductId(p.id)
    setProductModal('edit')
  }

  const handleSaveProduct = async () => {
    const raw = Array.isArray(productForm.barcodes) ? productForm.barcodes : [productForm.barcode || '']
    const main = String(raw[0] ?? '').trim()
    // الباركود اختياري — إذا كان فارغاً سيُولَّد تلقائياً من الباكند
    const additional_barcodes = raw.slice(1).map((b) => String(b).trim()).filter(Boolean)
    const { barcodes: _b, barcode: _old, ...rest } = productForm
    const payload = { ...rest, barcode: main, additional_barcodes }

    setSavingProduct(true)
    try {
      if (productModal === 'create') {
        await createProduct(payload)
        toast.success('تم إضافة المنتج')
      } else {
        await updateProduct(editProductId, payload)
        toast.success('تم تحديث المنتج')
      }
      setProductModal(null)
      loadProducts()
    } catch (err) { toast.error(formatProductApiError(err)) }
    finally { setSavingProduct(false) }
  }

  const handleDeleteProduct = async (id, name) => {
    if (!confirm(`هل تريد حذف "${name}"؟`)) return
    try { await deleteProduct(id); toast.success('تم حذف المنتج'); loadProducts() }
    catch (err) { toast.error(err.response?.data?.message || 'حدث خطأ أثناء الحذف') }
  }

  // ── Category actions ───────────────────────────────────────
  const openCreateCategory = () => { setCategoryForm({ name: '' }); setEditCategoryId(null); setCategoryModal('create') }
  const openEditCategory   = (c) => { setCategoryForm({ name: c.name }); setEditCategoryId(c.id); setCategoryModal('edit') }

  const handleSaveCategory = async () => {
    if (!categoryForm.name.trim()) { toast.error('اسم الفئة مطلوب'); return }
    setSavingCategory(true)
    try {
      if (categoryModal === 'create') { await createCategory(categoryForm); toast.success('تم إضافة الفئة') }
      else { await updateCategory(editCategoryId, categoryForm); toast.success('تم تحديث الفئة') }
      setCategoryModal(null); loadCategories()
    } catch (err) { toast.error(err.response?.data?.message || 'حدث خطأ') }
    finally { setSavingCategory(false) }
  }

  const handleDeleteCategory = async (id, name) => {
    if (!confirm(`هل تريد حذف فئة "${name}"؟ سيتم إلغاء ربط المنتجات بها.`)) return
    try { await deleteCategory(id); toast.success('تم حذف الفئة'); loadCategories(); loadProducts() }
    catch (err) { toast.error(err.response?.data?.message || 'حدث خطأ أثناء الحذف') }
  }

  // ── Computed stats (على كل المنتجات المحمّلة، وليس على المصفّاة فقط) ──
  const totalUnits  = allProducts.reduce((s, p) => s + Number(p.quantity), 0)
  const stockValue  = allProducts.reduce((s, p) => s + Number(p.quantity) * Number(p.cost), 0)
  const outOfStock  = allProducts.filter((p) => p.quantity <= 0).length

  const filtersActive =
    stockFilter !== 'all' ||
    sortKey !== 'name_asc' ||
    Boolean(categoryFilter) ||
    Boolean(search.trim())

  const clearProductFilters = () => {
    setStockFilter('all')
    setSortKey('name_asc')
    setCategoryFilter('')
    setSearch('')
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>

      {/* Header */}
      <div className="page-header">
        <h2>{tab === 'products' ? 'المنتجات' : 'الفئات'}</h2>
        {tab === 'products'
          ? <button className="btn btn-primary" onClick={openCreateProduct}><Plus size={16} /> إضافة منتج</button>
          : <button className="btn btn-primary" onClick={openCreateCategory}><Plus size={16} /> إضافة فئة</button>
        }
      </div>

      {/* Tabs */}
      <div className="tabs-scroll" style={{ marginTop: '-0.5rem' }}>
        <TabBtn active={tab === 'products'} onClick={() => setTab('products')}>
          المنتجات
          <span className="badge badge-gray" style={{ fontSize: '0.72rem', marginRight: '0.3rem' }}>{formatNumber(displayProducts.length)}</span>
        </TabBtn>
        <TabBtn active={tab === 'categories'} onClick={() => setTab('categories')}>
          <Tag size={14} style={{ marginLeft: '0.3rem' }} />
          الفئات
          <span className="badge badge-gray" style={{ fontSize: '0.72rem', marginRight: '0.3rem' }}>{formatNumber(categories.length)}</span>
        </TabBtn>
      </div>

      {/* ── Products Tab ─────────────────────────────────────── */}
      {tab === 'products' && (
        <>
          {/* Inventory widgets */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: '0.75rem' }}>
            <StatCard icon={<Warehouse size={20} color="var(--secondary)" />}  label="إجمالي المنتجات"  value={formatNumber(allProducts.length)} />
            <StatCard icon={<span style={{ fontSize: '1.1rem' }}>📦</span>}      label="إجمالي الوحدات"   value={formatNumber(totalUnits)} />
            <StatCard icon={<span style={{ fontSize: '1.1rem' }}>💰</span>}      label="قيمة المخزون"    value={formatCurrency(stockValue)} />
            <StatCard
              icon={<AlertTriangle size={20} color={lowStock.length > 0 ? 'var(--warning)' : 'var(--text-muted)'} />}
              label="مخزون منخفض"
              value={formatNumber(lowStock.length)}
              color={lowStock.length > 0 ? 'var(--warning)' : undefined}
            />
            <StatCard
              icon={<span style={{ fontSize: '1.1rem' }}>🚫</span>}
              label="نفد المخزون"
              value={formatNumber(outOfStock)}
              color={outOfStock > 0 ? 'var(--danger)' : undefined}
            />
          </div>

          {/* Low stock alert banner */}
          {lowStock.length > 0 && (
            <div style={{ background: '#fef9c3', border: '1px solid #fbbf24', borderRadius: 'var(--radius)', padding: '0.65rem 1rem' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontWeight: 700, color: '#92400e', marginBottom: '0.4rem', fontSize: '0.9rem' }}>
                <AlertTriangle size={16} /> {formatNumber(lowStock.length)} منتج بمخزون منخفض
              </div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.35rem' }}>
                {lowStock.map(p => (
                  <span key={p.id} className="badge badge-yellow">{p.name} ({formatNumber(p.quantity)})</span>
                ))}
              </div>
            </div>
          )}

          {/* Search + filters */}
          <div className="card" style={{ padding: '0.75rem', display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
            <div style={{ display: 'flex', gap: '0.4rem', alignItems: 'center' }}>
              <div style={{ position: 'relative', flex: 1, minWidth: 0 }}>
                <Search size={18} style={{ position: 'absolute', top: '50%', transform: 'translateY(-50%)', right: '0.75rem', color: 'var(--text-muted)' }} />
                <input
                  className="input" style={{ paddingRight: '2.5rem' }}
                  placeholder="بحث بالاسم أو الباركود..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                />
              </div>
              <button
                type="button"
                className="btn btn-ghost btn-icon"
                style={{ flexShrink: 0, padding: '0.5rem' }}
                title="مسح الباركود بالكاميرا"
                aria-label="مسح الباركود بالكاميرا"
                onClick={async () => {
                  try {
                    if (!SearchScannerLazy) {
                      const m = await import('../components/BarcodeCameraScanner')
                      setSearchScannerLazy(() => m.default)
                    }
                    setSearchCameraOpen(true)
                  } catch {
                    toast.error('تعذر تحميل ماسح الباركود')
                  }
                }}
              >
                <Camera size={18} />
              </button>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.82rem', fontWeight: 600, color: 'var(--text-muted)' }}>
                <SlidersHorizontal size={16} />
                المخزون
              </span>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.35rem', flex: 1 }}>
                {STOCK_FILTERS.map((f) => (
                  <button
                    key={f.id}
                    type="button"
                    onClick={() => setStockFilter(f.id)}
                    className={`btn btn-sm ${stockFilter === f.id ? 'btn-primary' : 'btn-ghost'}`}
                    style={{ fontSize: '0.78rem' }}
                  >
                    {f.label}
                  </button>
                ))}
              </div>
            </div>

            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.75rem', alignItems: 'flex-end' }}>
              <div style={{ flex: '1 1 160px', minWidth: 0 }}>
                <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--text-muted)', marginBottom: '0.3rem' }}>الفئة</label>
                <select
                  className="input"
                  style={{ width: '100%' }}
                  value={categoryFilter}
                  onChange={(e) => setCategoryFilter(e.target.value)}
                >
                  <option value="">كل الفئات</option>
                  {categories.map((c) => (
                    <option key={c.id} value={String(c.id)}>{c.name}</option>
                  ))}
                </select>
              </div>
              <div style={{ flex: '1 1 200px', minWidth: 0 }}>
                <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--text-muted)', marginBottom: '0.3rem' }}>الترتيب</label>
                <select
                  className="input"
                  style={{ width: '100%' }}
                  value={sortKey}
                  onChange={(e) => setSortKey(e.target.value)}
                >
                  {SORT_OPTIONS.map((o) => (
                    <option key={o.id} value={o.id}>{o.label}</option>
                  ))}
                </select>
              </div>
              {filtersActive && (
                <button type="button" className="btn btn-ghost btn-sm" onClick={clearProductFilters} style={{ alignSelf: 'center' }}>
                  <X size={14} /> مسح التصفية
                </button>
              )}
            </div>

            <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)' }}>
              معروض {formatNumber(displayProducts.length)} من {formatNumber(allProducts.length)} منتج
              {filtersActive && ' — تم تطبيق تصفية أو ترتيب'}
            </div>
          </div>

          {/* Table */}
          <div className="card">
            <div className="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>المنتج</th>
                    <th className="hide-mobile">الباركود</th>
                    <th>السعر</th>
                    <th className="hide-mobile">التكلفة</th>
                    <th>الكمية</th>
                    <th className="hide-mobile">الفئة</th>
                    <th>الإجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  {loadingProducts ? (
                    <tr><td colSpan={7} style={{ textAlign: 'center', padding: '2rem' }}><span className="spinner" /></td></tr>
                  ) : displayProducts.length === 0 ? (
                    <tr><td colSpan={7} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد منتجات</td></tr>
                  ) : displayProducts.map((p) => (
                    <tr key={p.id}>
                      <td style={{ fontWeight: 600 }}>
                        {p.name}
                        <div className="show-mobile" style={{ fontSize: '0.75rem', color: 'var(--text-muted)', fontWeight: 400 }}>
                          {p.barcode}
                          {(p.additional_barcodes || []).length > 0 && ` (+${formatNumber((p.additional_barcodes || []).length)})`}
                          {p.category_name ? ` · ${p.category_name}` : ''}
                        </div>
                      </td>
                      <td className="hide-mobile">
                        <code style={{ fontSize: '0.8rem' }}>{p.barcode}</code>
                        {(p.additional_barcodes || []).length > 0 && (
                          <div style={{ fontSize: '0.72rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>
                            +{formatNumber((p.additional_barcodes || []).length)} باركود إضافي
                          </div>
                        )}
                      </td>
                      <td>{formatCurrency(p.price)}</td>
                      <td className="hide-mobile">{formatCurrency(p.cost)}</td>
                      <td>
                        <span className={`badge ${p.quantity <= 0 ? 'badge-red' : p.quantity <= p.low_stock_threshold ? 'badge-yellow' : 'badge-green'}`}>
                          {formatNumber(p.quantity)}
                        </span>
                      </td>
                      <td className="hide-mobile">
                        {p.category_name
                          ? <span className="badge badge-blue">{p.category_name}</span>
                          : <span style={{ color: 'var(--text-muted)' }}>—</span>}
                      </td>
                      <td>
                        <div style={{ display: 'flex', gap: '0.4rem' }}>
                          <button className="btn btn-ghost btn-sm" onClick={() => openEditProduct(p)}><Pencil size={13} /></button>
                          <button className="btn btn-danger btn-sm" onClick={() => handleDeleteProduct(p.id, p.name)}><Trash2 size={13} /></button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}

      {/* ── Categories Tab ───────────────────────────────────── */}
      {tab === 'categories' && (
        <>
          <div className="card" style={{ padding: '0.75rem' }}>
            <div style={{ position: 'relative' }}>
              <Search size={18} style={{ position: 'absolute', top: '50%', transform: 'translateY(-50%)', right: '0.75rem', color: 'var(--text-muted)', pointerEvents: 'none' }} />
              <input
                className="input"
                style={{ paddingRight: '2.5rem' }}
                placeholder="بحث في أسماء الفئات…"
                value={categoryTabSearch}
                onChange={(e) => setCategoryTabSearch(e.target.value)}
              />
            </div>
            {categories.length > 0 && (
              <div style={{ fontSize: '0.78rem', color: 'var(--text-muted)', marginTop: '0.5rem' }}>
                معروض {formatNumber(filteredCategoriesTab.length)} من {formatNumber(categories.length)} فئة
              </div>
            )}
          </div>
          <div className="card">
            <div className="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>اسم الفئة</th>
                    <th>عدد المنتجات</th>
                    <th>الإجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  {loadingCategories ? (
                    <tr><td colSpan={4} style={{ textAlign: 'center', padding: '2rem' }}><span className="spinner" /></td></tr>
                  ) : categories.length === 0 ? (
                    <tr>
                      <td colSpan={4} style={{ textAlign: 'center', padding: '2.5rem', color: 'var(--text-muted)' }}>
                        <div style={{ fontSize: '2rem', marginBottom: '0.5rem' }}>🏷️</div>
                        لا توجد فئات — أضف فئة جديدة للبدء
                      </td>
                    </tr>
                  ) : filteredCategoriesTab.length === 0 ? (
                    <tr>
                      <td colSpan={4} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>
                        لا توجد فئات تطابق البحث
                      </td>
                    </tr>
                  ) : filteredCategoriesTab.map((c, i) => {
                    const count = allProducts.filter((p) => p.category_id === c.id).length
                    return (
                      <tr key={c.id}>
                        <td style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>{formatNumber(i + 1)}</td>
                        <td style={{ fontWeight: 600 }}>
                          <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem' }}>
                            <Tag size={14} color="var(--secondary)" />{c.name}
                          </span>
                        </td>
                        <td><span className="badge badge-gray">{formatNumber(count)} منتج</span></td>
                        <td>
                          <div style={{ display: 'flex', gap: '0.4rem' }}>
                            <button className="btn btn-ghost btn-sm" onClick={() => openEditCategory(c)}><Pencil size={13} /> تعديل</button>
                            <button className="btn btn-danger btn-sm" onClick={() => handleDeleteCategory(c.id, c.name)}><Trash2 size={13} /> حذف</button>
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}

      {/* ── Product Modal ─────────────────────────────────────── */}
      {productModal && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setProductModal(null)}>
          <div className="modal" style={{ maxWidth: '520px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1.25rem' }}>
              <h2 style={{ fontWeight: 700 }}>{productModal === 'create' ? 'إضافة منتج جديد' : 'تعديل المنتج'}</h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setProductModal(null)}><X size={18} /></button>
            </div>
            <ProductForm
              form={productForm}
              setForm={setProductForm}
              categories={categories}
              modalKey={productModal + (editProductId ?? 'new')}
              allProducts={allProducts}
              editingProductId={productModal === 'edit' ? editProductId : null}
            />
            <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.25rem' }}>
              <button className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }} onClick={handleSaveProduct} disabled={savingProduct}>
                {savingProduct ? <span className="spinner" /> : null} حفظ
              </button>
              <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={() => setProductModal(null)}>إلغاء</button>
            </div>
          </div>
        </div>
      )}

      {/* ── Category Modal ────────────────────────────────────── */}
      {categoryModal && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setCategoryModal(null)}>
          <div className="modal" style={{ maxWidth: '400px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1.25rem' }}>
              <h2 style={{ fontWeight: 700 }}>{categoryModal === 'create' ? 'إضافة فئة جديدة' : 'تعديل الفئة'}</h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setCategoryModal(null)}><X size={18} /></button>
            </div>
            <div>
              <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.4rem' }}>اسم الفئة *</label>
              <input className="input input-lg" placeholder="مثال: مشروبات، مواد غذائية..."
                value={categoryForm.name}
                onChange={(e) => setCategoryForm({ name: e.target.value })}
                onKeyDown={(e) => e.key === 'Enter' && handleSaveCategory()}
                autoFocus
              />
            </div>
            <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.25rem' }}>
              <button className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }} onClick={handleSaveCategory} disabled={savingCategory}>
                {savingCategory ? <span className="spinner" /> : null} حفظ
              </button>
              <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={() => setCategoryModal(null)}>إلغاء</button>
            </div>
          </div>
        </div>
      )}

      {/* ── Search Barcode Camera Scanner ──────────────────────── */}
      {SearchScannerLazy && searchCameraOpen && (
        <SearchScannerLazy
          onResult={(text) => {
            setSearch(text)
            setSearchCameraOpen(false)
            toast.success('تمت قراءة الباركود')
          }}
          onClose={() => setSearchCameraOpen(false)}
        />
      )}
    </div>
  )
}

// ── Helper components ──────────────────────────────────────────────────────

function StatCard({ icon, label, value, color }) {
  return (
    <div className="stat-card">
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
        {icon}
        <span className="stat-label">{label}</span>
      </div>
      <div className="stat-value" style={{ color: color || 'var(--text)' }}>{value}</div>
    </div>
  )
}

function ProductForm({ form, setForm, categories, modalKey, allProducts = [], editingProductId = null }) {
  const [barcodeCameraRow, setBarcodeCameraRow] = useState(null)
  const [BarcodeScannerLazy, setBarcodeScannerLazy] = useState(null)

  const openBarcodeCamera = async (rowIndex) => {
    try {
      if (!BarcodeScannerLazy) {
        const m = await import('../components/BarcodeCameraScanner')
        setBarcodeScannerLazy(() => m.default)
      }
      setBarcodeCameraRow(rowIndex)
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
          const conflict = getBarcodeRowConflict(barcodes, idx, editingProductId, allProducts)
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
        <Label>📦 عدد القطع في الصندوق</Label>
        <p style={{ fontSize: '0.76rem', color: 'var(--text-muted)', margin: '0 0 0.45rem' }}>
          عند البيع بالصندوق في نقطة البيع، سيتم إضافة هذا العدد من القطع دفعةً واحدة إلى السلة.
        </p>
        <div style={{ display: 'flex', gap: '0.65rem', alignItems: 'stretch', flexWrap: 'wrap' }}>
          <input
            className="input"
            type="number"
            min="1"
            step="1"
            {...f('units_per_box')}
            placeholder="1"
            style={{ width: '7rem', flexShrink: 0 }}
          />
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
          setBarcodeAt(barcodeCameraRow, text)
          setBarcodeCameraRow(null)
          toast.success('تمت قراءة الباركود')
        }}
        onClose={() => setBarcodeCameraRow(null)}
      />
    )}
    </>
  )
}

function Label({ children }) {
  return <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>{children}</label>
}

/** قائمة فئات مع بحث فوري؛ «بدون فئة» أولاً دائماً */
function CategoryCombobox({ categories, value, onChange }) {
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

function TabBtn({ active, onClick, children }) {
  return (
    <button onClick={onClick} style={{
      display: 'inline-flex', alignItems: 'center',
      padding: '0.5rem 1.25rem', background: 'none', border: 'none', cursor: 'pointer',
      fontWeight: active ? 700 : 400,
      color: active ? 'var(--primary)' : 'var(--text-muted)',
      borderBottom: `2px solid ${active ? 'var(--primary)' : 'transparent'}`,
      marginBottom: '-2px', fontSize: '0.9rem', fontFamily: 'inherit', whiteSpace: 'nowrap',
    }}>
      {children}
    </button>
  )
}
