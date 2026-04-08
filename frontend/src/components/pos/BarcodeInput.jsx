import { useRef, useEffect, useState, useCallback } from 'react'
import { Scan } from 'lucide-react'
import useCartStore from '../../store/cartStore'
import useProductStore from '../../store/productStore'
import toast from 'react-hot-toast'

const beep = () => {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)()
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.connect(gain)
    gain.connect(ctx.destination)
    osc.frequency.value = 880
    osc.type = 'sine'
    gain.gain.setValueAtTime(0.3, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15)
    osc.start(ctx.currentTime)
    osc.stop(ctx.currentTime + 0.15)
  } catch {}
}

// بعد توقف الماسح قليلاً نفّذ البحث (لا نعطّل الحقل أثناء الطلب حتى لا يُفقد التركيز)
const SCANNER_DEBOUNCE = 280

/**
 * @param {object} props
 * @param {(q: string) => void} [props.onFilterChange]
 * @param {(product: object) => void} [props.onAddProduct] — بدل الإضافة الافتراضية للسلة (مثلاً استلام بضاعة)
 * @param {boolean} [props.allowOutOfStock] — السماح بإضافة منتج نافد المخزون (للموردين)
 */
export default function BarcodeInput({ onFilterChange, onAddProduct, allowOutOfStock = false }) {
  const inputRef      = useRef(null)
  const debounceTimer = useRef(null)
  const lastTypeTime  = useRef(0)
  const typeCount     = useRef(0)
  const busyRef       = useRef(false)

  const [value, setValue]     = useState('')
  const [loading, setLoading] = useState(false)

  const addItem       = useCartStore((s) => s.addItem)
  const findByBarcode = useProductStore((s) => s.findByBarcode)
  const addFn         = onAddProduct ?? addItem

  // إعادة التركيز عند النقر خارج حقول الإدخال (لا نسرق التركيز من مودال أو حقل آخر)
  useEffect(() => {
    const refocus = (e) => {
      const tag = e.target.tagName
      if (['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(tag)) return
      inputRef.current?.focus()
    }
    inputRef.current?.focus()
    document.addEventListener('click', refocus)
    return () => document.removeEventListener('click', refocus)
  }, [])

  const handleSearch = useCallback(async (barcode) => {
    const trimmed = barcode.trim()
    if (!trimmed) return

    if (busyRef.current) {
      clearTimeout(debounceTimer.current)
      debounceTimer.current = setTimeout(() => {
        handleSearch((inputRef.current?.value ?? '').trim() || trimmed)
      }, 90)
      return
    }

    busyRef.current = true
    const snapshot = trimmed

    setLoading(true)
    let product = null
    try {
      product = await findByBarcode(trimmed)
    } finally {
      setLoading(false)
    }

    if (product) {
      if (!allowOutOfStock && product.quantity <= 0) {
        toast.error(`${product.name} — نفد من المخزون`, { icon: '⚠️' })
      } else {
        addFn(product)
        beep()
        toast.success(product.name, { duration: 1200, icon: '✅' })
      }
    } else {
      toast.error('المنتج غير موجود', { icon: '❌' })
    }

    // لا تمسح محتوى الحقل إذا وصلت مسحة جديدة أثناء انتظار الشبكة
    setValue((v) => (v.trim() === snapshot ? '' : v))
    typeCount.current = 0

    busyRef.current = false

    queueMicrotask(() => {
      const el = inputRef.current
      const v = el?.value ?? ''
      onFilterChange?.(v)
      requestAnimationFrame(() => {
        el?.focus()
        const rest = (el?.value ?? '').trim()
        if (rest && rest !== snapshot) {
          clearTimeout(debounceTimer.current)
          debounceTimer.current = setTimeout(() => handleSearch(rest), SCANNER_DEBOUNCE)
        }
      })
    })
  }, [addFn, allowOutOfStock, findByBarcode, onFilterChange])

  const handleChange = (e) => {
    const newVal = e.target.value
    setValue(newVal)
    onFilterChange?.(newVal)

    if (!newVal.trim()) return

    const now = Date.now()
    const gap = now - lastTypeTime.current
    lastTypeTime.current = now

    if (gap < 60) {
      typeCount.current += 1
    } else {
      typeCount.current = 1
    }

    clearTimeout(debounceTimer.current)

    // ≥3 أحرف بسرعة = غالباً ماسح (يدعم باركودات قصيرة)
    if (typeCount.current >= 3) {
      debounceTimer.current = setTimeout(() => {
        handleSearch(newVal)
      }, SCANNER_DEBOUNCE)
    }
  }

  const handleKeyDown = (e) => {
    if (e.key === 'Enter') {
      clearTimeout(debounceTimer.current)
      handleSearch(value)
    }
    if (e.key === 'Escape') {
      setValue('')
      onFilterChange?.('')
      typeCount.current = 0
    }
  }

  return (
    <div style={{ position: 'relative' }}>
      <Scan
        size={20}
        style={{
          position: 'absolute', top: '50%', transform: 'translateY(-50%)',
          right: '1rem', color: loading ? 'var(--primary)' : 'var(--text-muted)',
          transition: 'color .2s',
          pointerEvents: 'none',
        }}
      />
      {loading && (
        <span
          className="spinner"
          style={{
            position: 'absolute', top: '50%', left: '1rem',
            transform: 'translateY(-50%)',
            width: '1rem', height: '1rem',
          }}
        />
      )}
      <input
        ref={inputRef}
        className="input input-lg"
        style={{ paddingRight: '2.8rem', paddingLeft: loading ? '2.8rem' : '1rem' }}
        placeholder="امسح الباركود، أو اكتب لتصفية المنتجات فوراً، ثم Enter لإضافة بالباركود…"
        value={value}
        onChange={handleChange}
        onKeyDown={handleKeyDown}
        autoComplete="off"
        autoFocus
        /* مهم: لا تستخدم disabled أثناء التحميل — في المتصفح يزيل التركيز ويقطع المسح المتكرر */
      />
    </div>
  )
}
