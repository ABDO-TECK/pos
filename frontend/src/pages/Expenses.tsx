import { useState } from 'react'
import ExpenseLogTab from './expenses/ExpenseLogTab'
import ExpenseCategoriesTab from './expenses/ExpenseCategoriesTab'

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
