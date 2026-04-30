import React from 'react'

export function profitColor(v: any) {
  const n = Number(v)
  if (Number.isNaN(n)) return undefined
  if (n < 0) return 'var(--danger)'
  if (n > 0) return 'var(--secondary)'
  return 'var(--text-muted)'
}

interface SCardProps {
  label: React.ReactNode
  value: React.ReactNode
  color?: string
  icon?: React.ReactNode
  title?: string
}

export function SCard({ label, value, color, icon, title: tip }: SCardProps) {
  return (
    <div className="stat-card" title={tip}>
      <div style={{ fontSize: '1.4rem' }}>{icon}</div>
      <div className="stat-value" style={{ color: color || 'var(--text)' }}>{value}</div>
      <div className="stat-label">{label}</div>
    </div>
  )
}
