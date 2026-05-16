import { useState, useEffect } from 'react'
import { Activity, Database, HardDrive, Cpu, RefreshCw, CheckCircle2, AlertTriangle, XCircle } from 'lucide-react'
import { getHealthCheck } from '../../api/endpoints'

interface HealthData {
  status: 'healthy' | 'unhealthy'
  checks: {
    database: { status: string; latency_ms: number | null }
    disk: { status: string; free_gb: number; total_gb: number; used_percent: number }
    memory: { status: string; usage_mb: number; peak_mb: number; limit: string }
    php: { version: string; extensions: Record<string, boolean> }
  }
  timestamp: string
}

function StatusIcon({ status }: { status: string }) {
  if (status === 'ok' || status === 'connected') return <CheckCircle2 size={16} style={{ color: 'var(--primary)' }} />
  if (status === 'warning') return <AlertTriangle size={16} style={{ color: '#f59e0b' }} />
  return <XCircle size={16} style={{ color: 'var(--danger)' }} />
}

export default function SystemHealth() {
  const [data, setData] = useState<HealthData | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const fetchHealth = async () => {
    setLoading(true)
    setError('')
    try {
      const res = await getHealthCheck()
      setData(res.data.data ?? (res.data as any))
    } catch (err) { setError('فشل الاتصال بالخادم')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { fetchHealth() }, [])

  const cardStyle: React.CSSProperties = {
    background: 'var(--surface)', border: '1px solid var(--border)',
    borderRadius: 'var(--radius)', padding: '1rem',
    display: 'flex', flexDirection: 'column', gap: '0.5rem',
  }
  const rowStyle: React.CSSProperties = {
    display: 'flex', justifyContent: 'space-between', fontSize: '0.85rem',
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <h3 style={{ fontSize: '1rem', fontWeight: 700, display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <Activity size={18} /> حالة النظام
        </h3>
        <button className="btn btn-ghost btn-sm" onClick={fetchHealth} disabled={loading}>
          <RefreshCw size={14} className={loading ? 'spinning' : ''} /> تحديث
        </button>
      </div>

      {error && <div style={{ color: 'var(--danger)', fontSize: '0.85rem' }}>{error}</div>}

      {data && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '0.75rem' }}>
          {/* Database */}
          <div style={cardStyle}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontWeight: 600 }}>
              <Database size={16} /> قاعدة البيانات <StatusIcon status={data.checks.database.status} />
            </div>
            <div style={rowStyle}><span>الحالة</span><span>{data.checks.database.status}</span></div>
            {data.checks.database.latency_ms != null && (
              <div style={rowStyle}><span>زمن الاستجابة</span><span>{data.checks.database.latency_ms} ms</span></div>
            )}
          </div>

          {/* Disk */}
          <div style={cardStyle}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontWeight: 600 }}>
              <HardDrive size={16} /> القرص الصلب <StatusIcon status={data.checks.disk.status} />
            </div>
            <div style={rowStyle}><span>المساحة المتاحة</span><span>{data.checks.disk.free_gb} GB</span></div>
            <div style={rowStyle}><span>نسبة الاستخدام</span><span>{data.checks.disk.used_percent}%</span></div>
            <div style={{ height: 6, background: 'var(--border)', borderRadius: 3, overflow: 'hidden' }}>
              <div style={{
                height: '100%', borderRadius: 3,
                width: `${data.checks.disk.used_percent}%`,
                background: data.checks.disk.used_percent > 90 ? 'var(--danger)' : 'var(--primary)',
              }} />
            </div>
          </div>

          {/* Memory */}
          <div style={cardStyle}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontWeight: 600 }}>
              <Cpu size={16} /> الذاكرة <StatusIcon status={data.checks.memory.status} />
            </div>
            <div style={rowStyle}><span>الاستخدام الحالي</span><span>{data.checks.memory.usage_mb} MB</span></div>
            <div style={rowStyle}><span>الذروة</span><span>{data.checks.memory.peak_mb} MB</span></div>
            <div style={rowStyle}><span>الحد الأقصى</span><span>{data.checks.memory.limit}</span></div>
          </div>

          {/* PHP */}
          <div style={cardStyle}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontWeight: 600 }}>
              ⚙️ PHP {data.checks.php.version}
            </div>
            {Object.entries(data.checks.php.extensions).map(([ext, loaded]) => (
              <div key={ext} style={rowStyle}>
                <span>{ext}</span>
                <span style={{ color: loaded ? 'var(--primary)' : 'var(--danger)' }}>
                  {loaded ? '✓ مُفعّل' : '✗ غير مُفعّل'}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {data && (
        <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', textAlign: 'center' }}>
          آخر فحص: {new Date(data.timestamp).toLocaleString('ar-EG')}
          {' — '}
          الحالة العامة: <strong style={{ color: data.status === 'healthy' ? 'var(--primary)' : 'var(--danger)' }}>
            {data.status === 'healthy' ? '✓ سليم' : '⚠ يحتاج انتباه'}
          </strong>
        </div>
      )}
    </div>
  )
}
