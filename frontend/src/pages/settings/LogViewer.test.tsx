import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import api from '../../api/axios'
import LogViewer from './LogViewer'

vi.mock('../../api/axios', () => ({
  default: { get: vi.fn() },
}))

const mockedGet = vi.mocked(api.get)

describe('LogViewer', () => {
  let container: HTMLDivElement
  let root: Root

  beforeEach(() => {
    container = document.createElement('div')
    document.body.appendChild(container)
    root = createRoot(container)
  })

  afterEach(() => {
    act(() => root.unmount())
    container.remove()
    vi.clearAllMocks()
  })

  it('serializes structured log context before rendering it', async () => {
    mockedGet.mockResolvedValue({
      data: {
        data: [{
          id: 'log-1',
          level: 'ERROR',
          message: '[CLIENT] React component error',
          context: {
            url: 'http://localhost/pos',
            stack: 'Error: example',
            userAgent: 'test-agent',
            timestamp: '2026-07-27T10:00:00.000Z',
            client_ip: '127.0.0.1',
          },
          source: 'client',
          created_at: '2026-07-27T10:00:00.000Z',
        }],
      },
    } as never)

    await act(async () => root.render(<LogViewer />))

    await vi.waitFor(() => {
      expect(mockedGet).toHaveBeenCalledWith(
        '/admin/error-logs',
        expect.objectContaining({ params: { level: 'all', limit: 10 } }),
      )
      expect(container.textContent).toContain('"client_ip": "127.0.0.1"')
    })
  })

  it('never renders more than ten rows when the backend over-returns a page', async () => {
    mockedGet.mockResolvedValue({
      data: {
        data: Array.from({ length: 12 }, (_, index) => ({
          id: `log-${index}`,
          level: 'INFO',
          message: `Log ${index}`,
          context: {},
          source: 'server',
          created_at: '2026-07-28 12:00:00',
        })),
      },
    } as never)

    await act(async () => root.render(<LogViewer />))

    await vi.waitFor(() => {
      expect(container.querySelectorAll('tbody tr[role="button"]')).toHaveLength(10)
    })
  })

  it('uses the server cursor to load the next bounded page', async () => {
    mockedGet
      .mockResolvedValueOnce({
        data: {
          data: [{ id: 'newest', level: 'ERROR', message: '[CLIENT] Newest', context: {}, source: 'client', created_at: '2026-07-28 12:00:00' }],
          pagination: { page: 1, limit: 10, next_cursor: 'next-page-cursor', has_more: true },
        },
      } as never)
      .mockResolvedValueOnce({
        data: {
          data: [{ id: 'older', level: 'WARNING', message: '[CLIENT] Older', context: {}, source: 'client', created_at: '2026-07-27 12:00:00' }],
          pagination: { page: 2, limit: 10, next_cursor: null, has_more: false },
        },
      } as never)

    await act(async () => root.render(<LogViewer />))

    const nextButton = await vi.waitFor(() => {
      const button = container.querySelector<HTMLButtonElement>('[data-testid="next-log-page"]:not([disabled])')
      expect(button).not.toBeNull()
      return button as HTMLButtonElement
    })

    await act(async () => nextButton.click())

    await vi.waitFor(() => {
      expect(mockedGet).toHaveBeenLastCalledWith(
        '/admin/error-logs',
        expect.objectContaining({ params: { level: 'all', limit: 10, cursor: 'next-page-cursor' } }),
      )
      expect(container.textContent).toContain('[CLIENT] Older')
      expect(container.textContent).toContain('الصفحة 2')
    })
  })

  it('opens a complete error detail dialog when a row is selected', async () => {
    mockedGet.mockResolvedValue({
      data: {
        data: [{
          id: 'server-1',
          level: 'CRITICAL',
          message: 'Fatal PHP error',
          context: { file: 'index.php', line: 42, reference: 'abc123' },
          source: 'server',
          created_at: '2026-07-28 12:00:00',
        }],
        pagination: { page: 1, limit: 10, next_cursor: null, has_more: false },
      },
    } as never)

    await act(async () => root.render(<LogViewer />))
    const row = await vi.waitFor(() => {
      const candidate = container.querySelector<HTMLTableRowElement>('tbody tr[role="button"]')
      expect(candidate).not.toBeNull()
      return candidate as HTMLTableRowElement
    })

    await act(async () => row.click())

    expect(container.querySelector('[role="dialog"]')).not.toBeNull()
    expect(container.textContent).toContain('Fatal PHP error')
    expect(container.textContent).toContain('"reference": "abc123"')
  })
})
