import React from 'react'

export default function Pagination({ current, total, onPage }) {
  if (total <= 1) return null;

  return (
    <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'center', marginTop: '1.5rem', alignItems: 'center' }}>
      <button 
        className="btn btn-ghost btn-sm" 
        onClick={() => onPage(current - 1)} 
        disabled={current <= 1}
      >
        السابق
      </button>
      
      <span style={{ padding: '0.3rem 0.8rem', fontSize: '0.9rem', color: 'var(--text-muted)', fontWeight: 600, background: 'var(--bg)', borderRadius: 'var(--radius)' }}>
        صفحة {current} من {total}
      </span>
      
      <button 
        className="btn btn-ghost btn-sm" 
        onClick={() => onPage(current + 1)} 
        disabled={current >= total}
      >
        التالي
      </button>
    </div>
  )
}
