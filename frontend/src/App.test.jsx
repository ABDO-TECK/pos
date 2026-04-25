import { render, screen } from '@testing-library/react'
import { describe, it, expect } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import App from './App'

describe('App Component', () => {
  it('renders without crashing', () => {
    // If App does routing and requires a Router wrapper
    // Since App has its own HashRouter, rendering it directly works if it doesn't try to mock too much
    render(<App />)
    expect(document.body.innerHTML).not.toBeNull()
  })
})
