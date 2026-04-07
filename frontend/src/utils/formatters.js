// Force Western/Latin numerals (1234567890) while keeping Arabic currency symbol
const AR = 'ar-EG-u-nu-latn'

export const formatCurrency = (amount) =>
  new Intl.NumberFormat(AR, { style: 'currency', currency: 'EGP' }).format(amount ?? 0)

export const formatNumber = (num) =>
  new Intl.NumberFormat(AR).format(num ?? 0)

export const formatPercent = (num) =>
  `${new Intl.NumberFormat(AR).format(num ?? 0)}%`

export const formatDate = (dateStr) => {
  if (!dateStr) return '—'
  return new Intl.DateTimeFormat(AR, {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit',
  }).format(new Date(dateStr))
}

export const formatShortDate = (dateStr) => {
  if (!dateStr) return '—'
  return new Intl.DateTimeFormat(AR, {
    year: 'numeric', month: 'short', day: 'numeric',
  }).format(new Date(dateStr))
}

export const formatTime = (dateStr) => {
  if (!dateStr) return '—'
  return new Intl.DateTimeFormat(AR, {
    hour: '2-digit', minute: '2-digit',
  }).format(new Date(dateStr))
}
