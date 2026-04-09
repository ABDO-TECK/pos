import { create } from 'zustand'

const STORAGE_KEY = 'pos_ui_theme'
const THEME_TRANSITION_MS = 380

function applyTheme(mode) {
  if (typeof document === 'undefined') return
  if (mode === 'dark') {
    document.documentElement.dataset.theme = 'dark'
  } else {
    delete document.documentElement.dataset.theme
  }
  try {
    localStorage.setItem(STORAGE_KEY, mode)
  } catch {
    /* ignore */
  }
}

function runWithThemeTransition(updateDom) {
  if (typeof document === 'undefined') {
    updateDom()
    return
  }
  const root = document.documentElement
  if (typeof document.startViewTransition === 'function') {
    document.startViewTransition(() => {
      updateDom()
    })
    return
  }
  root.classList.add('theme-changing')
  requestAnimationFrame(() => {
    updateDom()
    window.setTimeout(() => {
      root.classList.remove('theme-changing')
    }, THEME_TRANSITION_MS)
  })
}

function getStoredTheme() {
  try {
    const s = localStorage.getItem(STORAGE_KEY)
    if (s === 'dark' || s === 'light') return s
  } catch {
    /* ignore */
  }
  return 'light'
}

const initial = getStoredTheme()
applyTheme(initial)

const useThemeStore = create((set, get) => ({
  mode: initial,
  toggle: () => {
    const next = get().mode === 'dark' ? 'light' : 'dark'
    runWithThemeTransition(() => {
      applyTheme(next)
      set({ mode: next })
    })
  },
}))

export default useThemeStore
