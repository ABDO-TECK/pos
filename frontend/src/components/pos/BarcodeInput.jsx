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

// Debounce delay in ms — barcode scanners type all chars in < 100ms then stop
const SCANNER_DEBOUNCE = 350

export default function BarcodeInput({ onFilterChange }) {
  const inputRef      = useRef(null)
  const debounceTimer = useRef(null)
  const lastTypeTime  = useRef(0)
  const typeCount     = useRef(0)

  const [value, setValue]     = useState('')
  const [loading, setLoading] = useState(false)

  const addItem       = useCartStore((s) => s.addItem)
  const findByBarcode = useProductStore((s) => s.findByBarcode)

  // Keep focus on barcode input at all times
  useEffect(() => {
    const refocus = (e) => {
      // Don't steal focus from modals or interactive elements
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

    setLoading(true)
    const product = await findByBarcode(trimmed)
    setLoading(false)

    if (product) {
      if (product.quantity <= 0) {
        toast.error(`${product.name} — نفد من المخزون`, { icon: '⚠️' })
      } else {
        addItem(product)
        beep()
        toast.success(product.name, { duration: 1200, icon: '✅' })
      }
    } else {
      toast.error('المنتج غير موجود', { icon: '❌' })
    }

    setValue('')
    onFilterChange?.('')
    typeCount.current = 0
    inputRef.current?.focus()
  }, [addItem, findByBarcode, onFilterChange])

  const handleChange = (e) => {
    const newVal = e.target.value
    setValue(newVal)
    onFilterChange?.(newVal)

    if (!newVal.trim()) return

    // Track typing speed to detect barcode scanner (types very fast)
    const now = Date.now()
    const gap = now - lastTypeTime.current
    lastTypeTime.current = now

    if (gap < 60) {
      // Fast typing = likely a scanner; increment counter
      typeCount.current += 1
    } else {
      // Slow typing = manual keyboard; reset counter
      typeCount.current = 1
    }

    // Clear any pending debounce
    clearTimeout(debounceTimer.current)

    // If scanner detected (≥5 chars typed rapidly), auto-search after short pause
    if (typeCount.current >= 4) {
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
        disabled={loading}
      />
    </div>
  )
}
