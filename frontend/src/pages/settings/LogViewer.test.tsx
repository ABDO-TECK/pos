import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import api from '../../api/axios'
import LogViewer from './LogViewer'

vi.mock('../../api/axios', () => ({
  default: {
    get: vi.fn(),
  },
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
    act(() => {
      root.unmount()
    })
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
          created_at: '2026-07-27T10:00:00.000Z',
        }],
      },
    } as never)

    await act(async () => {
      root.render(<LogViewer />)
    })

    await vi.waitFor(() => {
      expect(container.textContent).toContain('"client_ip": "127.0.0.1"')
    })
  })

  it('uses the server cursor to load the next bounded page', async () => {
    mockedGet
      .mockResolvedValueOnce({
        data: {
          data: [{
            id: 'newest',
            level: 'ERROR',
            message: '[CLIENT] Newest',
            context: {},
            created_at: '2026-07-28 12:00:00',
          }],
          pagination: {
            page: 1,
            limit: 100,
            next_cursor: 'next-page-cursor',
            has_more: true,
          },
        },
      } as never)
      .mockResolvedValueOnce({
        data: {
          data: [{
            id: 'older',
            level: 'WARNING',
            message: '[CLIENT] Older',
            context: {},
            created_at: '2026-07-27 12:00:00',
          }],
          pagination: {
            page: 2,
            limit: 100,
            next_cursor: null,
            has_more: false,
          },
        },
      } as never)

    await act(async () => {
      root.render(<LogViewer />)
    })

    const nextButton = await vi.waitFor(() => {
      const button = Array.from(container.querySelectorAll('button'))
        .find(candidate => candidate.textContent === 'التالي')
      expect(button).toBeDefined()
      expect(button?.disabled).toBe(false)
      return button as HTMLButtonElement
    })

    await act(async () => {
      nextButton.click()
    })

    await vi.waitFor(() => {
      expect(mockedGet).toHaveBeenLastCalledWith(
        '/admin/client-logs',
        expect.objectContaining({
          params: {
            level: 'all',
            limit: 100,
            cursor: 'next-page-cursor',
          },
        }),
      )
      expect(container.textContent).toContain('[CLIENT] Older')
      expect(container.textContent).toContain('الصفحة 2')
    })
  })
})
