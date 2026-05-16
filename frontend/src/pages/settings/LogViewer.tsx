import { useEffect, useState } from 'react'
import api from '../../api/axios'

interface LogEntry {
  id: string
  level: string
  message: string
  context: string
  created_at: string
}

export default function LogViewer() {
  const [logs, setLogs] = useState<LogEntry[]>([])
  const [level, setLevel] = useState('all')

  useEffect(() => {
    api.get('/admin/client-logs', { params: { level, limit: 100 } })
      .then(r => setLogs(r.data.data ?? []))
      .catch(() => {})
  }, [level])

  return (
    <div style={{ padding: '0' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
        <h2 style={{ fontSize: '1.1rem', margin: 0 }}>سجل أخطاء العميل</h2>
        <select className="input" value={level} onChange={e => setLevel(e.target.value)} style={{ width: 'auto', padding: '0.3rem 0.5rem', fontSize: '0.85rem' }}>
          <option value="all">الكل</option>
          <option value="error">أخطاء</option>
          <option value="warning">تحذيرات</option>
          <option value="info">معلومات</option>
        </select>
      </div>
      <div className="table-responsive">
        <table className="table">
          <thead>
            <tr><th>الوقت</th><th>المستوى</th><th>الرسالة</th><th>التفاصيل</th></tr>
          </thead>
          <tbody>
            {logs.map(l => (
              <tr key={l.id}>
                <td style={{ direction: 'ltr', textAlign: 'right', fontSize: '0.8rem' }}>{l.created_at}</td>
                <td>
                  <span className={`badge badge-${l.level.toLowerCase().includes('error') ? 'danger' : l.level.toLowerCase().includes('warn') ? 'warning' : 'success'}`}>
                    {l.level}
                  </span>
                </td>
                <td style={{ maxWidth: '200px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} title={l.message}>{l.message}</td>
                <td style={{ maxWidth: '300px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', fontSize: '0.8rem' }} title={l.context}>
                  <code style={{ background: 'var(--bg)', padding: '0.2rem', borderRadius: '4px' }}>{l.context}</code>
                </td>
              </tr>
            ))}
            {logs.length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: '2rem' }}>لا توجد سجلات</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
