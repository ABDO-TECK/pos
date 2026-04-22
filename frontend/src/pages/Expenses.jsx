import { useState, useEffect } from 'react'
import { Plus, Edit2, Trash2, Calendar } from 'lucide-react'
import toast from 'react-hot-toast'
import api from '../api/axios'
import { formatCurrency, formatShortDate } from '../utils/formatters'
import useAuthStore from '../store/authStore'

export default function Expenses() {
  const [tab, setTab] = useState('log') // 'log' or 'categories'
  
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <h1 style={{ fontSize: '1.3rem', fontWeight: 700 }}>نظام المصروفات</h1>

      <div className="tabs-scroll">
        <button
          onClick={() => setTab('log')}
          style={{
            padding: '0.5rem 1.1rem', background: 'none', border: 'none', cursor: 'pointer',
            fontWeight: tab === 'log' ? 700 : 400,
            color: tab === 'log' ? 'var(--primary)' : 'var(--text-muted)',
            borderBottom: `2px solid ${tab === 'log' ? 'var(--primary)' : 'transparent'}`,
            marginBottom: '-2px', fontSize: '0.9rem',
          }}
        >
          سجل المصروفات
        </button>
        <button
          onClick={() => setTab('categories')}
          style={{
            padding: '0.5rem 1.1rem', background: 'none', border: 'none', cursor: 'pointer',
            fontWeight: tab === 'categories' ? 700 : 400,
            color: tab === 'categories' ? 'var(--primary)' : 'var(--text-muted)',
            borderBottom: `2px solid ${tab === 'categories' ? 'var(--primary)' : 'transparent'}`,
            marginBottom: '-2px', fontSize: '0.9rem',
          }}
        >
          تصنيفات المصروفات
        </button>
      </div>

      {tab === 'log' ? <ExpenseLogTab /> : <ExpenseCategoriesTab />}
    </div>
  )
}

