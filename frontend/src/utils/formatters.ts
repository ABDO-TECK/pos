// Force Western/Latin numerals (1234567890) while keeping Arabic currency symbol
const AR = 'ar-EG-u-nu-latn'

const toNumber = (value: string | number | null | undefined): number => {
  const parsed = typeof value === 'number' ? value : Number.parseFloat(value ?? '')
  return Number.isFinite(parsed) ? parsed : 0
}

export const formatCurrency = (amount: string | number | null | undefined): string =>
  new Intl.NumberFormat(AR, { style: 'currency', currency: 'EGP' }).format(toNumber(amount))

export const formatNumber = (num: string | number | null | undefined): string =>
  new Intl.NumberFormat(AR).format(toNumber(num))

export const formatPercent = (num: string | number | null | undefined): string =>
  `${new Intl.NumberFormat(AR).format(toNumber(num))}%`

export const roundCurrency = (value: string | number | null | undefined): number =>
  Math.round(toNumber(value) * 100) / 100

export const formatDate = (dateStr: string | number | Date | null | undefined): string => {
  if (!dateStr) return '—'
  return new Intl.DateTimeFormat(AR, {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit',
  }).format(new Date(dateStr))
}

export const formatShortDate = (dateStr: string | number | Date | null | undefined): string => {
  if (!dateStr) return '—'
  return new Intl.DateTimeFormat(AR, {
    year: 'numeric', month: 'short', day: 'numeric',
  }).format(new Date(dateStr))
}

export const formatTime = (dateStr: string | number | Date | null | undefined): string => {
  if (!dateStr) return '—'
  return new Intl.DateTimeFormat(AR, {
    hour: '2-digit', minute: '2-digit',
  }).format(new Date(dateStr))
}
