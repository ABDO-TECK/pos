import api from './axios'

// Auth
export const getCsrfCookie = () => api.get('/csrf-cookie')
export const login = (data: any) => api.post('/login', data)
export const logout = () => api.post('/logout')
export const getMe = () => api.get('/user')

export const getChangelog = () => api.get('/update/changelog')

// Categories
export const getCategories = (params?: any) => api.get('/categories', { params })
export const createCategory = (data: any) => api.post('/categories', data)
export const updateCategory = (id: number | string, data: any) => api.put(`/categories/${id}`, data)
export const deleteCategory = (id: number | string) => api.delete(`/categories/${id}`)

// Products
export const getProducts = (params?: any) => api.get('/products', { params })
export const getProduct = (id: number | string) => api.get(`/products/${id}`)
export const getProductByBarcode = (barcode: string) =>
  api.get('/products/barcode', { params: { barcode } })
export const createProduct = (data: any) => api.post('/products', data)
export const updateProduct = (id: number | string, data: any) => api.put(`/products/${id}`, data)
export const deleteProduct = (id: number | string) => api.delete(`/products/${id}`)

// Sales
export const createSale = (data: any) => api.post('/sales', data)
export const getSales = (params?: any) => api.get('/sales', { params })
export const getSale = (id: number | string) => api.get(`/sales/${id}`)
export const updateSaleStatus = (id: number | string, data: any) => api.put(`/sales/${id}/status`, data)
export const deleteSale = (id: number | string) => api.delete(`/sales/${id}`)

// Inventory
export const getInventory = (params?: any) => api.get('/inventory', { params })
export const getLowStock = () => api.get('/inventory/low-stock')
export const adjustInventory = (id: number | string, data: any) => api.put(`/inventory/${id}`, data)

// Suppliers
export const getSuppliers = () => api.get('/suppliers')
export const getSupplier = (id: number | string) => api.get(`/suppliers/${id}`)
export const createSupplier = (data: any) => api.post('/suppliers', data)
export const updateSupplier = (id: number | string, data: any) => api.put(`/suppliers/${id}`, data)
export const deleteSupplier = (id: number | string) => api.delete(`/suppliers/${id}`)
export const createPurchase = (data: any) => api.post('/purchases', data)
export const getPurchases = (params?: any) => api.get('/purchases', { params })

// Reports
export const getDailyReport = (params?: any) => api.get('/reports/daily', { params })
export const getMonthlyReport = (params?: any) => api.get('/reports/monthly', { params })
export const getTopProducts = (params?: any) => api.get('/reports/products', { params })
export const getReportSummary = () => api.get('/reports/summary')

// Users
export const getUsers = () => api.get('/users')
export const createUser = (data: any) => api.post('/users', data)
export const updateUser = (id: number | string, data: any) => api.put(`/users/${id}`, data)
export const deleteUser = (id: number | string) => api.delete(`/users/${id}`)

// Settings
export const getSettings = () => api.get('/settings')
export const updateSettings = (data: any) => api.post('/settings', data)

// Updates
export const checkUpdate = () => api.get('/update/check')
export const applyUpdate = () => api.post('/update/apply', null, { timeout: 300_000 })

// Backup
export const downloadBackup = () => api.get('/backup', { responseType: 'blob' })
/** FormData مع الحقل sql_file */
export const restoreBackup = (formData: FormData) =>
  api.post('/backup/restore', formData, {
    transformRequest: [
      (data, headers) => {
        if (data instanceof FormData) delete headers['Content-Type']
        return data
      },
    ],
  })

// Purchases (bulk)
export const createBulkPurchase = (data: any) => api.post('/purchases/bulk', data)

// Purchase Invoices
export const getPurchaseInvoices = (params?: any) => api.get('/purchase-invoices', { params })
export const getPurchaseInvoice  = (id: number | string)     => api.get(`/purchase-invoices/${id}`)
export const deletePurchaseInvoice = (id: number | string)   => api.delete(`/purchase-invoices/${id}`)
export const addSupplierPayment = (id: number | string, data: any) => api.post(`/suppliers/${id}/payment`, data)
export const updateSupplierLedgerEntry = (entryId: number | string, data: any) => api.put(`/suppliers/ledger/${entryId}`, data)

// Reports (profit)
export const getProfitReport = (params?: any) => api.get('/reports/profit', { params })

// Customers
export const getCustomers    = ()           => api.get('/customers')
export const getCustomer     = (id: number | string)         => api.get(`/customers/${id}`)
export const createCustomer  = (data: any)       => api.post('/customers', data)
export const updateCustomer  = (id: number | string, data: any)   => api.put(`/customers/${id}`, data)
export const deleteCustomer  = (id: number | string)         => api.delete(`/customers/${id}`)
export const addCustomerPayment = (id: number | string, data: any) => api.post(`/customers/${id}/payment`, data)
export const updateCustomerLedgerEntry = (entryId: number | string, data: any) => api.put(`/customers/ledger/${entryId}`, data)
