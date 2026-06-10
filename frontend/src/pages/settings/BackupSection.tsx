import { useState, useRef } from 'react'
import { Database, Download, Upload } from 'lucide-react'
import toast from 'react-hot-toast'
import { downloadBackup, restoreBackup } from '../../api/endpoints'
import useSettingsStore from '../../store/settingsStore'
import { useConfirmStore } from '../../store/confirmStore'
import SectionTitle from '../../components/common/SectionTitle'
import { extractApiError } from '../../utils/apiError'

export default function BackupSection() {
  const { fetchSettings } = useSettingsStore()
  const { confirm } = useConfirmStore()

  const [backing, setBacking] = useState(false)
  const [restoring, setRestoring] = useState(false)
  const restoreInputRef = useRef<HTMLInputElement>(null)

  const handleBackup = async () => {
    setBacking(true)
    try {
      const res = await downloadBackup()
      const url = window.URL.createObjectURL(new Blob([res.data]))
      const link = document.createElement('a')
      link.href = url
      link.download = `pos_backup_${new Date().toISOString().slice(0, 10)}.sql`
      link.click()
      window.URL.revokeObjectURL(url)
      toast.success('تم تحميل النسخة الاحتياطية')
    } catch (err: any) { 
      toast.error(extractApiError(err, 'فشل تحميل النسخة الاحتياطية')) 
    } finally {
      setBacking(false)
    }
  }

  const handleRestorePick = () => restoreInputRef.current?.click()

  const handleRestoreFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    if (!file.name.toLowerCase().endsWith('.sql')) {
      toast.error('اختر ملفاً بصيغة .sql')
      return
    }
    if (!(await confirm('سيتم استبدال قاعدة البيانات الحالية بالكامل بمحتوى الملف. لن يمكن التراجع تلقائياً. هل تريد المتابعة؟'))) {
      return
    }
    setRestoring(true)
    try {
      const fd = new FormData()
      fd.append('sql_file', file)
      await restoreBackup(fd)
      toast.success('تمت استعادة قاعدة البيانات')
      await fetchSettings()
    } catch (err: any) {
      toast.error(extractApiError(err, 'فشلت الاستعادة'))
    } finally {
      setRestoring(false)
    }
  }

  return (
    <section className="card" style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <SectionTitle icon={<Database size={16}/>} label="النسخ الاحتياطي" />
      <p style={{ fontSize: '0.9rem', color: 'var(--text-muted)', margin: 0 }}>
        تحميل نسخة احتياطية كاملة من قاعدة البيانات بصيغة SQL، أو استعادة نسخة سابقة من ملف تم تصديره من هنا.
      </p>
      <input
        ref={restoreInputRef}
        type="file"
        accept=".sql,text/plain"
        style={{ display: 'none' }}
        onChange={handleRestoreFile}
      />
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
        <button
          type="button"
          onClick={handleBackup}
          disabled={backing || restoring}
          className="btn btn-secondary"
        >
          {backing ? <span className="spinner" /> : <Download size={16}/>}
          {backing ? 'جاري التحميل…' : 'تحميل نسخة احتياطية'}
        </button>
        <button
          type="button"
          onClick={handleRestorePick}
          disabled={backing || restoring}
          className="btn btn-danger"
        >
          {restoring ? <span className="spinner" /> : <Upload size={16}/>}
          {restoring ? 'جاري الاستعادة…' : 'استعادة من ملف SQL'}
        </button>
      </div>
      <p style={{ fontSize: '0.78rem', color: 'var(--danger)', margin: 0 }}>
        تحذير: الاستعادة تمسح البيانات الحالية وتستبدلها بمحتوى الملف. استخدم نسخاً احتياطياً موثوقاً فقط.
      </p>
    </section>
  )
}
