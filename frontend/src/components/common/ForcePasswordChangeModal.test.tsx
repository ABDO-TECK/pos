import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { updateUser } from '../../api/endpoints'
import ForcePasswordChangeModal from './ForcePasswordChangeModal'

const { requireReauthentication, toast } = vi.hoisted(() => ({
  requireReauthentication: vi.fn(),
  toast: Object.assign(vi.fn(), {
    error: vi.fn(),
    success: vi.fn(),
  }),
}))

vi.mock('react-hot-toast', () => ({
  default: toast,
}))

vi.mock('../../api/endpoints', () => ({
  updateUser: vi.fn(),
}))

vi.mock('../../store/authStore', () => ({
  default: () => ({
    user: {
      id: 7,
      name: 'Cashier',
      email: 'cashier@pos.test',
      role: 'cashier',
      force_password_change: 1,
    },
    requireReauthentication,
  }),
}))

describe('ForcePasswordChangeModal', () => {
  let container: HTMLDivElement
  let root: Root

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(updateUser).mockResolvedValue({
      data: {
        data: {
          id: 7,
          name: 'Cashier',
          email: 'cashier@pos.test',
          role: 'cashier',
          force_password_change: 0,
        },
        requires_reauthentication: true,
        sessions_revoked: true,
      },
    } as never)
    requireReauthentication.mockResolvedValue(undefined)

    container = document.createElement('div')
    document.body.appendChild(container)
    root = createRoot(container)
  })

  afterEach(() => {
    act(() => root.unmount())
    container.remove()
  })

  it('submits the temporary password and ends the client session after reset', async () => {
    await act(async () => {
      root.render(<ForcePasswordChangeModal />)
    })

    const setInput = (id: string, value: string) => {
      const input = container.querySelector<HTMLInputElement>(`#${id}`)
      if (!input) throw new Error(`Input not found: ${id}`)
      const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set
      setter?.call(input, value)
      input.dispatchEvent(new Event('input', { bubbles: true }))
    }

    act(() => {
      setInput('forced-current-password', 'Temporary123')
      setInput('forced-new-password', 'Changed456')
      setInput('forced-confirm-password', 'Changed456')
    })

    const form = container.querySelector('form')
    if (!form) throw new Error('Password form not found')
    await act(async () => {
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
      await Promise.resolve()
    })

    expect(updateUser).toHaveBeenCalledWith(7, {
      name: 'Cashier',
      email: 'cashier@pos.test',
      password: 'Changed456',
      current_password: 'Temporary123',
    })
    expect(requireReauthentication).toHaveBeenCalledOnce()
  })
})