function ExpenseLogTab() {
  const [expenses, setExpenses] = useState([])
  const [categories, setCategories] = useState([])
  const [loading, setLoading] = useState(false)
  const [showModal, setShowModal] = useState(false)
  const [editingExp, setEditingExp] = useState(null)
  
  const [filters, setFilters] = useState({ date: '', month: '', year: new Date().getFullYear(), category_id: '' })
  
  const [form, setForm] = useState({ category_id: '', amount: '', expense_date: new Date().toISOString().slice(0,16), notes: '' })
  const user = useAuthStore(s => s.user)
  const isAdmin = user?.role === 'admin'

  const loadExpenses = async () => {
    setLoading(true)
    try {
      const params = {}
      if (filters.date) params.date = filters.date
      if (filters.month) { params.month = filters.month; params.year = filters.year }
      if (filters.category_id) params.category_id = filters.category_id
      
      const res = await api.get('/expenses', { params })
      setExpenses(res.data.data ?? [])
    } catch {
      toast.error('فشل تحميل المصروفات')
    } finally {
      setLoading(false)
    }
  }

  const loadCategories = async () => {
    try {
      const res = await api.get('/expense-categories')
      setCategories(res.data.data ?? [])
    } catch {
      // ignore
    }
  }

  useEffect(() => {
    loadCategories()
  }, [])

  useEffect(() => {
    loadExpenses()
  }, [filters.date, filters.month, filters.year, filters.category_id])

  const handleSave = async (e) => {
    e.preventDefault()
    if (!form.category_id || !form.amount || !form.expense_date) return toast.error('يرجى تعبئة الحقول المطلوبة')
    
    try {
      const payload = { ...form, amount: parseFloat(form.amount) }
      if (editingExp) {
        await api.put(`/expenses/${editingExp.id}`, payload)
        toast.success('تم التعديل بنجاح')
      } else {
        await api.post('/expenses', payload)
        toast.success('تم إضافة المصروف بنجاح')
      }
      setShowModal(false)
      loadExpenses()
    } catch (err) {
      toast.error(err.response?.data?.message ?? 'فشل حفظ المصروف')
    }
  }

  const handleDelete = async (id) => {
    if (!confirm('هل أنت متأكد من حذف هذا المصروف؟')) return
    try {
      await api.delete(`/expenses/${id}`)
      toast.success('تم الحذف بنجاح')
      loadExpenses()
    } catch (err) {
      toast.error(err.response?.data?.message ?? 'فشل الحذف')
    }
  }

  const openAdd = () => {
    const tzOffset = (new Date()).getTimezoneOffset() * 60000; 
    const localISOTime = (new Date(Date.now() - tzOffset)).toISOString().slice(0, 16);
    setForm({ category_id: categories[0]?.id ?? '', amount: '', expense_date: localISOTime, notes: '' })
    setEditingExp(null)
    setShowModal(true)
  }

  const openEdit = (exp) => {
    setForm({
      category_id: exp.category_id,
      amount: exp.amount,
      expense_date: exp.expense_date.slice(0, 16),
      notes: exp.notes ?? ''
    })
    setEditingExp(exp)
    setShowModal(true)
  }

  return (
    <div className="card" style={{ padding: '1rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      
      {/* Filters & Actions */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: '1rem' }}>
        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', alignItems: 'center' }}>
          <div>
            <label style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>تصنيف</label>
            <select className="input" value={filters.category_id} onChange={e => setFilters({...filters, category_id: e.target.value})}>
              <option value="">الكل</option>
              {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <div>
            <label style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>تاريخ محدد</label>
            <input type="date" className="input" value={filters.date} onChange={e => setFilters({...filters, date: e.target.value, month: ''})} />
          </div>
          <div>
            <label style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>شهر</label>
            <select className="input" value={filters.month} onChange={e => setFilters({...filters, month: e.target.value, date: ''})}>
              <option value="">الكل</option>
              {Array.from({ length: 12 }, (_, i) => (
                <option key={i + 1} value={i + 1}>{new Date(2000, i).toLocaleString('ar-EG', { month: 'long' })}</option>
              ))}
            </select>
          </div>
          <button onClick={() => setFilters({ date: '', month: '', year: new Date().getFullYear(), category_id: '' })} className="btn btn-ghost btn-sm" style={{ marginTop: '1.2rem' }}>مسح الفلاتر</button>
        </div>
        
        <button className="btn btn-primary" onClick={openAdd}><Plus size={16}/> تسجيل مصروف</button>
      </div>

      <div className="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>التاريخ</th>
              <th>التصنيف</th>
              <th>المبلغ</th>
              <th>الملاحظات</th>
              <th>بواسطة</th>
              {isAdmin && <th></th>}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={6} style={{ textAlign: 'center', padding: '2rem' }}><span className="spinner" /></td></tr>
            ) : expenses.length === 0 ? (
              <tr><td colSpan={6} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد مصروفات</td></tr>
            ) : expenses.map(exp => (
              <tr key={exp.id}>
                <td>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.3rem' }}>
                    <Calendar size={14} style={{ color: 'var(--text-muted)' }}/>
                    {formatShortDate(exp.expense_date)}
                  </div>
                </td>
                <td><span className="badge badge-blue">{exp.category_name}</span></td>
                <td style={{ fontWeight: 700, color: 'var(--danger)' }}>{formatCurrency(exp.amount)}</td>
                <td style={{ color: 'var(--text-muted)', fontSize: '0.85rem' }}>{exp.notes ?? '—'}</td>
                <td style={{ fontSize: '0.85rem' }}>{exp.user_name}</td>
                {isAdmin && (
                  <td>
                    <div style={{ display: 'flex', gap: '0.3rem' }}>
                      <button className="btn btn-ghost btn-icon" onClick={() => openEdit(exp)}><Edit2 size={14}/></button>
                      <button className="btn btn-ghost btn-icon" style={{ color: 'var(--danger)' }} onClick={() => handleDelete(exp.id)}><Trash2 size={14}/></button>
                    </div>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Add/Edit Modal */}
      {showModal && (
        <div className="modal-overlay" onClick={e => e.target === e.currentTarget && setShowModal(false)}>
          <div className="modal">
            <h2 style={{ fontSize: '1.1rem', fontWeight: 700, marginBottom: '1rem' }}>
              {editingExp ? 'تعديل المصروف' : 'تسجيل مصروف جديد'}
            </h2>
            <form onSubmit={handleSave} style={{ display: 'flex', flexDirection: 'column', gap: '0.8rem' }}>
              
              <div className="form-group">
                <label>التصنيف *</label>
                <select className="input" value={form.category_id} onChange={e => setForm({...form, category_id: e.target.value})} required>
                  <option value="">اختر التصنيف...</option>
                  {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </div>

              <div className="form-group">
                <label>المبلغ *</label>
                <input type="number" step="0.01" min="0" className="input" value={form.amount} onChange={e => setForm({...form, amount: e.target.value})} required />
              </div>

              <div className="form-group">
                <label>تاريخ ووقت المصروف *</label>
                <input type="datetime-local" className="input" value={form.expense_date} onChange={e => setForm({...form, expense_date: e.target.value})} required />
                <span style={{ fontSize: '0.7rem', color: 'var(--text-muted)', marginTop: '0.2rem' }}>يمكنك تسجيل مصروف بتاريخ سابق.</span>
              </div>

              <div className="form-group">
                <label>ملاحظات (وصف)</label>
                <textarea className="input" value={form.notes} onChange={e => setForm({...form, notes: e.target.value})} rows={2} placeholder="مثل: فاتورة كهرباء شهر مايو..." />
              </div>

              <div style={{ display: 'flex', gap: '0.5rem', marginTop: '0.5rem' }}>
                <button type="button" className="btn btn-ghost" onClick={() => setShowModal(false)} style={{ flex: 1 }}>إلغاء</button>
                <button type="submit" className="btn btn-primary" style={{ flex: 2 }}>حفظ المصروف</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

function ExpenseCategoriesTab() {
  const [categories, setCategories] = useState([])
  const [loading, setLoading] = useState(false)
  const [showModal, setShowModal] = useState(false)
  const [editingCat, setEditingCat] = useState(null)
  const [name, setName] = useState('')
  const user = useAuthStore(s => s.user)
  const isAdmin = user?.role === 'admin'

  const loadCategories = async () => {
    setLoading(true)
    try {
      const res = await api.get('/expense-categories')
      setCategories(res.data.data ?? [])
    } catch {
      toast.error('فشل تحميل التصنيفات')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadCategories()
  }, [])

  const handleSave = async (e) => {
    e.preventDefault()
    if (!name.trim()) return
    try {
      if (editingCat) {
        await api.put(`/expense-categories/${editingCat.id}`, { name })
        toast.success('تم التعديل بنجاح')
      } else {
        await api.post('/expense-categories', { name })
        toast.success('تمت الإضافة بنجاح')
      }
      setShowModal(false)
      loadCategories()
    } catch (err) {
      toast.error(err.response?.data?.message ?? 'فشل الحفظ')
    }
  }

  const handleDelete = async (id) => {
    if (!confirm('هل أنت متأكد من حذف هذا التصنيف؟')) return
    try {
      await api.delete(`/expense-categories/${id}`)
      toast.success('تم الحذف بنجاح')
      loadCategories()
    } catch (err) {
      toast.error(err.response?.data?.message ?? 'فشل الحذف')
    }
  }

  return (
    <div className="card" style={{ padding: '1rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <h2 style={{ fontSize: '1rem', fontWeight: 600 }}>إدارة تصنيفات المصروفات</h2>
        {isAdmin && (
          <button className="btn btn-primary btn-sm" onClick={() => { setEditingCat(null); setName(''); setShowModal(true); }}>
            <Plus size={14}/> تصنيف جديد
          </button>
        )}
      </div>

      <div className="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>اسم التصنيف</th>
              {isAdmin && <th></th>}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={3} style={{ textAlign: 'center', padding: '2rem' }}><span className="spinner" /></td></tr>
            ) : categories.length === 0 ? (
              <tr><td colSpan={3} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد تصنيفات</td></tr>
            ) : categories.map((c, i) => (
              <tr key={c.id}>
                <td style={{ color: 'var(--text-muted)', width: '50px' }}>{i + 1}</td>
                <td style={{ fontWeight: 600 }}>{c.name}</td>
                {isAdmin && (
                  <td style={{ width: '100px' }}>
                    <div style={{ display: 'flex', gap: '0.3rem' }}>
                      <button className="btn btn-ghost btn-icon" onClick={() => { setEditingCat(c); setName(c.name); setShowModal(true); }}><Edit2 size={14}/></button>
                      <button className="btn btn-ghost btn-icon" style={{ color: 'var(--danger)' }} onClick={() => handleDelete(c.id)}><Trash2 size={14}/></button>
                    </div>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {showModal && (
        <div className="modal-overlay" onClick={e => e.target === e.currentTarget && setShowModal(false)}>
          <div className="modal" style={{ maxWidth: '400px' }}>
            <h2 style={{ fontSize: '1.1rem', fontWeight: 700, marginBottom: '1rem' }}>
              {editingCat ? 'تعديل التصنيف' : 'تصنيف جديد'}
            </h2>
            <form onSubmit={handleSave} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
              <div className="form-group">
                <label>اسم التصنيف *</label>
                <input type="text" className="input" value={name} onChange={e => setName(e.target.value)} required autoFocus />
              </div>
              <div style={{ display: 'flex', gap: '0.5rem' }}>
                <button type="button" className="btn btn-ghost" onClick={() => setShowModal(false)} style={{ flex: 1 }}>إلغاء</button>
                <button type="submit" className="btn btn-primary" style={{ flex: 1 }}>حفظ</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
