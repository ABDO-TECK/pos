// @ts-nocheck
import { formatCurrency, formatDate } from '../../../utils/formatters'
import { Edit2 } from 'lucide-react'

export default function SupplierLedgerTable({
  ledgerLoading, ledgerData,
  setEditEntryModal, setEditEntryForm
}) {
  if (ledgerLoading) {
    return <div style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>جارٍ التحميل...</div>
  }

  if (!ledgerData) return null

  return (
    <div style={{ flex: 1, overflowY: 'auto', borderRadius: 'var(--radius)', border: '1px solid var(--border)' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.85rem' }}>
        <thead>
          <tr style={{ background: 'var(--surface)', position: 'sticky', top: 0, zIndex: 1 }}>
            {['التاريخ', 'البيان', 'مدين (دفعة)', 'دائن (مستحق)', 'الرصيد', ''].map((h, i) => (
              <th key={i} style={{
                padding: '0.65rem 0.75rem', fontWeight: 700,
                textAlign: h.includes('مدين') || h.includes('دائن') || h.includes('الرصيد') ? 'left' : 'right',
                borderBottom: '2px solid var(--border)', whiteSpace: 'nowrap', width: h === '' ? '40px' : 'auto'
              }}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {ledgerData.entries.length === 0 ? (
            <tr><td colSpan={6} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد حركات بعد</td></tr>
          ) : ledgerData.entries.map((row, i) => {
            const isDebit = row.type === 'debit'
            const isCredit = row.type === 'credit'
            return (
              <tr key={row.id ?? `init-${i}`} style={{ borderBottom: '1px solid var(--border)', background: 'var(--bg)', transition: 'background .2s' }}>
                <td style={{ padding: '0.6rem 0.75rem', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                  {formatDate(row.created_at)}
                </td>
                <td style={{ padding: '0.6rem 0.75rem' }}>{row.description}</td>
                <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: isDebit ? 'var(--danger)' : 'var(--text-muted)', fontWeight: isDebit ? 600 : 400 }}>
                  {isDebit ? formatCurrency(row.debit) : '-'}
                </td>
                <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: isCredit ? 'var(--primary)' : 'var(--text-muted)', fontWeight: isCredit ? 600 : 400 }}>
                  {isCredit ? formatCurrency(row.credit) : '-'}
                </td>
                <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', fontWeight: 700, color: row.balance > 0 ? 'var(--danger)' : row.balance < 0 ? 'var(--primary)' : 'inherit' }}>
                  {formatCurrency(Math.abs(row.balance))}
                </td>
                <td style={{ padding: '0.4rem', textAlign: 'center' }}>
                  <button className="btn btn-ghost btn-icon btn-sm"
                    onClick={(e) => {
                      e.stopPropagation()
                      setEditEntryModal(row)
                      setEditEntryForm({
                        type: row.type,
                        amount: row.type === 'debit' ? (row.debit || 0) : (row.credit || 0),
                        description: row.description || ''
                      })
                    }}>
                    <Edit2 size={13} />
                  </button>
                </td>
              </tr>
            )
          })}
        </tbody>
        {ledgerData.entries.length > 0 && (
          <tfoot>
            <tr style={{ background: 'var(--surface)', fontWeight: 700, borderTop: '2px solid var(--border)' }}>
              <td colSpan={2} style={{ padding: '0.6rem 0.75rem' }}>الإجمالي</td>
              <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: 'var(--danger)' }}>
                {formatCurrency(ledgerData.entries.reduce((s, r) => s + r.debit, 0))}
              </td>
              <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: 'var(--primary)' }}>
                {formatCurrency(ledgerData.entries.reduce((s, r) => s + r.credit, 0))}
              </td>
              <td style={{ padding: '0.6rem 0.75rem', textAlign: 'left', color: ledgerData.balance > 0 ? 'var(--danger)' : 'var(--primary)', fontSize: '1rem' }}>
                {formatCurrency(ledgerData.balance)}
              </td>
            </tr>
          </tfoot>
        )}
      </table>
    </div>
  )
}
