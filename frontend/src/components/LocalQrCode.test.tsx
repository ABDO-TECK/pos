import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import LocalQrCode from './LocalQrCode'

describe('LocalQrCode', () => {
  it('renders a QR SVG locally without an external image request', () => {
    render(<LocalQrCode value="https://192.168.1.20:8443/" />)

    const qr = screen.getByRole('img', { name: 'QR Code' })
    expect(qr.tagName.toLowerCase()).toBe('svg')
    expect(qr.querySelector('path')).not.toBeNull()
    expect(qr.querySelector('img')).toBeNull()
  })

  it('shows a stable fallback for an empty value', () => {
    render(<LocalQrCode value="" />)

    expect(screen.getByRole('img', { name: 'QR Code' }).tagName.toLowerCase()).toBe('span')
  })
})
