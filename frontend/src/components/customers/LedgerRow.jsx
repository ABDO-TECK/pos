import { Edit2 } from 'lucide-react';
import { formatCurrency } from '../../utils/formatters';

const fmtDate = (s) => {
  if (!s) return '—';
  const d = new Date(s);
  if (Number.isNaN(d.getTime())) return '—';
  return new Intl.DateTimeFormat('en-GB', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(d);
};

export default function LedgerRow({ row, onEdit }) {
  const isDebit  = row.debit  > 0;
  const isCredit = row.credit > 0;
  return (
    <tr style={{ borderBottom: '1px solid var(--border)', transition: 'background .1s' }}
        onMouseEnter={e => e.currentTarget.style.background = 'var(--bg)'}
        onMouseLeave={e => e.currentTarget.style.background = ''}>
      <td style={{ padding: '0.55rem 0.75rem', whiteSpace: 'nowrap', color: 'var(--text-muted)', fontSize: '0.8rem' }}>
        {fmtDate(row.date)}
      </td>
      <td style={{ padding: '0.55rem 0.75rem' }}>
        <span style={{ fontSize: '0.85rem' }}>{row.description || '—'}</span>
        {row.type === 'initial' && (
          <span style={{ marginRight: '0.5rem', fontSize: '0.7rem', background: 'rgba(59,130,246,.1)', color: 'var(--secondary)', borderRadius: '3px', padding: '0.1rem 0.35rem' }}>رصيد مبدئي</span>
        )}
      </td>
      <td style={{ padding: '0.55rem 0.75rem', textAlign: 'left', fontWeight: isDebit ? 700 : 400, color: isDebit ? 'var(--danger)' : 'var(--text-muted)' }}>
        {isDebit ? formatCurrency(row.debit) : '—'}
      </td>
      <td style={{ padding: '0.55rem 0.75rem', textAlign: 'left', fontWeight: isCredit ? 700 : 400, color: isCredit ? 'var(--primary)' : 'var(--text-muted)' }}>
        {isCredit ? formatCurrency(row.credit) : '—'}
      </td>
      <td style={{ padding: '0.55rem 0.75rem', textAlign: 'left', fontWeight: 700, color: row.balance > 0 ? 'var(--danger)' : row.balance < 0 ? 'var(--primary)' : 'var(--text)' }}>
        {formatCurrency(Math.abs(row.balance))}
        {row.balance > 0 && <span style={{ fontSize: '0.65rem', color: 'var(--danger)', marginRight: '0.3rem' }}>د</span>}
        {row.balance < 0 && <span style={{ fontSize: '0.65rem', color: 'var(--primary)', marginRight: '0.3rem' }}>ر</span>}
      </td>
      <td style={{ padding: '0.2rem 0.5rem', textAlign: 'center' }}>
        {row.id && row.type !== 'initial' && (
          <button className="btn btn-ghost btn-icon" style={{ padding: '0.25rem', color: 'var(--text-muted)' }} onClick={onEdit} title="تعديل القيد">
            <Edit2 size={13} />
          </button>
        )}
      </td>
    </tr>
  );
}
