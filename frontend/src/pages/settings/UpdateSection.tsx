import { useCallback, useEffect, useState } from 'react'
import { isAxiosError } from 'axios'
import { RefreshCw, List } from 'lucide-react'
import toast from 'react-hot-toast'
import { applyUpdate, getUpdateJob } from '../../api/endpoints'
import useUpdateStore from '../../store/updateStore'
import { useConfirmStore } from '../../store/confirmStore'
import SectionTitle from '../../components/common/SectionTitle'

export default function UpdateSection() {
  const { confirm } = useConfirmStore()
  const { hasUpdate, currentVersion, latestVersion, changelog, isChecking, forceCheck, updatesDisabled, updatesDisabledMessage, updatesUnreachable, updateErrorMessage } = useUpdateStore()

  const [applyingUpdate, setApplyingUpdate] = useState(false)
  const [showChangelog, setShowChangelog] = useState(false)
  const [updateLogs, setUpdateLogs] = useState<string[]>([])
  const [desktopUpdaterStatus, setDesktopUpdaterStatus] = useState<UpdaterStatus | null>(null)

  const appendUpdaterLog = useCallback((status: UpdaterStatus) => {
    const line = formatUpdaterStatus(status)
    if (!line) return
    setUpdateLogs((logs) => [...logs, line])
  }, [])

  useEffect(() => {
    const unsubscribe = window.electronAPI?.updater?.onStatus?.(async (status) => {
      setDesktopUpdaterStatus(status)
      appendUpdaterLog(status)

      if (status.state === 'ready_to_install' && status.canInstall) {
        setApplyingUpdate(false)
        const shouldInstall = await confirm('تم تحميل التحديث. هل تريد إعادة تشغيل التطبيق الآن لتثبيته؟')
        if (shouldInstall) {
          setApplyingUpdate(true)
          await window.electronAPI?.updater?.install()
        }
      }

      if (status.state === 'error' || status.state === 'update_not_available' || status.state === 'developer_only') {
        setApplyingUpdate(false)
      }
    })

    return () => {
      unsubscribe?.()
    }
  }, [appendUpdaterLog, confirm])

  const handleCheckUpdate = async () => {
    try {
      const data = await forceCheck()
      if (data?.updates_disabled) {
        toast.error(data.message || 'خادم التحديثات غير مهيأ.')
        return
      }
      if (data?.updates_unreachable) {
        toast.error(formatUpdateError(data))
        return
      }
      if (data?.has_update) {
        toast.success('تم العثور على تحديث جديد!')
        setShowChangelog(true)
      } else {
        toast.success('النظام محدّث لأحدث إصدار')
      }
    } catch (error: unknown) {
      const responseData = isAxiosError(error) ? error.response?.data : undefined
      toast.error(formatUpdateError(asRecord(responseData)?.data ?? responseData ?? error))
    }
  }

  const handleApplyUpdate = async (force = false) => {
    const desktopUpdater = window.electronAPI?.updater
    const updaterStatus = desktopUpdater ? await desktopUpdater.getStatus() : null
    if (desktopUpdater && updaterStatus?.isPackaged) {
      setApplyingUpdate(true)
      setUpdateLogs([])
      try {
        const status = await desktopUpdater.download()
        setDesktopUpdaterStatus(status)
        appendUpdaterLog(status)
        if (status.state === 'error') {
          toast.error(status.error || 'فشل تحميل تحديث سطح المكتب.')
          setApplyingUpdate(false)
        }
      } catch (error: unknown) {
        toast.error(error instanceof Error ? error.message : 'فشل تحميل تحديث سطح المكتب.')
        setApplyingUpdate(false)
      }
      return
    }

    if (!force) {
      if (!(await confirm('سيتم إنشاء نسخة احتياطية من قاعدة البيانات ثم تحديث ملفات النظام والمكتبات تلقائياً. هل أنت متأكد من رغبتك بالاستمرار؟ (قد يستغرق الأمر دقيقة أو اثنتين)'))) return
    }
    
    setApplyingUpdate(true)
    setUpdateLogs([])
    try {
      const res = await applyUpdate(force)
      const jobId = res.data?.data?.job_id
      if (!jobId) {
        throw new Error('The update job was not created')
      }

      toast.success('تم وضع التحديث في قائمة التنفيذ. ستتم متابعة حالته تلقائياً.')
      const deadline = Date.now() + 15 * 60 * 1000

      while (Date.now() < deadline) {
        await new Promise((resolve) => window.setTimeout(resolve, 2000))
        const jobResponse = await getUpdateJob(jobId)
        const job = jobResponse.data?.data

        if (!job) {
          throw new Error('Unable to read update job status')
        }

        if (job.status === 'completed') {
          setApplyingUpdate(false)
          toast.success('تم تطبيق التحديث بنجاح! جاري إعادة التحميل...')
          window.setTimeout(() => window.location.reload(), 1000)
          return
        }

        if (job.status === 'failed') {
          const jobError = new Error(job.last_error || 'The update job failed') as Error & { status?: number }
          jobError.status = job.failure_code ?? undefined
          throw jobError
        }

        setUpdateLogs(['Update job ' + job.status + '...'])
      }

      throw new Error('The update job exceeded the monitoring timeout')
    } catch (error: unknown) {
      const status = isAxiosError(error)
        ? error.response?.status
        : (error as { status?: number })?.status
      const errData = isAxiosError(error)
        ? error.response?.data as { message?: string, errors?: { logs?: string[] }, data?: { logs?: string[] } } | undefined
        : undefined
      const msg = errData?.message
        || (error instanceof Error ? error.message : null)
        || 'فشل تطبيق التحديث.'
      const logs = errData?.errors?.logs || errData?.data?.logs || [
        error instanceof Error ? error.message : 'Update job failed',
      ]
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
      
      {updatesDisabled ? (
        <div style={{
          padding: '1rem', 
          borderRadius: 'var(--radius)', 
          background: 'rgba(239, 68, 68, 0.1)',
          border: '1px solid rgba(239, 68, 68, 0.3)'
        }}>
          <h3 style={{ margin: '0 0 0.5rem 0', fontSize: '1rem', color: 'var(--danger)' }}>
            ⚠️ {updatesDisabledMessage || 'خادم التحديثات غير مهيأ.'}
          </h3>
          <p style={{ margin: 0, fontSize: '0.85rem' }}>
            الإصدار الحالي: <strong>{currentVersion ? `v${currentVersion}` : 'غير معروف'}</strong>
          </p>
        </div>
      ) : updatesUnreachable ? (
        <div style={{
          padding: '1rem', 
          borderRadius: 'var(--radius)', 
          background: 'rgba(245, 158, 11, 0.1)',
          border: '1px solid rgba(245, 158, 11, 0.3)'
        }}>
          <h3 style={{ margin: '0 0 0.5rem 0', fontSize: '1rem', color: 'var(--warning)' }}>
            ⚠️ {updateErrorMessage || 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.'}
          </h3>
          <p style={{ margin: 0, fontSize: '0.85rem' }}>
            الإصدار الحالي: <strong>{currentVersion ? `v${currentVersion}` : 'غير معروف'}</strong>
          </p>
        </div>
      ) : (
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
      )}

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
           {formatUpdateButtonLabel(applyingUpdate, desktopUpdaterStatus)}
         </button>
        )}
      </div>

      {showChangelog && changelog.length > 0 && (
        <div style={{ background: 'var(--bg)', padding: '1rem', borderRadius: 'var(--radius)', maxHeight: '300px', overflowY: 'auto' }}>
          <h4 style={{ margin: '0 0 0.8rem 0', fontSize: '0.9rem' }}>أهم المميزات والإصلاحات الجديدة:</h4>
          <ul style={{ margin: 0, padding: '0 1.2rem', fontSize: '0.85rem', display: 'flex', flexDirection: 'column', gap: '0.6rem' }}>
            {changelog.map((entry) => (
              <li key={`${entry.version}-${entry.date}`}>
                <strong>{entry.version}</strong>
                {entry.changes.length > 0 ? ` — ${entry.changes.join(' • ')}` : ''}
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}

function formatUpdateButtonLabel(applyingUpdate: boolean, status: UpdaterStatus | null): string {
  if (status?.state === 'downloading' && status.progress?.percent != null) {
    return `جاري التحميل ${Math.round(status.progress.percent)}%`
  }
  if (status?.state === 'ready_to_install') return 'جاهز للتثبيت'
  if (status?.state === 'restarting') return 'جاري إعادة التشغيل...'
  return applyingUpdate ? 'جاري التحديث...' : 'تحديث الآن'
}

function formatUpdaterStatus(status: UpdaterStatus): string {
  switch (status.state) {
    case 'checking':
      return '🔎 جاري التحقق من بيانات إصدار GitHub...'
    case 'update_available':
      return `✅ تحديث سطح مكتب متاح${status.updateInfo?.version ? `: v${status.updateInfo.version}` : ''}`
    case 'update_not_available':
      return '✅ لا يوجد تحديث سطح مكتب جديد في GitHub Releases.'
    case 'downloading':
      return `📥 جاري تنزيل التحديث${status.progress?.percent != null ? ` (${Math.round(status.progress.percent)}%)` : '...'}`
    case 'ready_to_install':
      return '✅ تم تحميل التحديث وأصبح جاهزاً للتثبيت.'
    case 'restarting':
      return '🔄 سيتم إعادة تشغيل التطبيق لتثبيت التحديث.'
    case 'developer_only':
      return '⚠️ تحديث سطح المكتب عبر Electron متاح في النسخة المعبأة فقط.'
    case 'error':
      return `❌ ${status.error || 'فشل تحديث سطح المكتب.'}`
    default:
      return ''
  }
}

function formatUpdateError(value: unknown): string {
  const data = asRecord(value)
  const baseMessage = typeof data?.message === 'string'
    ? data.message
    : 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.'
  const errorCode = typeof data?.errorCode === 'string'
    ? data.errorCode
    : (typeof data?.status === 'string' ? data.status : null)
  const details = typeof data?.details === 'string' ? data.details : null
  const checkedUrl = typeof data?.checkedUrl === 'string' ? data.checkedUrl : null
  const technicalParts = [
    errorCode ? `code: ${errorCode}` : null,
    details ? `details: ${details}` : null,
    checkedUrl ? `url: ${checkedUrl}` : null,
  ].filter(Boolean)

  return technicalParts.length ? `${baseMessage} (${technicalParts.join(' | ')})` : baseMessage
}

function asRecord(value: unknown): Record<string, unknown> | undefined {
  return value !== null && typeof value === 'object'
    ? value as Record<string, unknown>
    : undefined
}
