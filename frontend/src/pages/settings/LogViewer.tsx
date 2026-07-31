import { useEffect, useState } from 'react'
import api from '../../api/axios'

interface LogEntry {
  id: string
  level: string
  message: string
  context: unknown
  created_at: string | null
}

interface LogPagination {
  page: number
  limit: number
  next_cursor: string | null
  has_more: boolean
}

interface LogResponse {
  data?: LogEntry[]
  pagination?: LogPagination
}

function formatLogValue(value: unknown): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'string') return value

  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return String(value)
  }
}

export default function LogViewer() {
  const [logs, setLogs] = useState<LogEntry[]>([])
  const [level, setLevel] = useState('all')
  const [cursorStack, setCursorStack] = useState<Array<string | null>>([null])
  const [pagination, setPagination] = useState<LogPagination>({
    page: 1,
    limit: 100,
    next_cursor: null,
    has_more: false,
  })
  const [loading, setLoading] = useState(true)
  const cursor = cursorStack[cursorStack.length - 1]

  useEffect(() => {
    const controller = new AbortController()
    api.get<LogResponse>('/admin/client-logs', {
      params: { level, limit: 100, ...(cursor ? { cursor } : {}) },
      signal: controller.signal,
    })
      .then(response => {
        setLogs(response.data.data ?? [])
        setPagination(response.data.pagination ?? {
          page: cursorStack.length,
          limit: 100,
          next_cursor: null,
          has_more: false,
        })
      })
      .catch(() => {})
      .finally(() => {
        if (!controller.signal.aborted) {
          setLoading(false)
        }
      })

    return () => controller.abort()
  }, [cursor, cursorStack.length, level])

  const changeLevel = (nextLevel: string) => {
    setLoading(true)
    setLevel(nextLevel)
    setCursorStack([null])
  }

  const showNextPage = () => {
    if (pagination.next_cursor) {
      setLoading(true)
      setCursorStack(previous => [...previous, pagination.next_cursor])
    }
  }

  const showPreviousPage = () => {
    setLoading(true)
    setCursorStack(previous => previous.length > 1 ? previous.slice(0, -1) : previous)
  }

  return (
    <div style={{ padding: '0' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
        <h2 style={{ fontSize: '1.1rem', margin: 0 }}>سجل أخطاء العميل</h2>
        <select className="input" value={level} onChange={event => changeLevel(event.target.value)} style={{ width: 'auto', padding: '0.3rem 0.5rem', fontSize: '0.85rem' }}>
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
            {logs.map(l => {
              const message = formatLogValue(l.message)
              const context = formatLogValue(l.context)

              return (
                <tr key={l.id}>
                  <td style={{ direction: 'ltr', textAlign: 'right', fontSize: '0.8rem' }}>{l.created_at}</td>
                  <td>
                    <span className={`badge badge-${l.level.toLowerCase().includes('error') ? 'danger' : l.level.toLowerCase().includes('warn') ? 'warning' : 'success'}`}>
                      {l.level}
                    </span>
                  </td>
                  <td style={{ maxWidth: '200px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} title={message}>{message}</td>
                  <td style={{ maxWidth: '300px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', fontSize: '0.8rem' }} title={context}>
                    <code style={{ background: 'var(--bg)', padding: '0.2rem', borderRadius: '4px' }}>{context}</code>
                  </td>
                </tr>
              )
            })}
            {logs.length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: '2rem' }}>لا توجد سجلات</td></tr>}
          </tbody>
        </table>
      </div>
      <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: '0.75rem', marginTop: '1rem' }}>
        <button className="btn btn-secondary" type="button" onClick={showPreviousPage} disabled={loading || cursorStack.length === 1}>
          السابق
        </button>
        <span style={{ fontSize: '0.85rem' }}>الصفحة {pagination.page}</span>
        <button className="btn btn-secondary" type="button" onClick={showNextPage} disabled={loading || !pagination.has_more || !pagination.next_cursor}>
          التالي
        </button>
      </div>
    </div>
  )
}
