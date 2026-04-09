import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { BrowserMultiFormatReader, BrowserCodeReader } from '@zxing/browser'
import { NotFoundException } from '@zxing/library'
import { X } from 'lucide-react'

/**
 * على HTTP (ما عدا localhost) المتصفحات الحديثة لا تعرّف `navigator.mediaDevices` — فينهار ZXing عند قراءة getUserMedia.
 * بعض الأجهزة تعرض فقط الواجهة المسبوقة (webkitGetUserMedia).
 */
function ensureCameraApi() {
  if (typeof navigator === 'undefined') return false

  if (typeof navigator.mediaDevices?.getUserMedia === 'function') {
    return true
  }

  const legacy =
    navigator.getUserMedia ||
    navigator.webkitGetUserMedia ||
    navigator.mozGetUserMedia ||
    navigator.msGetUserMedia

  if (typeof legacy !== 'function') {
    return false
  }

  try {
    if (!navigator.mediaDevices) {
      navigator.mediaDevices = {}
    }
    if (typeof navigator.mediaDevices.getUserMedia !== 'function') {
      navigator.mediaDevices.getUserMedia = function (constraints) {
        return new Promise((resolve, reject) => {
          legacy.call(navigator, constraints, resolve, reject)
        })
      }
    }
  } catch {
    return false
  }

  return true
}

function cameraUnavailableMessage() {
  const host = typeof window !== 'undefined' ? window.location.hostname : ''
  const localhost =
    host === 'localhost' ||
    host === '127.0.0.1' ||
    host === '[::1]'
  const secure =
    (typeof window !== 'undefined' && window.isSecureContext === true) || localhost

  if (!secure) {
    return 'الكاميرا غير متاحة على هذا الرابط لأن الاتصال غير آمن (HTTP). افتح النظام عبر https:// أو استخدم شهادة SSL على الخادم (حتى على الشبكة الداخلية).'
  }

  return 'المتصفح لا يوفّر واجهة الكاميرا هنا. جرّب Chrome أو Safari المحدّث، وتأكد من أذونات الكاميرا للموقع.'
}

/**
 * ملء الشاشة فوق المودال: قراءة باركود/QR من كاميرا الجهاز (مفيد على الهاتف دون قارئ).
 * يُعرض عبر portal على document.body حتى لا يُقصّه overflow المودال.
 */
export default function BarcodeCameraScanner({ onResult, onClose }) {
  const videoRef = useRef(null)
  const onResultRef = useRef(onResult)
  onResultRef.current = onResult

  const [error, setError] = useState(null)
  const [starting, setStarting] = useState(true)

  useEffect(() => {
    const video = videoRef.current
    if (!video) return undefined

    if (!ensureCameraApi()) {
      setStarting(false)
      setError(cameraUnavailableMessage())
      return undefined
    }

    const reader = new BrowserMultiFormatReader()
    let finished = false

    const stopAll = () => {
      finished = true
      try {
        BrowserCodeReader.releaseAllStreams()
      } catch {
        /* ignore */
      }
      const v = videoRef.current
      if (v?.srcObject) {
        const stream = v.srcObject
        stream.getTracks().forEach((t) => {
          try {
            t.stop()
          } catch {
            /* ignore */
          }
        })
        v.srcObject = null
      }
    }

    reader
      .decodeFromVideoDevice(undefined, video, (result, err, controls) => {
        if (finished) return
        if (result) {
          const text = result.getText()?.trim()
          if (text) {
            finished = true
            try {
              controls?.stop()
            } catch {
              /* ignore */
            }
            stopAll()
            onResultRef.current(text)
          }
        }
        const benign =
          err instanceof NotFoundException ||
          (err && err.constructor?.name === 'NotFoundException')
        if (err && !benign) {
          /* أخطاء أخرى نادرة أثناء اللقطات */
        }
      })
      .then(() => {
        if (!finished) setStarting(false)
      })
      .catch((e) => {
        setStarting(false)
        try {
          BrowserCodeReader.releaseAllStreams()
        } catch {
          /* ignore */
        }
        const raw = String(e?.message || '')
        const name = e?.name || ''
        let msg = 'تعذر تشغيل الكاميرا.'

        if (
          raw.includes('getUserMedia') ||
          raw.includes('mediaDevices') ||
          raw.includes('undefined')
        ) {
          msg = cameraUnavailableMessage()
        } else if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
          msg = 'تم رفض إذن الكاميرا. اسمح بالوصول من إعدادات المتصفح أو أيقونة القفل في شريط العنوان.'
        } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
          msg = 'لم يُعثر على كاميرا في هذا الجهاز.'
        } else if (raw.toLowerCase().includes('secure')) {
          msg = 'الكاميرا تتطلب اتصالاً آمناً (HTTPS) في معظم المتصفحات.'
        } else if (e?.message) {
          msg = String(e.message)
        }
        setError(msg)
      })

    return () => {
      stopAll()
    }
  }, [])

  const ui = (
    <div
      className="modal-overlay barcode-scanner-overlay"
      style={{ zIndex: 1100 }}
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose()
      }}
      role="dialog"
      aria-modal="true"
      aria-labelledby="barcode-scanner-title"
    >
      <div
        className="barcode-scanner-panel"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="barcode-scanner-toolbar">
          <h2 id="barcode-scanner-title" className="barcode-scanner-title">
            مسح الباركود بالكاميرا
          </h2>
          <button
            type="button"
            className="btn btn-ghost btn-icon"
            onClick={onClose}
            aria-label="إغلاق"
          >
            <X size={20} />
          </button>
        </div>

        <p className="barcode-scanner-hint">
          وجّه الباركود داخل الإطار الأخضر في منتصف الشاشة حتى يتم القراءة تلقائياً.
        </p>

        <div className="barcode-scanner-video-wrap">
          <video
            ref={videoRef}
            className="barcode-scanner-video"
            muted
            playsInline
            autoPlay
          />
          <div className="barcode-scanner-frame" aria-hidden="true" />
          {starting && !error && (
            <div className="barcode-scanner-loading">
              <span className="spinner" style={{ width: '2rem', height: '2rem', borderWidth: '3px' }} />
              <span>جاري تشغيل الكاميرا…</span>
            </div>
          )}
        </div>

        {error && (
          <div className="barcode-scanner-error" role="alert">
            {error}
          </div>
        )}

        <div className="barcode-scanner-actions">
          <button type="button" className="btn btn-ghost" style={{ flex: 1 }} onClick={onClose}>
            إلغاء
          </button>
        </div>
      </div>
    </div>
  )

  return createPortal(ui, document.body)
}
