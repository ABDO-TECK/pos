import React, { useEffect, useState } from 'react'
import { getConflictedSales, deletePendingSale, updatePendingSaleStatus } from '../utils/idb'
import { syncPendingSales } from '../utils/offlineSync'
import toast from 'react-hot-toast'

interface ConflictItem {
  localId: IDBValidKey
  savedAt: string
  lastError?: string
  syncStatus?: string
  saleData?: Record<string, unknown>
}

export const ConflictResolutionDialog: React.FC = () => {
  const [conflicts, setConflicts] = useState<ConflictItem[]>([])
  const [isOpen, setIsOpen] = useState(false)

  const loadConflicts = async () => {
    const data = await getConflictedSales()
    setConflicts(data as unknown as ConflictItem[])
  }

  useEffect(() => {
    loadConflicts()
    // الاستماع لحدث الأونلاين للتحقق من التعارضات بعد المزامنة التلقائية
    window.addEventListener('online', () => setTimeout(loadConflicts, 5000))
    return () => window.removeEventListener('online', () => setTimeout(loadConflicts, 5000))
  }, [])

  if (conflicts.length === 0) return null

  const handleRetryAll = async () => {
    for (const c of conflicts) {
      await updatePendingSaleStatus(c.localId, 'pending')
    }
    toast.loading('جاري إعادة مزامنة التعارضات...', { id: 'retryAll' })
    await syncPendingSales()
    toast.success('اكتملت المحاولة', { id: 'retryAll' })
    loadConflicts()
  }

  const handleDiscard = async (localId: IDBValidKey) => {
    if (window.confirm('هل أنت متأكد من حذف هذه العملية المحفوظة محلياً؟ لا يمكن التراجع.')) {
      await deletePendingSale(localId)
      toast.success('تم الحذف بنجاح')
      loadConflicts()
    }
  }

  const handleRetryOne = async (localId: IDBValidKey) => {
    await updatePendingSaleStatus(localId, 'pending')
    await syncPendingSales()
    loadConflicts()
  }

  return (
    <>
      {/* أيقونة التحذير في الشريط العلوي (أو عائمة) */}
      <div 
        onClick={() => setIsOpen(true)}
        className="fixed bottom-4 left-4 bg-red-600 text-white p-3 rounded-full shadow-lg cursor-pointer flex items-center gap-2 hover:bg-red-700 transition z-50 animate-bounce"
        title="يوجد عمليات بيع متعارضة"
      >
        <span>⚠️</span>
        <span className="font-bold">{conflicts.length} متعارضة</span>
      </div>

      {/* نافذة عرض التعارضات */}
      {isOpen && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-2xl flex flex-col max-h-[90vh]">
            <div className="p-4 border-b flex justify-between items-center bg-red-50 text-red-800 rounded-t-lg">
              <h2 className="text-xl font-bold flex items-center gap-2">
                <span>⚠️</span> عمليات البيع المتعارضة
              </h2>
              <button onClick={() => setIsOpen(false)} className="text-2xl font-bold hover:text-red-900">&times;</button>
            </div>
            
            <div className="p-4 overflow-y-auto flex-1">
              <p className="text-gray-600 mb-4">
                هذه العمليات لم تنجح في المزامنة مع الخادم بسبب تعارض في البيانات (مثلاً: تغير السعر، نقص المخزون) أو أخطاء دائمة. يرجى مراجعتها.
              </p>
              
              <div className="space-y-4">
                {conflicts.map(c => (
                  <div key={String(c.localId)} className="border border-red-200 rounded p-4 bg-red-50/30">
                    <div className="flex justify-between items-start mb-2">
                      <div>
                        <span className="text-sm text-gray-500 block mb-1">
                          تاريخ العملية: {new Date(c.savedAt).toLocaleString('ar-EG')}
                        </span>
                        <strong className="text-red-700 text-sm block">
                          الخطأ: {c.lastError || 'غير معروف'}
                        </strong>
                      </div>
                      <span className={`px-2 py-1 text-xs rounded text-white ${c.syncStatus === 'permanent' ? 'bg-red-800' : 'bg-orange-500'}`}>
                        {c.syncStatus === 'permanent' ? 'فشل دائم' : 'تعارض'}
                      </span>
                    </div>
                    
                    <div className="flex gap-2 mt-4">
                      <button 
                        onClick={() => handleRetryOne(c.localId)}
                        className="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm transition"
                      >
                        إعادة محاولة
                      </button>
                      <button 
                        onClick={() => handleDiscard(c.localId)}
                        className="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm transition"
                      >
                        حذف العملية
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <div className="p-4 border-t flex justify-end gap-3 bg-gray-50 rounded-b-lg">
              <button 
                onClick={handleRetryAll}
                className="bg-blue-800 hover:bg-blue-900 text-white px-4 py-2 rounded transition"
              >
                إعادة محاولة الكل
              </button>
              <button 
                onClick={() => setIsOpen(false)}
                className="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded transition"
              >
                إغلاق
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  )
}
