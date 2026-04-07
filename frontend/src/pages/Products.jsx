import { useState, useEffect, useRef } from 'react'
import { Plus, Pencil, Trash2, Search, X, Tag } from 'lucide-react'
import {
  getProducts, createProduct, updateProduct, deleteProduct,
  getCategories, createCategory, updateCategory, deleteCategory,
} from '../api/endpoints'
import { formatCurrency, formatNumber } from '../utils/formatters'
import toast from 'react-hot-toast'

const emptyProduct = { name: '', barcode: '', price: '', cost: '', quantity: '', low_stock_threshold: 5, category_id: '' }

export default function Products() {
  const [tab, setTab] = useState('products')

  // ── Products state ─────────────────────────────────────────
  const [products, setProducts] = useState([])
  const [search, setSearch] = useState('')
  const [loadingProducts, setLoadingProducts] = useState(false)
  const [productModal, setProductModal] = useState(null) // null | 'create' | 'edit'
  const [productForm, setProductForm] = useState(emptyProduct)
  const [editProductId, setEditProductId] = useState(null)
  const [savingProduct, setSavingProduct] = useState(false)
  const searchTimer = useRef(null)

  // ── Categories state ───────────────────────────────────────
  const [categories, setCategories] = useState([])
  const [loadingCategories, setLoadingCategories] = useState(false)
  const [categoryModal, setCategoryModal] = useState(null) // null | 'create' | 'edit'
  const [categoryForm, setCategoryForm] = useState({ name: '' })
  const [editCategoryId, setEditCategoryId] = useState(null)
  const [savingCategory, setSavingCategory] = useState(false)

  // ── Load data ──────────────────────────────────────────────
  const loadProducts = async (s = '') => {
    setLoadingProducts(true)
    try {
      const res = await getProducts({ search: s })
      setProducts(res.data.data)
    } finally {
      setLoadingProducts(false)
    }
  }

  const loadCategories = async () => {
    setLoadingCategories(true)
    try {
      const res = await getCategories()
      setCategories(res.data.data)
    } finally {
      setLoadingCategories(false)
    }
  }

  useEffect(() => {
    loadProducts()
    loadCategories()
  }, [])

  // ── Product actions ────────────────────────────────────────
  const openCreateProduct = () => {
    setProductForm(emptyProduct)
    setEditProductId(null)
    setProductModal('create')
  }

  const openEditProduct = (p) => {
    setProductForm({ ...p, category_id: p.category_id ?? '' })
    setEditProductId(p.id)
    setProductModal('edit')
  }

  const handleSaveProduct = async () => {
    setSavingProduct(true)
    try {
      if (productModal === 'create') {
        await createProduct(productForm)
        toast.success('تم إضافة المنتج')
      } else {
        await updateProduct(editProductId, productForm)
        toast.success('تم تحديث المنتج')
      }
      setProductModal(null)
      loadProducts(search)
    } catch (err) {
      toast.error(err.response?.data?.message || 'حدث خطأ')
    } finally {
      setSavingProduct(false)
    }
  }

  const handleDeleteProduct = async (id, name) => {
    if (!confirm(`هل تريد حذف "${name}"؟`)) return
    try {
      await deleteProduct(id)
      toast.success('تم حذف المنتج')
      loadProducts(search)
    } catch (err) {
      toast.error(err.response?.data?.message || 'حدث خطأ أثناء الحذف')
    }
  }

  // ── Category actions ───────────────────────────────────────
  const openCreateCategory = () => {
    setCategoryForm({ name: '' })
    setEditCategoryId(null)
    setCategoryModal('create')
  }

  const openEditCategory = (c) => {
    setCategoryForm({ name: c.name })
    setEditCategoryId(c.id)
    setCategoryModal('edit')
  }

  const handleSaveCategory = async () => {
    if (!categoryForm.name.trim()) {
      toast.error('اسم الفئة مطلوب')
      return
    }
    setSavingCategory(true)
    try {
      if (categoryModal === 'create') {
        await createCategory(categoryForm)
        toast.success('تم إضافة الفئة')
      } else {
        await updateCategory(editCategoryId, categoryForm)
        toast.success('تم تحديث الفئة')
      }
      setCategoryModal(null)
      loadCategories()
    } catch (err) {
      toast.error(err.response?.data?.message || 'حدث خطأ')
    } finally {
      setSavingCategory(false)
    }
  }

  const handleDeleteCategory = async (id, name) => {
    if (!confirm(`هل تريد حذف فئة "${name}"؟ سيتم إلغاء ربط المنتجات بها.`)) return
    try {
      await deleteCategory(id)
      toast.success('تم حذف الفئة')
      loadCategories()
      loadProducts(search)
    } catch (err) {
      toast.error(err.response?.data?.message || 'حدث خطأ أثناء الحذف')
    }
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>

      {/* Header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <h1 style={{ fontSize: '1.3rem', fontWeight: 700 }}>
          {tab === 'products' ? 'المنتجات' : 'الفئات'}
        </h1>
        {tab === 'products' ? (
          <button className="btn btn-primary" onClick={openCreateProduct}>
            <Plus size={16} /> إضافة منتج
          </button>
        ) : (
          <button className="btn btn-primary" onClick={openCreateCategory}>
            <Plus size={16} /> إضافة فئة
          </button>
        )}
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: '0', borderBottom: '2px solid var(--border)' }}>
        <TabBtn active={tab === 'products'} onClick={() => setTab('products')}>
          المنتجات
          <span className="badge badge-gray" style={{ fontSize: '0.72rem', marginRight: '0.3rem' }}>{formatNumber(products.length)}</span>
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
                    <th>الباركود</th>
                    <th>السعر</th>
                    <th>التكلفة</th>
                    <th>الكمية</th>
                    <th>الفئة</th>
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
                      <td style={{ fontWeight: 600 }}>{p.name}</td>
                      <td><code style={{ fontSize: '0.8rem' }}>{p.barcode}</code></td>
                      <td>{formatCurrency(p.price)}</td>
                      <td>{formatCurrency(p.cost)}</td>
                      <td>
                        <span className={`badge ${p.quantity <= p.low_stock_threshold ? (p.quantity === 0 ? 'badge-red' : 'badge-yellow') : 'badge-green'}`}>
                          {formatNumber(p.quantity)}
                        </span>
                      </td>
                      <td>
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
                          <Tag size={14} color="var(--secondary)" />
                          {c.name}
                        </span>
                      </td>
                      <td>
                        <span className="badge badge-gray">{formatNumber(count)} منتج</span>
                      </td>
                      <td>
                        <div style={{ display: 'flex', gap: '0.4rem' }}>
                          <button className="btn btn-ghost btn-sm" onClick={() => openEditCategory(c)}>
                            <Pencil size={13} /> تعديل
                          </button>
                          <button className="btn btn-danger btn-sm" onClick={() => handleDeleteCategory(c.id, c.name)}>
                            <Trash2 size={13} /> حذف
                          </button>
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

      {/* ── Product Modal ────────────────────────────────────── */}
      {productModal && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setProductModal(null)}>
          <div className="modal" style={{ maxWidth: '520px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1.25rem' }}>
              <h2 style={{ fontWeight: 700 }}>
                {productModal === 'create' ? 'إضافة منتج جديد' : 'تعديل المنتج'}
              </h2>
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

      {/* ── Category Modal ───────────────────────────────────── */}
      {categoryModal && (
        <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setCategoryModal(null)}>
          <div className="modal" style={{ maxWidth: '400px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1.25rem' }}>
              <h2 style={{ fontWeight: 700 }}>
                {categoryModal === 'create' ? 'إضافة فئة جديدة' : 'تعديل الفئة'}
              </h2>
              <button className="btn btn-ghost btn-icon" onClick={() => setCategoryModal(null)}><X size={18} /></button>
            </div>
            <div>
              <label style={{ fontSize: '0.85rem', fontWeight: 600, display: 'block', marginBottom: '0.4rem' }}>
                اسم الفئة *
              </label>
              <input
                className="input input-lg"
                placeholder="مثال: مشروبات، مواد غذائية..."
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
    </div>
  )
}

function ProductForm({ form, setForm, categories }) {
  const f = (k) => ({ value: form[k] ?? '', onChange: (e) => setForm((p) => ({ ...p, [k]: e.target.value })) })
  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
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
          {categories.map((c) => (
            <option key={c.id} value={c.id}>{c.name}</option>
          ))}
        </select>
        {categories.length === 0 && (
          <p style={{ fontSize: '0.78rem', color: 'var(--text-muted)', marginTop: '0.3rem' }}>
            لا توجد فئات — أضف فئات من تاب "الفئات" أولًا
          </p>
        )}
      </div>
    </div>
  )
}

function Label({ children }) {
  return <label style={{ fontSize: '0.82rem', fontWeight: 600, display: 'block', marginBottom: '0.3rem' }}>{children}</label>
}

function TabBtn({ active, onClick, children }) {
  return (
    <button
      onClick={onClick}
      style={{
        display: 'inline-flex', alignItems: 'center',
        padding: '0.5rem 1.25rem', background: 'none', border: 'none', cursor: 'pointer',
        fontWeight: active ? 700 : 400,
        color: active ? 'var(--primary)' : 'var(--text-muted)',
        borderBottom: `2px solid ${active ? 'var(--primary)' : 'transparent'}`,
        marginBottom: '-2px', fontSize: '0.9rem',
        fontFamily: 'inherit',
      }}
    >
      {children}
    </button>
  )
}
