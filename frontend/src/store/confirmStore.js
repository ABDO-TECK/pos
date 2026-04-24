import { create } from 'zustand'

export const useConfirmStore = create((set, get) => ({
  isOpen: false,
  message: '',
  resolvePromise: null,

  confirm: (message) => {
    return new Promise((resolve) => {
      set({ isOpen: true, message, resolvePromise: resolve })
    })
  },

  handleConfirm: () => {
    const { resolvePromise } = get()
    if (resolvePromise) resolvePromise(true)
    set({ isOpen: false, resolvePromise: null })
  },

  handleCancel: () => {
    const { resolvePromise } = get()
    if (resolvePromise) resolvePromise(false)
    set({ isOpen: false, resolvePromise: null })
  }
}))
