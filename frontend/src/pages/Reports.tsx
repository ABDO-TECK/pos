import { useState } from 'react'

import SummaryTab from './reports/SummaryTab'
import DailyTab from './reports/DailyTab'
import MonthlyTab from './reports/MonthlyTab'
import ProductsTab from './reports/ProductsTab'
import ProfitTab from './reports/ProfitTab'

export default function Reports() {
  const [tab, setTab] = useState('summary')

  const tabs = [
    { id: 'summary',  label: 'الملخص' },
    { id: 'daily',    label: 'يومي' },
    { id: 'monthly',  label: 'شهري' },
    { id: 'products', label: 'المنتجات' },
    { id: 'profit',   label: 'الأرباح' },
  ]

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <h1 style={{ fontSize: '1.3rem', fontWeight: 700 }}>التقارير والتحليلات</h1>

      {/* Tabs */}
      <div className="tabs-scroll">
        {tabs.map((t) => (
          <button key={t.id} onClick={() => setTab(t.id)} style={{
            padding: '0.5rem 1.1rem', background: 'none', border: 'none', cursor: 'pointer',
            fontWeight: tab === t.id ? 700 : 400,
            color: tab === t.id ? 'var(--primary)' : 'var(--text-muted)',
            borderBottom: `2px solid ${tab === t.id ? 'var(--primary)' : 'transparent'}`,
            marginBottom: '-2px', fontSize: '0.9rem',
          }}>{t.label}</button>
        ))}
      </div>

      {tab === 'summary' && <SummaryTab />}
      {tab === 'daily' && <DailyTab />}
      {tab === 'monthly' && <MonthlyTab />}
      {tab === 'products' && <ProductsTab />}
      {tab === 'profit' && <ProfitTab />}
    </div>
  )
}
