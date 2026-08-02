import { act, useState } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { fireEvent } from '@testing-library/dom'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import NumericInput from './NumericInput'

describe('NumericInput', () => {
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
  })

  it('keeps an empty or leading-zero draft while the parent normalizes numbers', () => {
    function Wrapper() {
      const [value, setValue] = useState<number>(0)
      return <NumericInput aria-label="amount" value={value} onChange={event => setValue(parseFloat(event.target.value) || 0)} />
    }

    act(() => root.render(<Wrapper />))
    const input = container.querySelector<HTMLInputElement>('input[type="number"]')
    expect(input).not.toBeNull()
    if (!input) return

    act(() => {
      input.focus()
      fireEvent.change(input, { target: { value: '007' } })
    })
    expect(input.value).toBe('007')

    act(() => fireEvent.change(input, { target: { value: '' } }))
    expect(input.value).toBe('')

    act(() => input.blur())
    expect(input.value).toBe('0')
  })
})
