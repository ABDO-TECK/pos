import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PaymentModal from './PaymentModal'
import useCartStore from '../../store/cartStore'
import useSettingsStore from '../../store/settingsStore'
import { createSale, getCustomerOption, getCustomers, searchCustomers } from '../../api/endpoints'
import { savePendingSale } from '../../utils/idb'
import useAuthStore from '../../store/authStore'

const { toast } = vi.hoisted(() => ({
  toast: Object.assign(vi.fn(), {
    error: vi.fn(),
    success: vi.fn(),
  }),
}))

vi.mock('react-hot-toast', () => ({
  default: toast,
}))

vi.mock('../../api/endpoints', () => ({
  createSale: vi.fn(),
  getCustomerOption: vi.fn(),
  getCustomers: vi.fn(),
  searchCustomers: vi.fn(),
}))

vi.mock('../../utils/idb', () => ({
  savePendingSale: vi.fn(),
}))

describe('PaymentModal customer creation', () => {
  let container: HTMLDivElement
  let root: Root

  beforeEach(() => {
    vi.clearAllMocks()
    useAuthStore.persist.setOptions({
      storage: {
        getItem: () => null,
        setItem: () => undefined,
        removeItem: () => undefined,
      },
    })
    vi.mocked(getCustomers).mockResolvedValue({
      data: { data: [] },
    } as never)
    vi.mocked(searchCustomers).mockResolvedValue([])
    vi.mocked(getCustomerOption).mockResolvedValue(null)
    vi.mocked(createSale).mockResolvedValue({
      data: {
        data: {
          invoice: { id: 77, customer_id: 42 },
          low_stock_alerts: [],
        },
      },
    } as never)

    useSettingsStore.setState({
      taxEnabled: false,
      taxRate: 15,
    })
    useAuthStore.setState({
      user: { id: 7, name: 'Cashier', email: 'cashier@example.com', role: 'cashier', branch_id: 3 },
      token: null,
      isAuthenticated: true,
      _hasHydrated: true,
    })

    container = document.createElement('div')
    document.body.appendChild(container)
    root = createRoot(container)
  })

  afterEach(() => {
    act(() => root.unmount())
    container.remove()
    vi.restoreAllMocks()
  })

  it.each(['cash', 'credit'])(
    'sends the new customer with a %s sale when Confirm is clicked',
    async (paymentMethod) => {
      useCartStore.setState({
        items: [{
          id: 1,
          name: 'Product',
          price: 100,
          quantity: 1,
          subtotal: 100,
        }],
        paymentMethod,
        discount: 0,
        amountPaid: 0,
        rebillingInvoiceId: null,
        rebillingCustomerId: null,
        rebillingAmountPaid: 0,
        rebillingPaymentMethod: null,
        rebillingShippingCost: 0,
      })

      await act(async () => {
        root.render(<PaymentModal onClose={vi.fn()} onSuccess={vi.fn()} />)
      })

      const findButton = (label: RegExp): HTMLButtonElement => {
        const button = [...container.querySelectorAll('button')]
          .find((candidate) => label.test(candidate.textContent ?? ''))
        if (!button) throw new Error(`Button not found: ${label}`)
        return button
      }
      const click = (element: HTMLElement) => {
        element.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      }
      const change = (input: HTMLInputElement, value: string) => {
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set
        setter?.call(input, value)
        input.dispatchEvent(new Event('input', { bubbles: true }))
      }

      act(() => click(findButton(/العميل والآجل/)))
      act(() => click(findButton(/عميل جديد/)))

      const customerInputs = [...container.querySelectorAll<HTMLInputElement>('input[type="text"], input:not([type])')]
      act(() => change(customerInputs[0], 'Body'))
      act(() => change(customerInputs[1], '01000000000'))
      await act(async () => {
        click(findButton(/تأكيد/))
        await Promise.resolve()
      })

      expect(createSale).toHaveBeenCalledWith(expect.objectContaining({
        payment_method: paymentMethod,
        new_customer: {
          name: 'Body',
          phone: '01000000000',
          address: '',
        },
      }))
    },
  )

  it('replaces an original cash payment with only the new credit deposit', async () => {
    useCartStore.getState().clearCart()
    useCartStore.getState().mergeInvoiceLines(
      [{
        product_id: 1,
        name: 'Product',
        price: 100,
        quantity: 1,
      }],
      77,
      42,
      100,
      'cash',
      0,
    )
    useCartStore.getState().setPaymentMethod('credit')

    await act(async () => {
      root.render(<PaymentModal onClose={vi.fn()} onSuccess={vi.fn()} />)
    })

    const findButton = (label: RegExp): HTMLButtonElement => {
      const button = [...container.querySelectorAll('button')]
        .find((candidate) => label.test(candidate.textContent ?? ''))
      if (!button) throw new Error(`Button not found: ${label}`)
      return button
    }
    const click = (element: HTMLElement) => {
      element.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    }
    const change = (input: HTMLInputElement, value: string) => {
      const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set
      setter?.call(input, value)
      input.dispatchEvent(new Event('input', { bubbles: true }))
    }

    act(() => click(findButton(/العميل والآجل/)))
    const depositInput = container.querySelector<HTMLInputElement>('input[type="number"]')
    if (!depositInput) throw new Error('Deposit input not found')
    act(() => change(depositInput, '25'))

    await act(async () => {
      const saveButton = container.querySelector<HTMLButtonElement>('button.btn-danger.btn-lg')
      if (!saveButton) throw new Error('Save invoice button not found')
      click(saveButton)
      await Promise.resolve()
    })

    expect(createSale).toHaveBeenCalledWith(expect.objectContaining({
      invoice_id: 77,
      customer_id: 42,
      payment_method: 'credit',
      deposit: 25,
      amount_paid: 25,
    }))
  })

  it('adds shipping cost to the invoice total and payload', async () => {
    useCartStore.getState().clearCart()
    useCartStore.setState({
      items: [{
        id: 1,
        name: 'Product',
        price: 100,
        quantity: 1,
        subtotal: 100,
      }],
      paymentMethod: 'cash',
    })

    await act(async () => {
      root.render(<PaymentModal onClose={vi.fn()} onSuccess={vi.fn()} />)
    })

    const deliveryTab = [...container.querySelectorAll('button')]
      .find((button) => /التوصيل والشحن/.test(button.textContent ?? ''))
    if (!deliveryTab) throw new Error('Delivery tab not found')
    act(() => deliveryTab.dispatchEvent(new MouseEvent('click', { bubbles: true })))

    const shippingInput = container.querySelector<HTMLInputElement>('input[type="number"]')
    if (!shippingInput) throw new Error('Shipping cost input not found')
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set
    await act(async () => {
      setter?.call(shippingInput, '15')
      shippingInput.dispatchEvent(new Event('input', { bubbles: true }))
      await Promise.resolve()
    })

    const checkoutButton = container.querySelector<HTMLButtonElement>('button.btn-primary.btn-lg')
    if (!checkoutButton) throw new Error('Checkout button not found')
    await act(async () => {
      checkoutButton.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      await Promise.resolve()
    })

    expect(createSale).toHaveBeenCalledWith(expect.objectContaining({
      shipping_cost: 15,
      amount_paid: 115,
    }))
  })

  it('passes the optional customer name to the printable invoice', async () => {
    vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(true)
    const onSuccess = vi.fn()
    useCartStore.setState({
      items: [{
        id: 1,
        name: 'Product',
        price: 100,
        quantity: 1,
        subtotal: 100,
      }],
      paymentMethod: 'cash',
      rebillingInvoiceId: null,
      rebillingCustomerId: null,
      rebillingAmountPaid: 0,
      rebillingPaymentMethod: null,
      rebillingShippingCost: 0,
    })

    await act(async () => {
      root.render(<PaymentModal onClose={vi.fn()} onSuccess={onSuccess} />)
    })

    const customerTab = [...container.querySelectorAll('button')]
      .find((button) => (button.textContent ?? '').includes('العميل'))
    if (!customerTab) throw new Error('Customer tab not found')
    act(() => customerTab.dispatchEvent(new MouseEvent('click', { bubbles: true })))

    const customerNameInput = container.querySelector<HTMLInputElement>('#invoice-customer-name')
    if (!customerNameInput) throw new Error('Optional customer name input not found')
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set
    act(() => {
      setter?.call(customerNameInput, 'Alice & Sons')
      customerNameInput.dispatchEvent(new Event('input', { bubbles: true }))
    })

    const checkoutButton = container.querySelector<HTMLButtonElement>('button.btn-primary.btn-lg')
    if (!checkoutButton) throw new Error('Checkout button not found')
    await act(async () => {
      checkoutButton.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      await Promise.resolve()
    })

    expect(onSuccess).toHaveBeenCalledWith(
      expect.objectContaining({ customer_name: 'Alice & Sons' }),
      0,
    )
  })

  it('retains one UUID v4 across an ambiguous online retry', async () => {
    vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(true)
    vi.mocked(createSale)
      .mockRejectedValueOnce(new Error('Network connection reset'))
      .mockResolvedValueOnce({
        data: {
          data: {
            invoice: { id: 77 },
            low_stock_alerts: [],
          },
        },
      } as never)
    useCartStore.setState({
      items: [{
        id: 1,
        name: 'Product',
        price: 100,
        quantity: 1,
        subtotal: 100,
      }],
      paymentMethod: 'cash',
      rebillingInvoiceId: null,
      rebillingCustomerId: null,
      rebillingAmountPaid: 0,
      rebillingPaymentMethod: null,
      rebillingShippingCost: 0,
    })

    await act(async () => {
      root.render(<PaymentModal onClose={vi.fn()} onSuccess={vi.fn()} />)
    })
    const checkoutButton = container.querySelector<HTMLButtonElement>('button.btn-primary.btn-lg')
    if (!checkoutButton) throw new Error('Checkout button not found')

    await act(async () => {
      checkoutButton.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      await Promise.resolve()
    })
    await act(async () => {
      checkoutButton.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      await Promise.resolve()
    })

    const firstPayload = vi.mocked(createSale).mock.calls[0][0]
    const secondPayload = vi.mocked(createSale).mock.calls[1][0]
    expect(firstPayload.idempotency_key).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
    )
    expect(secondPayload.idempotency_key).toBe(firstPayload.idempotency_key)
  })

  it('persists the attempted UUID unchanged when the sale goes offline', async () => {
    vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(false)
    vi.mocked(createSale).mockRejectedValueOnce(new Error('Offline'))
    useCartStore.setState({
      items: [{
        id: 1,
        name: 'Product',
        price: 100,
        quantity: 1,
        subtotal: 100,
      }],
      paymentMethod: 'cash',
      rebillingInvoiceId: null,
      rebillingCustomerId: null,
      rebillingAmountPaid: 0,
      rebillingPaymentMethod: null,
      rebillingShippingCost: 0,
    })

    await act(async () => {
      root.render(<PaymentModal onClose={vi.fn()} onSuccess={vi.fn()} />)
    })
    const checkoutButton = container.querySelector<HTMLButtonElement>('button.btn-primary.btn-lg')
    if (!checkoutButton) throw new Error('Checkout button not found')
    await act(async () => {
      checkoutButton.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      await Promise.resolve()
    })

    const attemptedPayload = vi.mocked(createSale).mock.calls[0][0]
    expect(savePendingSale).toHaveBeenCalledWith(
      expect.objectContaining({ idempotency_key: attemptedPayload.idempotency_key }),
      { ownerUserId: 7, branchId: 3 },
    )
  })
})
