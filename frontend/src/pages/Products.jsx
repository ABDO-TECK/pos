import { useState, useEffect, useRef } from 'react'
import { Plus, Pencil, Trash2, Search, X, Tag, AlertTriangle, Warehouse } from 'lucide-react'
import {
  getProducts, createProduct, updateProduct, deleteProduct,
  getCategories, createCategory, updateCategory, deleteCategory,
  getInventory, getLowStock, adjustInventory,
} from '../api/endpoints'
import { formatCurrency, formatNumber } from '../utils/formatters'
import toast from 'react-hot-toast'

const emptyProduct = { name: '', barcode: '', price: '', cost: '', quantity: '', low_stock_threshold: 5, category_id: '' }

export default function Products() {
  const [tab, setTab] = useState('products')

  // ── Products state ─────────────────────────────────────────
  const [products,        setProducts]        = useState([])
  const [search,          setSearch]          = useState('')
  const [loadingProducts, setLoadingProducts] = useState(false)
  const [productModal,    setProductModal]    = useState(null)
  const [productForm,     setProductForm]     = useState(emptyProduct)
  const [editProductId,   setEditProductId]   = useState(null)
  const [savingProduct,   setSavingProduct]   = useState(false)
  const searchTimer = useRef(null)

  // ── Categories state ───────────────────────────────────────
  const [categories,        setCategories]        = useState([])
  const [loadingCategories, setLoadingCategories] = useState(false)
  const [categoryModal,     setCategoryModal]     = useState(null)
  const [categoryForm,      setCategoryForm]      = useState({ name: '' })
  const [editCategoryId,    setEditCategoryId]    = useState(null)
  const [savingCategory,    setSavingCategory]    = useState(false)

  // ── Inventory state ────────────────────────────────────────
  const [invProducts,  setInvProducts]  = useState([])
  const [lowStock,     setLowStock]     = useState([])
  const [invSearch,    setInvSearch]    = useState('')
  const [invLoading,   setInvLoading]   = useState(false)
  const [editModal,    setEditModal]    = useState(null)
  const [newQty,       setNewQty]       = useState(0)
  const invSearchTimer = useRef(null)

  // ── Load data ──────────────────────────────────────────────
  const loadProducts = async (s = '') => {
    setLoadingProducts(true)
    try { setProducts((await getProducts({ search: s })).data.data) }
    finally { setLoadingProducts(false) }
  }

  const loadCategories = async () => {
    setLoadingCategories(true)
    try { setCategories((await getCategories()).data.data) }
    finally { setLoadingCategories(false) }
  }

  const loadInventory = async (s = '') => {
    setInvLoading(true)
    try {
      const [invRes, lowRes] = await Promise.all([getInventory({ search: s }), getLowStock()])
      setInvProducts(invRes.data.data)
      setLowStock(lowRes.data.data)
    } catch { toast.error('فشل تحميل المخزون') }
    finally { setInvLoading(false) }
  }

  useEffect(() => {
    loadProducts()
    loadCategories()
  }, [])

  // Load inventory data when tab switches to inventory
  useEffect(() => {
    if (tab === 'inventory' && invProducts.length === 0) loadInventory()
  }, [tab])

  // ── Product actions ────────────────────────────────────────
  const openCreateProduct = () => { setProductForm(emptyProduct); setEditProductId(null); setProductModal('create') }
  const openEditProduct = (p) => { setProductForm({ ...p, category_id: p.category_id ?? '' }); setEditProductId(p.id); setProductModal('edit') }

  const handleSaveProduct = async () => {
    setSavingProduct(true)
    try {
      if (productModal === 'create') { await createProduct(productForm); toast.success('تم إضافة المنتج') }
      else { await updateProduct(editProductId, productForm); toast.success('تم تحديث المنتج') }
      setProductModal(null)
      loadProducts(search)
      if (tab === 'inventory') loadInventory(invSearch)
    } catch (err) { toast.error(err.response?.data?.message || 'حدث خطأ') }
    finally { setSavingProduct(false) }
  }

  const handleDeleteProduct = async (id, name) => {
    if (!confirm(`هل تريد حذف "${name}"؟`)) return
    try { await deleteProduct(id); toast.success('تم حذف المنتج'); loadProducts(search) }
    catch (err) { toast.error(err.response?.data?.message || 'حدث خطأ أثناء الحذف') }
  }

  // ── Category actions ───────────────────────────────────────
  const openCreateCategory = () => { setCategoryForm({ name: '' }); setEditCategoryId(null); setCategoryModal('create') }
  const openEditCategory = (c) => { setCategoryForm({ name: c.name }); setEditCategoryId(c.id); setCategoryModal('edit') }

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
    try { await deleteCategory(id); toast.success('تم حذف الفئة'); loadCategories(); loadProducts(search) }
    catch (err) { toast.error(err.response?.data?.message || 'حدث خطأ أثناء الحذف') }
  }

  // ── Inventory actions ──────────────────────────────────────
  const handleInvSearch = (val) => {
    setInvSearch(val)
    clearTimeout(invSearchTimer.current)
    invSearchTimer.current = setTimeout(() => loadInventory(val), 400)
  }

  const handleAdjust = async () => {
    try {
      await adjustInventory(editModal.id, { quantity: newQty })
      toast.success('تم تحديث الكمية')
      setEditModal(null)
      loadInventory(invSearch)
    } catch (err) { toast.error(err.response?.data?.message || 'حدث خطأ') }
  }

  // ── Tab header action button ───────────────────────────────
  const headerBtn = {
    products:   <button className="btn btn-primary" onClick={openCreateProduct}><Plus size={16} /> إضافة منتج</button>,
    inventory:  null,
    categories: <button className="btn btn-primary" onClick={openCreateCategory}><Plus size={16} /> إضافة فئة</button>,
  }

  const tabLabels = { products: 'المنتجات', inventory: 'المخزون', categories: 'الفئات' }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>

      {/* Header */}
      <div className="page-header">
        <h2>{tabLabels[tab]}</h2>
        {headerBtn[tab]}
      </div>

      {/* Tabs */}
      <div className="tabs-scroll" style={{ marginTop: '-0.5rem' }}>
        <TabBtn active={tab === 'products'} onClick={() => setTab('products')}>
          المنتجات
          <span className="badge badge-gray" style={{ fontSize: '0.72rem', marginRight: '0.3rem' }}>{formatNumber(products.length)}</span>
        </TabBtn>
        <TabBtn active={tab === 'inventory'} onClick={() => setTab('inventory')}>
          <Warehouse size={14} style={{ marginLeft: '0.3rem' }} />
          المخزون
          {lowStock.length > 0 && (
            <span className="badge badge-yellow" style={{ fontSize: '0.72rem', marginRight: '0.3rem' }}>{formatNumber(lowStock.length)}</span>
          )}
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
          <div className="card" style={{ padding: '0.75rem' }}>
            <div style={{ position: 'relative' }}>
              <Search size={18} style={{ position: 'absolute', top: '50%', transform: 'translateY(-50%)', right: '0.75rem', color: 'var(--text-muted)' }} />
              <input
                className="input" style={{ paddingRight: '2.5rem' }}
                placeholder="بحث بالاسم أو الباركود..."
                value={search}
                onChange={(e) => {
                  setSearch(e.target.value)
                  clearTimeout(searchTimer.current)
                  searchTimer.current = setTimeout(() => loadProducts(e.target.value), 400)
                }}
              />
            </div>
          </div>

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
                  ) : products.length === 0 ? (
                    <tr><td colSpan={7} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد منتجات</td></tr>
                  ) : products.map((p) => (
                    <tr key={p.id}>
                      <td style={{ fontWeight: 600 }}>
                        {p.name}
                        <div className="show-mobile" style={{ fontSize: '0.75rem', color: 'var(--text-muted)', fontWeight: 400 }}>
                          {p.barcode} {p.category_name && `· ${p.category_name}`}
                        </div>
                      </td>
                      <td className="hide-mobile"><code style={{ fontSize: '0.8rem' }}>{p.barcode}</code></td>
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

      {/* ── Inventory Tab ────────────────────────────────────── */}
      {tab === 'inventory' && (
        <>
          {/* Low stock alert */}
          {lowStock.length > 0 && (
            <div style={{ background: '#fef9c3', border: '1px solid #fbbf24', borderRadius: 'var(--radius)', padding: '0.75rem 1rem' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontWeight: 700, color: '#92400e', marginBottom: '0.5rem' }}>
                <AlertTriangle size={18} /> {formatNumber(lowStock.length)} منتج بمخزون منخفض
              </div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.4rem' }}>
                {lowStock.map((p) => (
                  <span key={p.id} className="badge badge-yellow">{p.name} ({formatNumber(p.quantity)})</span>
                ))}
              </div>
            </div>
          )}

          {/* Stats */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '0.75rem' }}>
            <StatCard icon={<Warehouse size={22} color="var(--secondary)" />} label="إجمالي المنتجات" value={formatNumber(invProducts.length)} />
            <StatCard icon={<AlertTriangle size={22} color="var(--warning)" />} label="مخزون منخفض" value={formatNumber(lowStock.length)} color="var(--warning)" />
            <StatCard icon={<span style={{ fontSize: '1.2rem' }}>📦</span>} label="إجمالي الوحدات"
              value={formatNumber(invProducts.reduce((s, p) => s + p.quantity, 0))} />
            <StatCard icon={<span style={{ fontSize: '1.2rem' }}>💰</span>} label="قيمة المخزون"
              value={formatCurrency(invProducts.reduce((s, p) => s + p.quantity * p.cost, 0))} />
          </div>

          {/* Search */}
          <div className="card" style={{ padding: '0.75rem' }}>
            <div style={{ position: 'relative' }}>
              <Search size={18} style={{ position: 'absolute', top: '50%', transform: 'translateY(-50%)', right: '0.75rem', color: 'var(--text-muted)' }} />
              <input className="input" style={{ paddingRight: '2.5rem' }} placeholder="بحث..."
                value={invSearch} onChange={(e) => handleInvSearch(e.target.value)} />
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
                    <th>الكمية</th>
                    <th className="hide-mobile">حد التنبيه</th>
                    <th>الحالة</th>
                    <th className="hide-mobile">قيمة المخزون</th>
                    <th>تعديل</th>
                  </tr>
                </thead>
                <tbody>
                  {invLoading ? (
                    <tr><td colSpan={7} style={{ textAlign: 'center', padding: '2rem' }}><span className="spinner" /></td></tr>
                  ) : invProducts.map((p) => {
                    const isNeg = p.quantity < 0
                    const isOut = p.quantity === 0
                    const isLow = p.quantity > 0 && p.quantity <= p.low_stock_threshold
                    return (
                      <tr key={p.id}>
                        <td style={{ fontWeight: 600 }}>
                          {p.name}
                          <div className="show-mobile" style={{ fontSize: '0.75rem', color: 'var(--text-muted)', fontWeight: 400 }}>
                            {p.barcode}
                          </div>
                        </td>
                        <td className="hide-mobile"><code style={{ fontSize: '0.8rem' }}>{p.barcode}</code></td>
                        <td style={{ fontWeight: 700, color: isNeg ? '#ef4444' : undefined }}>{formatNumber(p.quantity)}</td>
                        <td className="hide-mobile">{formatNumber(p.low_stock_threshold)}</td>
                        <td>
                          {isNeg ? <span className="badge badge-red">سالب</span>
                            : isOut ? <span className="badge badge-red">نفد</span>
                            : isLow ? <span className="badge badge-yellow">منخفض</span>
                            : <span className="badge badge-green">جيد</span>}
                        </td>
                        <td className="hide-mobile">{formatCurrency(p.quantity * p.cost)}</td>
                        <td>
                          <button className="btn btn-ghost btn-sm" onClick={() => { setEditModal(p); setNewQty(p.quantity) }}>
                            <Pencil size={13} /> تعديل
                          </button>
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

      {/* ── Categories Tab ───────────────────────────────────── */}
      {tab === 'categories' && (
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
                ) : categories.map((c, i) => {
                  const count = products.filter((p) => p.category_id === c.id).length
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
      )}

      {/* ── Product Modal ─────────────────────────────────────── */}
      {productModal && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setProductModal(null)}>
          <div className="modal" style={{ maxWidth: '520px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1.25rem' }}>
              <h2 style={{ fontWeight: 700 }}>{productModal === 'create' ? 'إضافة منتج جديد' : 'تعديل المنتج'}</h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setProductModal(null)}><X size={18} /></button>
            </div>
            <ProductForm form={productForm} setForm={setProductForm} categories={categories} />
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

      {/* ── Inventory Adjust Modal ────────────────────────────── */}
      {editModal && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setEditModal(null)}>
          <div className="modal" style={{ maxWidth: '380px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1rem' }}>
              <h3 style={{ fontWeight: 700 }}>تعديل الكمية</h3>
              <button className="btn btn-ghost btn-icon" onClick={() => setEditModal(null)}><X size={18} /></button>
            </div>
            <p style={{ marginBottom: '1rem', color: 'var(--text-muted)' }}>المنتج: <strong>{editModal.name}</strong></p>
            <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.4rem' }}>الكمية الجديدة</label>
            <input className="input input-lg" type="number" min={0} value={newQty}
              onChange={(e) => setNewQty(parseInt(e.target.value) || 0)} />
            <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1rem' }}>
              <button className="btn btn-primary" style={{ flex: 1, justifyContent: 'center' }} onClick={handleAdjust}>حفظ</button>
              <button className="btn btn-ghost" style={{ flex: 1, justifyContent: 'center' }} onClick={() => setEditModal(null)}>إلغاء</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

// ── Helper components ──────────────────────────────────────────────────────

function ProductForm({ form, setForm, categories }) {
  const f = (k) => ({ value: form[k] ?? '', onChange: (e) => setForm((p) => ({ ...p, [k]: e.target.value })) })
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '0.75rem' }}>
      <div style={{ gridColumn: 'span 2' }}>
        <Label>اسم المنتج *</Label>
        <input className="input" {...f('name')} placeholder="مثال: أرز بسمتي 1كغ" required />
      </div>
      <div style={{ gridColumn: 'span 2' }}>
        <Label>الباركود *</Label>
        <input className="input" {...f('barcode')} placeholder="امسح الباركود أو اكتبه يدويًا" required />
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
      <div style={{ gridColumn: 'span 2' }}>
        <Label>الفئة</Label>
        <select className="input" {...f('category_id')}>
          <option value="">— بدون فئة —</option>
          {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </div>
    </div>
  )
}

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

function Label({ children }) {
  return <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>{children}</label>
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
