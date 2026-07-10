import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import SystemHealth from './SystemHealth'
import { getHealthCheck } from '../../api/endpoints'

vi.mock('../../api/endpoints', () => ({
  getHealthCheck: vi.fn(),
}))

const mockedGetHealthCheck = vi.mocked(getHealthCheck)

describe('SystemHealth', () => {
  let container: HTMLDivElement
  let root: Root

  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-06-16T10:15:00.000Z'))
    container = document.createElement('div')
    document.body.appendChild(container)
    root = createRoot(container)
  })

  afterEach(() => {
    act(() => {
      root.unmount()
    })
    container.remove()
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  it('sets a local last check timestamp after a successful health fetch', async () => {
    mockedGetHealthCheck.mockResolvedValue({
      data: {
        status: 'ok',
        critical_failed: false,
        version: '1.1.32',
        checks: {
          database: { status: 'ok', latency_ms: 2 },
          disk: { status: 'ok', free_gb: 250, total_gb: 500, used_percent: 50 },
          memory: { status: 'ok', usage_mb: 98, peak_mb: 100, limit: '512M' },
          php: { version: '8.2.12', extensions: { curl: true } },
        },
      },
    } as any)

    await act(async () => {
      root.render(<SystemHealth />)
    })

    await vi.waitFor(() => {
      expect(container.textContent).toContain('آخر فحص:')
      expect(container.textContent).not.toContain('غير معروف')
    })
    expect(container.textContent).toContain('الحالة العامة:')
    expect(container.textContent).toContain('✓ سليم')
  })
})
