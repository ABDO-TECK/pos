// @ts-nocheck
import LedgerRow from '../../../components/customers/LedgerRow'
import { formatCurrency } from '../../../utils/formatters'

export default function CustomerLedgerTable({
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
            {['التاريخ', 'البيان', 'مدين', 'دائن', 'الرصيد', ''].map(h => (
              <th key={h} style={{
                padding: '0.65rem 0.75rem', fontWeight: 700, textAlign: h === 'مدين' || h === 'دائن' || h === 'الرصيد' ? 'left' : 'right',
                borderBottom: '2px solid var(--border)', whiteSpace: 'nowrap', width: h === '' ? '40px' : 'auto'
              }}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {ledgerData.entries.length === 0 ? (
            <tr><td colSpan={6} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>لا توجد حركات بعد</td></tr>
          ) : ledgerData.entries.map((row, i) => (
            <LedgerRow key={row.id ?? `init-${i}`} row={row} onEdit={() => {
              setEditEntryModal(row)
              setEditEntryForm({
                type: row.type,
                amount: row.type === 'debit' ? (row.debit || 0) : (row.credit || 0),
                description: row.description || ''
              })
            }} />
          ))}
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
