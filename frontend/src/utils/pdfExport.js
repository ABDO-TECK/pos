import api from '../api/axios'

/**
 * Export Customer account statement as PDF.
 * Uses axios to pass the Auth token.
 * If asBase64 is true, returns base64 string. Otherwise triggers download.
 */
export async function exportCustomerLedgerPDF(customerId, asBase64 = false) {
  if (!customerId) return
  
  try {
    const res = await api.get(`/customers/${customerId}/pdf`, { responseType: 'blob' })
    if (asBase64) {
      return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onloadend = () => resolve(reader.result.split(',')[1])
        reader.onerror = reject
        reader.readAsDataURL(res.data)
      })
    }

    const url = window.URL.createObjectURL(new Blob([res.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `customer_ledger_${customerId}.pdf`)
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    console.error('Failed to download PDF:', error)
    alert('فشل تحميل كشف الحساب')
  }
}

/**
 * Export Supplier account statement as PDF.
 * Uses axios to pass the Auth token.
 * If asBase64 is true, returns base64 string. Otherwise triggers download.
 */
export async function exportSupplierLedgerPDF(supplierId, asBase64 = false) {
  if (!supplierId) return
  
  try {
    const res = await api.get(`/suppliers/${supplierId}/pdf`, { responseType: 'blob' })
    if (asBase64) {
      return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onloadend = () => resolve(reader.result.split(',')[1])
        reader.onerror = reject
        reader.readAsDataURL(res.data)
      })
    }

    const url = window.URL.createObjectURL(new Blob([res.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `supplier_ledger_${supplierId}.pdf`)
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    console.error('Failed to download PDF:', error)
    alert('فشل تحميل كشف الحساب')
  }
}
