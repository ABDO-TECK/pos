import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import UpdateSection from './UpdateSection'
import { applyUpdate, getUpdateHistory, getUpdateSnapshots, getUpdateStatus, rollbackUpdate, diagnoseUpdateRecovery, getRecoveryAuditLogs } from '../../api/endpoints'


const forceCheck = vi.fn()
const confirm = vi.fn()

vi.mock('react-hot-toast', () => ({
  default: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

vi.mock('../../api/endpoints', () => ({
  getUpdateStatus: vi.fn(),
  checkUpdate: vi.fn(),
  applyUpdate: vi.fn(),
  getUpdateHistory: vi.fn(),
  rollbackUpdate: vi.fn(),
  getUpdateSnapshots: vi.fn(),
  getUpdateJob: vi.fn(),
  setUpdateChannel: vi.fn(),
  diagnoseUpdateRecovery: vi.fn(),
  executeRecoveryAction: vi.fn(),
  getRecoveryAuditLogs: vi.fn(),
  runPostUpdateHealthCheck: vi.fn(),
}))


vi.mock('../../store/confirmStore', () => ({
  useConfirmStore: () => ({ confirm }),
}))

vi.mock('../../store/updateStore', () => ({
  default: () => ({
    hasUpdate: true,
    currentVersion: '1.1.48',
    latestVersion: '1.1.49',
    changelog: [
      {
        version: '1.1.49',
        date: '2026-08-27',
        changes: ['Improved delta update engine', 'Added rollback manager'],
      },
    ],
    isChecking: false,
    forceCheck,
    updatesDisabled: false,
    updatesDisabledMessage: '',
    updatesUnreachable: false,
    updateErrorMessage: '',
  }),
}))

describe('UpdateSection Admin Update Center Flow', () => {
  let container: HTMLDivElement
  let root: Root

  beforeEach(() => {
    container = document.createElement('div')
    document.body.appendChild(container)
    root = createRoot(container)

    vi.mocked(getUpdateStatus).mockResolvedValue({
      data: {
        status: 'success',
        message: 'success',
        data: {
          current_version: '1.1.48',
          latest_version: '1.1.49',
          update_available: true,
          type: 'delta',
          release_info: {
            title: 'v1.1.49',
            tag_name: 'v1.1.49',
            changelog: ['Improved delta update engine'],
            released_at: '2026-08-27',
            files_count: 2,
            release_url: 'https://github.com/ABDO-TECK/pos/releases/tag/v1.1.49',
            download_url: 'https://github.com/ABDO-TECK/pos/releases/download/v1.1.49/delta.zip',
          },
          update_state: {
            state: 'idle',
          },
        },
      },
    } as never)

    vi.mocked(getUpdateHistory).mockResolvedValue({
      data: {
        status: 'success',
        message: 'success',
        data: [
          {
            id: 1,
            from_version: '1.1.47',
            to_version: '1.1.48',
            type: 'delta',
            source: 'github_release',
            release_tag: 'v1.1.48',
            status: 'success',
            files_count: 1,
            backup_path: '/storage/snapshot',
            download_url: null,
            error_message: null,
            created_at: '2026-08-26 12:00:00',
          },
        ],
      },
    } as never)

    vi.mocked(getUpdateSnapshots).mockResolvedValue({
      data: {
        status: 'success',
        message: 'success',
        data: [
          {
            snapshot_name: 'patch_1.1.47_to_1.1.48_20260826',
            snapshot_path: '/storage/update-backups/patch_1.1.47_to_1.1.48_20260826',
            from_version: '1.1.47',
            to_version: '1.1.48',
            timestamp: '2026-08-26 12:00:00',
            files_count: 1,
            has_db_backup: true,
            db_backup_path: '/storage/pre_update.sql',
          },
        ],
      },
    } as never)

    vi.mocked(diagnoseUpdateRecovery).mockResolvedValue({
      data: {
        status: 'success',
        message: 'success',
        data: {
          status: 'healthy',
          state: 'idle',
          problem_detected: false,
          recommended_action: 'none',
          message: 'No active or interrupted updates found.',
          details: {},
          is_locked: false,
          auto_recovery_enabled: true,
        },
      },
    } as never)

    vi.mocked(getRecoveryAuditLogs).mockResolvedValue({
      data: {
        status: 'success',
        message: 'success',
        data: {
          logs: [],
          total: 0,
        },
      },
    } as never)
  })


  afterEach(() => {
    act(() => {
      root.unmount()
    })
    container.remove()
    vi.clearAllMocks()
  })

  it('renders current version and available delta update', async () => {
    await act(async () => {
      root.render(<UpdateSection />)
    })

    expect(container.textContent).toContain('v1.1.48')
    expect(container.textContent).toContain('v1.1.49')
    expect(container.textContent).toContain('Delta (جزئي)')
    expect(container.textContent).toContain('RSA-2048 Verified')
  })

  it('triggers update installation after confirmation', async () => {
    confirm.mockResolvedValue(true)
    vi.mocked(applyUpdate).mockResolvedValue({
      data: {
        status: 'success',
        message: 'Update applied',
        data: {
          logs: ['Delta update applied successfully'],
        },
      },
    } as never)

    await act(async () => {
      root.render(<UpdateSection />)
    })

    const applyButton = Array.from(container.querySelectorAll('button'))
      .find((b) => b.textContent?.includes('تثبيت التحديث الآن'))

    expect(applyButton).toBeTruthy()

    await act(async () => {
      applyButton?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    })

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(applyUpdate).toHaveBeenCalledWith(false, false)
  })

  it('displays update history table when history button is clicked', async () => {
    await act(async () => {
      root.render(<UpdateSection />)
    })

    const historyButton = Array.from(container.querySelectorAll('button'))
      .find((b) => b.textContent?.includes('سجل التحديثات'))

    expect(historyButton).toBeTruthy()

    await act(async () => {
      historyButton?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    })

    expect(container.textContent).toContain('سجل عمليات التحديث السابقة')
    expect(container.textContent).toContain('v1.1.47')
    expect(container.textContent).toContain('v1.1.48')
    expect(container.textContent).toContain('ناجح ✅')
  })

  it('allows rollback from available snapshots', async () => {
    confirm.mockResolvedValue(true)
    vi.mocked(rollbackUpdate).mockResolvedValue({
      data: {
        status: 'success',
        message: 'Rolled back',
        data: {
          ok: true,
          logs: ['Restored files'],
        },
      },
    } as never)

    await act(async () => {
      root.render(<UpdateSection />)
    })

    const snapshotsToggle = Array.from(container.querySelectorAll('button'))
      .find((b) => b.textContent?.includes('نقاط الاسترجاع'))

    expect(snapshotsToggle).toBeTruthy()

    await act(async () => {
      snapshotsToggle?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    })

    expect(container.textContent).toContain('patch_1.1.47_to_1.1.48_20260826')

    const rollbackButton = Array.from(container.querySelectorAll('button'))
      .find((b) => b.textContent?.includes('استرجاع هذه النسخة'))

    expect(rollbackButton).toBeTruthy()

    await act(async () => {
      rollbackButton?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    })

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(rollbackUpdate).toHaveBeenCalledWith('/storage/update-backups/patch_1.1.47_to_1.1.48_20260826')
  })

  it('renders string-based changelog cleanly without throwing length errors', async () => {
    await act(async () => {
      root.render(<UpdateSection />)
    })

    const changelogButton = Array.from(container.querySelectorAll('button'))
      .find((b) => b.textContent?.includes('ملاحظات الإصدار'))

    expect(changelogButton).toBeTruthy()

    await act(async () => {
      changelogButton?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    })

    expect(container.textContent).toContain('سجل التغييرات والمميزات')
    expect(container.textContent).toContain('Improved delta update engine')
  })
})
