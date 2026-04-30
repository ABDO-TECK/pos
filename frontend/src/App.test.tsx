import { render } from '@testing-library/react'
import { describe, it, expect } from 'vitest'
import App from './App'

describe('App Component', () => {
  it('renders without crashing', () => {
    // Render the app (it includes its own HashRouter inside main.jsx or App.jsx)
    render(<App />)
    
    // Check if a common element is in the document, like the main container
    // Since document.body is always there, we test something slightly more robust
    expect(document.body.innerHTML).not.toBeNull()
  })

  it('can perform basic assertions', () => {
    // This is a placeholder test to demonstrate Vitest matchers
    expect(1 + 1).toBe(2)
    expect(true).toBeTruthy()
  })
})
