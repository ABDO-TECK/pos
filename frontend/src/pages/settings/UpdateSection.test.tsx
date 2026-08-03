import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import UpdateSection from './UpdateSection'
import { applyUpdate } from '../../api/endpoints'

const forceCheck = vi.fn()
const confirm = vi.fn()

vi.mock('react-hot-toast', () => ({
  default: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

vi.mock('../../api/endpoints', () => ({
  applyUpdate: vi.fn(),
  getUpdateJob: vi.fn(),
}))

vi.mock('../../store/confirmStore', () => ({
  useConfirmStore: () => ({ confirm }),
}))

vi.mock('../../store/updateStore', () => ({
  default: () => ({
    hasUpdate: true,
    currentVersion: '1.1.32',
    latestVersion: '1.1.33',
    changelog: [],
    isChecking: false,
    forceCheck,
    updatesDisabled: false,
    updatesDisabledMessage: '',
    updatesUnreachable: false,
    updateErrorMessage: '',
  }),
}))

describe('UpdateSection packaged desktop updater flow', () => {
  let container: HTMLDivElement
  let root: Root
  let download: ReturnType<typeof vi.fn>

  beforeEach(() => {
    container = document.createElement('div')
    document.body.appendChild(container)
    root = createRoot(container)
    download = vi.fn().mockResolvedValue({ status: 'downloading' })
    ;(window as any).electronAPI = {
      updater: {
        getStatus: vi.fn().mockResolvedValue({ isPackaged: true }),
        download,
      },
    }
  })

  afterEach(() => {
    act(() => {
      root.unmount()
    })
    container.remove()
    delete (window as any).electronAPI
    vi.clearAllMocks()
  })

  it('uses Electron updater IPC instead of backend applyUpdate in packaged production', async () => {
    await act(async () => {
      root.render(<UpdateSection />)
    })

    const updateButton = Array.from(container.querySelectorAll('button'))
      .find((button) => button.textContent?.includes('تحديث الآن'))

    expect(updateButton).toBeTruthy()
    await act(async () => {
      updateButton?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    })

    expect(download).toHaveBeenCalledTimes(1)
    expect(applyUpdate).not.toHaveBeenCalled()
  })
})
