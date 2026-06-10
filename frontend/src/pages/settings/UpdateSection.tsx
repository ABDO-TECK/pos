import { useState } from 'react'
import { RefreshCw, List } from 'lucide-react'
import toast from 'react-hot-toast'
import { applyUpdate } from '../../api/endpoints'
import useUpdateStore from '../../store/updateStore'
import { useConfirmStore } from '../../store/confirmStore'
import SectionTitle from '../../components/common/SectionTitle'
import { extractApiError } from '../../utils/apiError'

export default function UpdateSection() {
  const { confirm } = useConfirmStore()
  const { hasUpdate, currentVersion, latestVersion, changelog, isChecking, forceCheck } = useUpdateStore()

  const [applyingUpdate, setApplyingUpdate] = useState(false)
  const [showChangelog, setShowChangelog] = useState(false)
  const [updateLogs, setUpdateLogs] = useState<string[]>([])

  const handleCheckUpdate = async () => {
    try {
      const data = await forceCheck()
      if (data?.has_update) {
        toast.success('تم العثور على تحديث جديد!')
        setShowChangelog(true)
      } else {
        toast.success('النظام محدّث لأحدث إصدار')
      }
    } catch (err: any) { toast.error(extractApiError(err, 'فشل التحقق من التحديثات')) }
  }

  const handleApplyUpdate = async (force = false) => {
    if (!force) {
      if (!(await confirm('سيتم إنشاء نسخة احتياطية من قاعدة البيانات ثم تحديث ملفات النظام والمكتبات تلقائياً. هل أنت متأكد من رغبتك بالاستمرار؟ (قد يستغرق الأمر دقيقة أو اثنتين)'))) return
    }
    
    setApplyingUpdate(true)
    setUpdateLogs([])
    try {
      const res = await applyUpdate(force)
      const logs = (res.data?.data as { logs?: string[] })?.logs || []
      setUpdateLogs(logs)
      toast.success('تم تطبيق التحديث بنجاح! جاري إعادة التحميل...')
      setTimeout(() => window.location.reload(), 3000)
    } catch (err: any) {
      const status = err.response?.status
      const msg = err.response?.data?.message || 'فشل تطبيق التحديث.'
      const errData = err.response?.data as { errors?: { logs?: string[] }, data?: { logs?: string[] } } | undefined;
      const logs = errData?.errors?.logs || errData?.data?.logs || []
      setUpdateLogs(logs)

      if (status === 409 && !force) {
        setApplyingUpdate(false)
        const forceConfirm = await confirm(
          'توجد تعديلات محلية في بعض ملفات النظام. سيتم استبدالها بالنسخة الأصلية من التحديث. هل تريد المتابعة؟'
        )
        if (forceConfirm) {
          handleApplyUpdate(true)
        }
        return
      }

      toast.error(msg)
      if (Array.isArray(logs) && logs.length) {
        console.error('سجل التحديث:', logs)
      }
      setApplyingUpdate(false)
    }
  }

  return (
    <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <SectionTitle icon={<RefreshCw size={16}/>} label="تحديثات النظام التلقائية" />
      <p style={{ fontSize: '0.9rem', color: 'var(--text-muted)', margin: 0 }}>
        التحقق من توفر تحديثات جديدة للنظام من المطور وتطبيقها بضغطة زر واحدة مجاناً بفضل نظام التشغيل السحابي.
      </p>
      
      <div style={{
        padding: '1rem', 
        borderRadius: 'var(--radius)', 
        background: hasUpdate ? 'rgba(40, 167, 69, 0.1)' : 'var(--bg)',
        border: hasUpdate ? '1px solid rgba(40, 167, 69, 0.3)' : '1px solid var(--border)'
      }}>
        <h3 style={{ margin: '0 0 0.5rem 0', fontSize: '1rem', color: hasUpdate ? 'var(--success)' : 'var(--text)' }}>
          {hasUpdate ? '🎉 تحديث جديد متوفر!' : '✅ النظام مُحدَّث'}
        </h3>
        <p style={{ margin: '0 0 0.2rem 0', fontSize: '0.85rem' }}>
          الإصدار الحالي: <strong>{currentVersion ? `v${currentVersion}` : 'غير معروف'}</strong>
        </p>
        {latestVersion && (
          <p style={{ margin: 0, fontSize: '0.85rem' }}>
            أحدث إصدار متاح: <strong>v{latestVersion}</strong>
          </p>
        )}
      </div>

      {(applyingUpdate || updateLogs.length > 0) && (
        <div style={{ padding: '1rem', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginTop: '0.5rem' }}>
          {applyingUpdate && (
            <div style={{ fontWeight: 600, color: 'var(--primary-d)', marginBottom: '0.5rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <span className="spinner" style={{ borderColor: 'currentColor', borderRightColor: 'transparent' }} /> جاري التحديث... الرجاء عدم إغلاق هذه الصفحة
            </div>
          )}
          <div
            style={{
              background: '#1a1a2e',
              color: '#e0e0e0',
              padding: '0.8rem 1rem',
              borderRadius: '6px',
              fontSize: '0.82rem',
              fontFamily: 'Consolas, monospace',
              maxHeight: '260px',
              overflowY: 'auto',
              direction: 'ltr',
              textAlign: 'left',
              display: 'flex',
              flexDirection: 'column',
              gap: '0.2rem',
            }}
          >
            {updateLogs.length > 0 ? (
              updateLogs.map((line, i) => (
                <div key={i} style={{ 
                  color: line.startsWith('✅') ? '#4ade80' : line.startsWith('⚠️') ? '#fbbf24' : line.startsWith('❌') ? '#f87171' : line.startsWith('🎉') ? '#34d399' : '#e0e0e0',
                  lineHeight: 1.5,
                }}>
                  {line || '\u00A0'}
                </div>
              ))
            ) : (
              <div style={{ color: '#888' }}>في انتظار استجابة الخادم...</div>
            )}
          </div>
        </div>
      )}

      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
        <button
          type="button"
          onClick={handleCheckUpdate}
          disabled={isChecking || applyingUpdate}
          className="btn btn-secondary"
        >
          {isChecking ? <span className="spinner" /> : <RefreshCw size={16}/>}
          {isChecking ? 'جاري التحقق…' : 'التحقق من التحديثات'}
        </button>

        {changelog.length > 0 && (
          <button
            type="button"
            onClick={() => setShowChangelog(!showChangelog)}
            disabled={isChecking || applyingUpdate}
            className="btn btn-ghost"
          >
            <List size={16}/>
            {showChangelog ? 'إخفاء ملاحظات الإصدار' : 'عرض ملاحظات الإصدار'}
          </button>
        )}

        {hasUpdate && (
           <button
           type="button"
           onClick={() => handleApplyUpdate(false)}
           disabled={applyingUpdate}
           className="btn btn-primary"
           style={{ background: 'var(--success)', border: 'none' }}
         >
           {applyingUpdate ? 'جاري التحديث...' : 'تحديث الآن'}
         </button>
        )}
      </div>

      {showChangelog && changelog.length > 0 && (
        <div style={{ background: 'var(--bg)', padding: '1rem', borderRadius: 'var(--radius)', maxHeight: '300px', overflowY: 'auto' }}>
          <h4 style={{ margin: '0 0 0.8rem 0', fontSize: '0.9rem' }}>أهم المميزات والإصلاحات الجديدة:</h4>
          <ul style={{ margin: 0, padding: '0 1.2rem', fontSize: '0.85rem', display: 'flex', flexDirection: 'column', gap: '0.6rem' }}>
            {changelog.map((c: any, i: number) => (
              <li key={i}>
                <strong>{typeof c === 'string' ? c : (c as any).message}</strong>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}
