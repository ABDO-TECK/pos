import { describe, expect, it } from 'vitest'
import { formatDate, formatStatementDate, formatTime } from './formatters'

describe('shared date and time formatting', () => {
  it('uses Latin digits and a 12-hour clock for statement timestamps', () => {
    const date = new Date('2026-08-02T13:05:00')
    const expected = new Intl.DateTimeFormat('ar-EG-u-nu-latn', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
    }).format(date)

    expect(formatDate(date)).toBe(expected)
    expect(formatTime(date)).toBe(new Intl.DateTimeFormat('ar-EG-u-nu-latn', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
    }).format(date))
    const statementDate = formatStatementDate(date)
    expect(statementDate.replace(/[\u202a-\u202e\u2066-\u2069]/g, '')).toBe('02/أغسطس/2026 - 01:05 م')
    expect(statementDate).toContain('\u202bأغسطس\u202c')
    expect(formatDate(date)).not.toContain('13:05')
  })

  it('returns a stable placeholder for missing or invalid dates', () => {
    expect(formatDate(null)).toBe('—')
    expect(formatTime('not-a-date')).toBe('—')
  })
})
