import { useCallback, useEffect, useState } from 'react'
import { isAxiosError } from 'axios'
import { 
  RefreshCw, 
  List, 
  Download, 
  History, 
  RotateCcw, 
  ShieldCheck, 
  AlertTriangle, 
  CheckCircle2, 
  FileCode2, 
  Clock, 
  ExternalLink,
  ChevronDown,
  ChevronUp
} from 'lucide-react'
import toast from 'react-hot-toast'
import { 
  getUpdateStatus, 
  checkUpdate, 
  applyUpdate, 
  getUpdateHistory, 
  rollbackUpdate, 
  getUpdateSnapshots 
} from '../../api/endpoints'
import useUpdateStore from '../../store/updateStore'
import { useConfirmStore } from '../../store/confirmStore'
import SectionTitle from '../../components/common/SectionTitle'

export default function UpdateSection() {
  const { confirm } = useConfirmStore()
  const { 
    hasUpdate, 
    currentVersion, 
    latestVersion, 
    changelog, 
    isChecking, 
    forceCheck, 
    updatesDisabled, 
    updatesDisabledMessage, 
    updatesUnreachable, 
    updateErrorMessage 
  } = useUpdateStore()

  const [applyingUpdate, setApplyingUpdate] = useState(false)
  const [showChangelog, setShowChangelog] = useState(false)
  const [showHistory, setShowHistory] = useState(false)
  const [showSnapshots, setShowSnapshots] = useState(false)
  const [updateLogs, setUpdateLogs] = useState<string[]>([])
  
  // Status and Real-time progress data
  const [updateStatus, setUpdateStatus] = useState<UpdateStatusData | null>(null)
  const [historyList, setHistoryList] = useState<UpdateHistoryRecord[]>([])
  const [snapshotsList, setSnapshotsList] = useState<UpdateSnapshot[]>([])
  const [rollingBack, setRollingBack] = useState(false)

  const activeChangelog: unknown[] = Array.isArray(changelog) && changelog.length > 0
    ? changelog
    : (Array.isArray(updateStatus?.release_info?.changelog) ? updateStatus.release_info.changelog : [])


  // Fetch initial status and history
  const loadStatusAndHistory = useCallback(async () => {
    try {
      const [statusRes, histRes, snapRes] = await Promise.allSettled([
        getUpdateStatus(),
        getUpdateHistory(),
        getUpdateSnapshots(),
      ])

      if (statusRes.status === 'fulfilled' && statusRes.value.data?.data) {
        setUpdateStatus(statusRes.value.data.data)
      }
      if (histRes.status === 'fulfilled' && Array.isArray(histRes.value.data?.data)) {
        setHistoryList(histRes.value.data.data)
      }
      if (snapRes.status === 'fulfilled' && Array.isArray(snapRes.value.data?.data)) {
        setSnapshotsList(snapRes.value.data.data)
      }
    } catch {
      // Background poll failure is non-blocking
    }
  }, [])

  useEffect(() => {
    loadStatusAndHistory()
  }, [loadStatusAndHistory])

  // Polling update progress during apply
  useEffect(() => {
    if (!applyingUpdate) return

    const interval = setInterval(async () => {
      try {
        const res = await getUpdateStatus()
        if (res.data?.data) {
          setUpdateStatus(res.data.data)
          const state = res.data.data.update_state?.state
          if (state === 'completed') {
            setApplyingUpdate(false)
            toast.success('🎉 تم استكمال التحديث بنجاح! جاري إعادة التحميل...')
            setTimeout(() => window.location.reload(), 1500)
          } else if (state === 'failed' || state === 'rolled_back') {
            setApplyingUpdate(false)
            toast.error(res.data.data.update_state?.error || 'فشل تطبيق التحديث وتم التراجع التلقائي.')
          }
        }
      } catch {
        // Continue polling
      }
    }, 2000)

    return () => clearInterval(interval)
  }, [applyingUpdate])

  const handleCheckUpdate = async () => {
    try {
      const data = await forceCheck()
      await loadStatusAndHistory()

      if (data?.updates_disabled) {
        toast.error(data.message || 'خادم التحديثات غير مهيأ.')
        return
      }
      if (data?.updates_unreachable) {
        toast.error(formatUpdateError(data))
        return
      }
      if (data?.has_update) {
        toast.success('🎉 تم العثور على تحديث جديد متوافق!')
        setShowChangelog(true)
      } else {
        toast.success('✅ النظام محدّث لأحدث إصدار.')
      }
    } catch (error: unknown) {
      const responseData = isAxiosError(error) ? error.response?.data : undefined
      toast.error(formatUpdateError(asRecord(responseData)?.data ?? responseData ?? error))
    }
  }

  const handleApplyUpdate = async (force = false) => {
    const isDelta = updateStatus?.type === 'delta' || hasUpdate
    const updateTypeLabel = isDelta ? 'تحديث جزئي سريع (Delta Patch)' : 'تحديث كامل (Full Package)'

    if (!force) {
      const confirmed = await confirm(
        `تأكيد تثبيت التحديث (${updateTypeLabel}):\n\n• سيتم أخذ نسخة احتياطية ذرية لقاعدة البيانات والملفات.\n• سيتم التحقق من التوقيع الرقمي RSA وسلامة الملفات.\n• في حال حدوث أي خطأ، سيتم التراجع التلقائي الفوري.\n\nهل ترغب في بدء التحديث الآن؟`
      )
      if (!confirmed) return
    }

    setApplyingUpdate(true)
    setUpdateLogs(['🚀 بدء عملية التحديث...', '⏳ جاري الاتصال بخادم التحديثات وتجهيز الملفات...'])

    try {
      const res = await applyUpdate(force)
      const data = res.data?.data
      if (data?.logs && Array.isArray(data.logs)) {
        setUpdateLogs(data.logs)
      }

      toast.success('🎉 تم تطبيق التحديث بنجاح! جاري تنشيط النظام...')
      await loadStatusAndHistory()
      setTimeout(() => window.location.reload(), 1500)
    } catch (error: unknown) {
      setApplyingUpdate(false)
      const errData = isAxiosError(error)
        ? (error.response?.data as { message?: string; errors?: { logs?: string[] }; data?: { logs?: string[] } })
        : undefined
      const msg = errData?.message || (error instanceof Error ? error.message : 'فشل تطبيق التحديث.')
      const logs = errData?.errors?.logs || errData?.data?.logs || [error instanceof Error ? error.message : 'Update failed']
      setUpdateLogs(logs)
      await loadStatusAndHistory()

      if (isAxiosError(error) && error.response?.status === 409 && !force) {
        const forceConfirm = await confirm(
          'توجد تعديلات محلية في بعض ملفات النظام. سيتم استبدالها بالنسخة الأصلية المعتمدة من التحديث. هل تريد المتابعة؟'
        )
        if (forceConfirm) {
          handleApplyUpdate(true)
        }
        return
      }

      toast.error(msg)
    }
  }

  const handleRollback = async (snapshotPath?: string) => {
    const targetLabel = snapshotPath ? `من اللقطة: ${snapshotPath.split('/').pop()}` : 'إلى آخر نسخة احتياطية سابقة'
    const confirmed = await confirm(
      `⚠️ تحذير: التراجع عن التحديث ${targetLabel}:\n\nسيتم استرجاع ملفات النظام وقاعدة البيانات إلى الحالة السابقة للقطة المحددة. هل أنت متأكد؟`
    )
    if (!confirmed) return

    setRollingBack(true)
    setUpdateLogs(['🔄 جاري استرجاع الملفات من اللقطة الاحتياطية...'])
    try {
      const res = await rollbackUpdate(snapshotPath)
      const logs = res.data?.data?.logs || []
      setUpdateLogs(logs)
      toast.success('✅ تم التراجع عن التحديث بنجاح!')
      await loadStatusAndHistory()
      setTimeout(() => window.location.reload(), 1200)
    } catch (error: unknown) {
      const msg = isAxiosError(error) ? error.response?.data?.message : (error instanceof Error ? error.message : 'فشل التراجع.')
      toast.error(msg || 'فشل التراجع عن التحديث.')
    } finally {
      setRollingBack(false)
    }
  }

  const activeState = updateStatus?.update_state?.state || 'idle'

  return (
    <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
      {/* Header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '0.75rem' }}>
        <div>
          <SectionTitle icon={<RefreshCw size={18} className={isChecking ? 'spin' : ''} />} label="مركز إدارة التحديثات (Admin Update Center)" />
          <p style={{ fontSize: '0.85rem', color: 'var(--text-muted)', margin: '0.25rem 0 0 0' }}>
            نظام التحديثات الجزئية المباشر عبر GitHub Releases مع التحقق من التوقيع الرقمي RSA والنسخ الاحتياطي التلقائي.
          </p>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
          <span style={{ 
            fontSize: '0.8rem', 
            padding: '0.25rem 0.6rem', 
            borderRadius: '999px', 
            background: 'var(--card-bg, #2a2a3e)', 
            border: '1px solid var(--border)',
            display: 'flex',
            alignItems: 'center',
            gap: '0.35rem'
          }}>
            <ShieldCheck size={14} style={{ color: '#4ade80' }} />
            <span>RSA-2048 Verified</span>
          </span>
        </div>
      </div>

      {/* Interrupted Update Recovery Banner */}
      {updateStatus?.interrupted_update?.interrupted && (
        <div style={{
          padding: '1.2rem',
          borderRadius: '8px',
          background: 'rgba(239, 68, 68, 0.12)',
          border: '1px solid rgba(239, 68, 68, 0.4)',
          display: 'flex',
          flexDirection: 'column',
          gap: '0.75rem'
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', color: '#f87171', fontWeight: 700, fontSize: '0.95rem' }}>
            <AlertTriangle size={20} />
            <span>⚠️ تنبيه: تم مقاطعة عملية تحديث سابقة بشكل مفاجئ (انقطاع كهرباء أو إغلاق التطبيق)</span>
          </div>

          <p style={{ margin: 0, fontSize: '0.85rem', color: '#cbd5e1', lineHeight: 1.5 }}>
            {updateStatus.interrupted_update.message || 'تم مقاطعة عملية التحديث قبل اكتمالها.'}
            {updateStatus.interrupted_update.snapshot_path && ' تتوفر لقطة نسخ احتياطي سابقة يمكن الرجوع إليها بأمان.'}
          </p>

          <div style={{ display: 'flex', gap: '0.6rem', flexWrap: 'wrap', marginTop: '0.25rem' }}>
            {updateStatus.interrupted_update.snapshot_path && (
              <button
                type="button"
                onClick={() => handleRollback(updateStatus.interrupted_update?.snapshot_path || undefined)}
                disabled={rollingBack || applyingUpdate}
                className="btn btn-secondary btn-sm"
                style={{ background: '#ef4444', color: '#fff', border: 'none' }}
              >
                <RotateCcw size={14} /> استرجاع النسخة السابقة فوراً (Rollback)
              </button>
            )}

            <button
              type="button"
              onClick={() => handleApplyUpdate(true)}
              disabled={rollingBack || applyingUpdate}
              className="btn btn-secondary btn-sm"
            >
              <RefreshCw size={14} /> إعادة محاولة التحديث (Resume / Retry)
            </button>
          </div>
        </div>
      )}

      {/* Main Status Banner */}
      {updatesDisabled ? (

        <div style={{ padding: '1rem', borderRadius: '8px', background: 'rgba(239, 68, 68, 0.1)', border: '1px solid rgba(239, 68, 68, 0.3)' }}>
          <h3 style={{ margin: '0 0 0.5rem 0', fontSize: '0.95rem', color: 'var(--danger)', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <AlertTriangle size={18} /> {updatesDisabledMessage || 'خادم التحديثات غير مهيأ.'}
          </h3>
          <p style={{ margin: 0, fontSize: '0.85rem' }}>
            الإصدار الحالي: <strong>{currentVersion ? `v${currentVersion}` : 'غير معروف'}</strong>
          </p>
        </div>
      ) : updatesUnreachable ? (
        <div style={{ padding: '1rem', borderRadius: '8px', background: 'rgba(245, 158, 11, 0.1)', border: '1px solid rgba(245, 158, 11, 0.3)' }}>
          <h3 style={{ margin: '0 0 0.5rem 0', fontSize: '0.95rem', color: 'var(--warning)', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <AlertTriangle size={18} /> {updateErrorMessage || 'تعذر الاتصال بخادم التحديثات.'}
          </h3>
          <p style={{ margin: 0, fontSize: '0.85rem' }}>
            الإصدار الحالي: <strong>{currentVersion ? `v${currentVersion}` : 'غير معروف'}</strong>
          </p>
        </div>
      ) : (
        <div style={{
          padding: '1.2rem',
          borderRadius: '10px',
          background: hasUpdate ? 'linear-gradient(135deg, rgba(34, 197, 94, 0.12), rgba(16, 185, 129, 0.05))' : 'var(--bg)',
          border: hasUpdate ? '1px solid rgba(34, 197, 94, 0.35)' : '1px solid var(--border)',
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
          gap: '1rem',
          alignItems: 'center'
        }}>
          <div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginBottom: '0.2rem' }}>الإصدار الحالي المثبت</div>
            <div style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--text)' }}>
              v{currentVersion || '0.0.0'}
            </div>
          </div>

          <div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginBottom: '0.2rem' }}>أحدث إصدار متاح</div>
            <div style={{ fontSize: '1.25rem', fontWeight: 700, color: hasUpdate ? '#22c55e' : 'var(--text)' }}>
              {latestVersion ? `v${latestVersion}` : 'v' + (currentVersion || '0.0.0')}
            </div>
          </div>

          <div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginBottom: '0.2rem' }}>نوع التحديث</div>
            <div>
              <span style={{
                fontSize: '0.8rem',
                fontWeight: 600,
                padding: '0.2rem 0.5rem',
                borderRadius: '6px',
                background: updateStatus?.type === 'delta' ? 'rgba(59, 130, 246, 0.15)' : 'rgba(156, 163, 175, 0.15)',
                color: updateStatus?.type === 'delta' ? '#60a5fa' : 'var(--text-muted)',
                border: '1px solid rgba(255, 255, 255, 0.1)'
              }}>
                {updateStatus?.type === 'delta' ? '📦 Delta (جزئي)' : '💿 Full (كامل)'}
              </span>
            </div>
          </div>

          <div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginBottom: '0.2rem' }}>حالة النظام</div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.9rem', fontWeight: 600, color: hasUpdate ? '#22c55e' : '#38bdf8' }}>
              {hasUpdate ? <CheckCircle2 size={16} /> : <CheckCircle2 size={16} />}
              {hasUpdate ? 'تحديث متاح للتثبيت' : 'النظام محدّث لأحدث إصدار'}
            </div>
          </div>
        </div>
      )}

      {/* Release Information Card (When update available) */}
      {hasUpdate && updateStatus?.release_info && (
        <div style={{ padding: '1rem', background: 'rgba(59, 130, 246, 0.05)', border: '1px solid rgba(59, 130, 246, 0.2)', borderRadius: '8px' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
            <h4 style={{ margin: 0, fontSize: '0.95rem', color: '#60a5fa', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
              <FileCode2 size={16} /> معلومات الإصدار {updateStatus.release_info.tag_name}
            </h4>
            {updateStatus.release_info.release_url && (
              <a 
                href={updateStatus.release_info.release_url} 
                target="_blank" 
                rel="noreferrer" 
                style={{ fontSize: '0.8rem', color: '#93c5fd', textDecoration: 'none', display: 'flex', alignItems: 'center', gap: '0.25rem' }}
              >
                GitHub Release <ExternalLink size={12} />
              </a>
            )}
          </div>

          <div style={{ display: 'flex', gap: '1.5rem', fontSize: '0.8rem', color: 'var(--text-muted)', flexWrap: 'wrap' }}>
            {updateStatus.release_info.released_at && (
              <span><Clock size={12} style={{ display: 'inline', verticalAlign: 'middle' }} /> تاريخ النشر: {new Date(updateStatus.release_info.released_at).toLocaleDateString('ar-EG')}</span>
            )}
            {updateStatus.release_info.files_count != null && (
              <span><FileCode2 size={12} style={{ display: 'inline', verticalAlign: 'middle' }} /> عدد الملفات المعدلة: {updateStatus.release_info.files_count} ملف(ات)</span>
            )}
          </div>
        </div>
      )}

      {/* Progress State Tracker Bar */}
      {applyingUpdate && (
        <div style={{ padding: '1rem', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: '8px' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
            <span style={{ fontSize: '0.85rem', fontWeight: 600, color: '#38bdf8' }}>
              مرحلة التحديث الحالية: {getStageLabel(activeState)}
            </span>
            <span className="spinner" style={{ width: '14px', height: '14px' }} />
          </div>
          <div style={{ height: '6px', width: '100%', background: 'rgba(255,255,255,0.1)', borderRadius: '999px', overflow: 'hidden' }}>
            <div style={{
              height: '100%',
              width: `${getStagePercent(activeState)}%`,
              background: 'linear-gradient(90deg, #3b82f6, #10b981)',
              transition: 'width 0.4s ease'
            }} />
          </div>
        </div>
      )}

      {/* Terminal Live Logs */}
      {(applyingUpdate || rollingBack || updateLogs.length > 0) && (
        <div style={{ background: '#12131e', borderRadius: '8px', border: '1px solid #232538', padding: '0.85rem 1rem' }}>
          <div style={{ fontSize: '0.75rem', color: '#94a3b8', marginBottom: '0.5rem', fontWeight: 600 }}>سجل العمليات المباشر (Update Live Log):</div>
          <div style={{
            fontFamily: 'Consolas, monospace',
            fontSize: '0.8rem',
            maxHeight: '220px',
            overflowY: 'auto',
            display: 'flex',
            flexDirection: 'column',
            gap: '0.25rem',
            direction: 'ltr',
            textAlign: 'left'
          }}>
            {updateLogs.map((log, index) => (
              <div key={index} style={{
                color: log.startsWith('✅') || log.startsWith('🎉') ? '#4ade80' : log.startsWith('⚠️') ? '#facc15' : log.startsWith('❌') ? '#f87171' : '#e2e8f0',
                lineHeight: 1.4
              }}>
                {log}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Action Buttons */}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.6rem', alignItems: 'center' }}>
        <button
          type="button"
          onClick={handleCheckUpdate}
          disabled={isChecking || applyingUpdate || rollingBack}
          className="btn btn-secondary"
        >
          {isChecking ? <span className="spinner" /> : <RefreshCw size={16} />}
          {isChecking ? 'جاري الفحص...' : 'التحقق من التحديثات'}
        </button>

        {hasUpdate && (
          <button
            type="button"
            onClick={() => handleApplyUpdate(false)}
            disabled={applyingUpdate || rollingBack}
            className="btn btn-primary"
            style={{ background: '#16a34a', borderColor: '#16a34a', color: '#fff' }}
          >
            {applyingUpdate ? <span className="spinner" /> : <Download size={16} />}
            {applyingUpdate ? 'جاري تثبيت التحديث...' : 'تثبيت التحديث الآن'}
          </button>
        )}

        {activeChangelog.length > 0 && (
          <button
            type="button"
            onClick={() => setShowChangelog(!showChangelog)}
            className="btn btn-ghost"
          >
            <List size={16} />
            {showChangelog ? 'إخفاء الملاحظات' : 'ملاحظات الإصدار'}
          </button>
        )}

        <button
          type="button"
          onClick={() => setShowHistory(!showHistory)}
          className="btn btn-ghost"
        >
          <History size={16} />
          {showHistory ? 'إخفاء السجل' : 'سجل التحديثات'}
        </button>

        {snapshotsList.length > 0 && (
          <button
            type="button"
            onClick={() => setShowSnapshots(!showSnapshots)}
            className="btn btn-ghost"
            style={{ color: '#f59e0b' }}
          >
            <RotateCcw size={16} />
            {showSnapshots ? 'إخفاء نقاط الاسترجاع' : `نقاط الاسترجاع (${snapshotsList.length})`}
          </button>
        )}
      </div>

      {/* Changelog Dropdown */}
      {showChangelog && activeChangelog.length > 0 && (
        <div style={{ background: 'var(--bg)', padding: '1rem', borderRadius: '8px', border: '1px solid var(--border)' }}>
          <h4 style={{ margin: '0 0 0.6rem 0', fontSize: '0.88rem' }}>سجل التغييرات والمميزات:</h4>
          <ul style={{ margin: 0, padding: '0 1.2rem', fontSize: '0.85rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
            {activeChangelog.map((entry, idx) => renderChangelogEntry(entry, idx))}
          </ul>
        </div>
      )}


      {/* Snapshots Rollback Section */}
      {showSnapshots && snapshotsList.length > 0 && (
        <div style={{ background: 'rgba(245, 158, 11, 0.04)', padding: '1rem', borderRadius: '8px', border: '1px solid rgba(245, 158, 11, 0.25)' }}>
          <h4 style={{ margin: '0 0 0.75rem 0', fontSize: '0.9rem', color: '#f59e0b', display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
            <RotateCcw size={16} /> نقاط الاسترجاع المتاحة (Rollback Snapshots)
          </h4>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
            {snapshotsList.map((snap) => (
              <div key={snap.snapshot_name} style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                padding: '0.6rem 0.85rem',
                background: 'var(--bg)',
                borderRadius: '6px',
                border: '1px solid var(--border)',
                flexWrap: 'wrap',
                gap: '0.5rem'
              }}>
                <div>
                  <div style={{ fontSize: '0.85rem', fontWeight: 600 }}>
                    {snap.snapshot_name} (من v{snap.from_version} إلى v{snap.to_version})
                  </div>
                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                    التاريخ: {snap.timestamp} | الملفات: {snap.files_count} | قاعدة البيانات: {snap.has_db_backup ? 'مضمونة ✅' : 'غير متوفرة'}
                  </div>
                </div>

                <button
                  type="button"
                  onClick={() => handleRollback(snap.snapshot_path)}
                  disabled={applyingUpdate || rollingBack}
                  className="btn btn-secondary btn-sm"
                  style={{ color: '#ef4444', borderColor: 'rgba(239, 68, 68, 0.4)' }}
                >
                  <RotateCcw size={14} /> استرجاع هذه النسخة
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* History Table */}
      {showHistory && (
        <div style={{ background: 'var(--bg)', padding: '1rem', borderRadius: '8px', border: '1px solid var(--border)', overflowX: 'auto' }}>
          <h4 style={{ margin: '0 0 0.75rem 0', fontSize: '0.9rem' }}>سجل عمليات التحديث السابقة:</h4>
          {historyList.length === 0 ? (
            <div style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>لا توجد عمليات تحديث مسجلة حتى الآن.</div>
          ) : (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.8rem', textAlign: 'right' }}>
              <thead>
                <tr style={{ borderBottom: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                  <th style={{ padding: '0.4rem' }}>التاريخ</th>
                  <th style={{ padding: '0.4rem' }}>من إصدار</th>
                  <th style={{ padding: '0.4rem' }}>إلى إصدار</th>
                  <th style={{ padding: '0.4rem' }}>النوع</th>
                  <th style={{ padding: '0.4rem' }}>المصدر</th>
                  <th style={{ padding: '0.4rem' }}>الملفات</th>
                  <th style={{ padding: '0.4rem' }}>الحالة</th>
                </tr>
              </thead>
              <tbody>
                {historyList.map((item) => (
                  <tr key={item.id} style={{ borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                    <td style={{ padding: '0.5rem 0.4rem' }}>{item.created_at}</td>
                    <td style={{ padding: '0.5rem 0.4rem' }}>v{item.from_version}</td>
                    <td style={{ padding: '0.5rem 0.4rem' }}>v{item.to_version}</td>
                    <td style={{ padding: '0.5rem 0.4rem' }}>
                      <span style={{ 
                        padding: '0.15rem 0.4rem', 
                        borderRadius: '4px', 
                        fontSize: '0.75rem',
                        background: item.type === 'delta' ? 'rgba(59, 130, 246, 0.15)' : 'rgba(156, 163, 175, 0.15)',
                        color: item.type === 'delta' ? '#60a5fa' : 'var(--text-muted)'
                      }}>
                        {item.type}
                      </span>
                    </td>
                    <td style={{ padding: '0.5rem 0.4rem' }}>{item.source || 'github_release'}</td>
                    <td style={{ padding: '0.5rem 0.4rem' }}>{item.files_count}</td>
                    <td style={{ padding: '0.5rem 0.4rem' }}>
                      <span style={{
                        padding: '0.15rem 0.4rem',
                        borderRadius: '4px',
                        fontSize: '0.75rem',
                        background: item.status === 'success' ? 'rgba(34, 197, 94, 0.15)' : item.status === 'rolled_back' ? 'rgba(245, 158, 11, 0.15)' : 'rgba(239, 68, 68, 0.15)',
                        color: item.status === 'success' ? '#22c55e' : item.status === 'rolled_back' ? '#f59e0b' : '#ef4444'
                      }}>
                        {item.status === 'success' ? 'ناجح ✅' : item.status === 'rolled_back' ? 'تم التراجع 🔄' : 'فشل ❌'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}
    </section>
  )
}

function renderChangelogEntry(entry: unknown, index: number) {
  if (typeof entry === 'string') {
    return (
      <li key={index} style={{ lineHeight: 1.6 }}>
        {entry}
      </li>
    )
  }

  if (entry && typeof entry === 'object') {
    const obj = entry as { version?: string; date?: string; changes?: unknown };
    const versionLabel = obj.version ? `v${obj.version}` : ''
    const dateLabel = obj.date ? ` (${obj.date})` : ''
    const title = `${versionLabel}${dateLabel}`.trim()
    const changes = Array.isArray(obj.changes)
      ? obj.changes.filter((c): c is string => typeof c === 'string')
      : []

    return (
      <li key={index} style={{ lineHeight: 1.6 }}>
        {title && <strong style={{ color: 'var(--text)' }}>{title}</strong>}
        {changes.length > 0 && (
          <span>{title ? ' — ' : ''}{changes.join(' • ')}</span>
        )}
      </li>
    )
  }

  return null
}

function getStageLabel(state: string): string {

  switch (state) {
    case 'downloading': return 'تحميل حزمة التحديث من GitHub Releases...'
    case 'verifying': return 'فحص التوقيع الرقمي RSA وشهادات الأمان...'
    case 'backing_up': return 'إنشاء لقطة النسخ الاحتياطي الذرية...'
    case 'applying': return 'استبدال ملفات النظام بالنسخ الجديدة...'
    case 'migrating': return 'تطبيق تحديثات وهيكلة قاعدة البيانات...'
    case 'completed': return 'اكتمل التحديث بنجاح.'
    case 'rolled_back': return 'تم التراجع التلقائي بنجاح.'
    case 'failed': return 'حدث خطأ أثناء التحديث.'
    default: return 'تجهيز العملية...'
  }
}

function getStagePercent(state: string): number {
  switch (state) {
    case 'downloading': return 25
    case 'verifying': return 45
    case 'backing_up': return 65
    case 'applying': return 80
    case 'migrating': return 92
    case 'completed': return 100
    default: return 15
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
