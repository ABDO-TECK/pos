import api from './axios'

// Auth
export const login = (data) => api.post('/login', data)
export const logout = () => api.post('/logout')
export const getMe = () => api.get('/user')

// Categories
export const getCategories = () => api.get('/categories')
export const createCategory = (data) => api.post('/categories', data)
export const updateCategory = (id, data) => api.put(`/categories/${id}`, data)
export const deleteCategory = (id) => api.delete(`/categories/${id}`)

// Products
export const getProducts = (params) => api.get('/products', { params })
export const getProduct = (id) => api.get(`/products/${id}`)
export const getProductByBarcode = (barcode) =>
  api.get('/products/barcode', { params: { barcode } })
export const createProduct = (data) => api.post('/products', data)
export const updateProduct = (id, data) => api.put(`/products/${id}`, data)
export const deleteProduct = (id) => api.delete(`/products/${id}`)

// Sales
export const createSale = (data) => api.post('/sales', data)
export const getSales = (params) => api.get('/sales', { params })
export const getSale = (id) => api.get(`/sales/${id}`)
export const deleteSale = (id) => api.delete(`/sales/${id}`)

// Inventory
export const getInventory = (params) => api.get('/inventory', { params })
export const getLowStock = () => api.get('/inventory/low-stock')
export const adjustInventory = (id, data) => api.put(`/inventory/${id}`, data)

// Suppliers
export const getSuppliers = () => api.get('/suppliers')
export const getSupplier = (id) => api.get(`/suppliers/${id}`)
export const createSupplier = (data) => api.post('/suppliers', data)
export const updateSupplier = (id, data) => api.put(`/suppliers/${id}`, data)
export const deleteSupplier = (id) => api.delete(`/suppliers/${id}`)
export const createPurchase = (data) => api.post('/purchases', data)
export const getPurchases = (params) => api.get('/purchases', { params })

// Reports
export const getDailyReport = (params) => api.get('/reports/daily', { params })
export const getMonthlyReport = (params) => api.get('/reports/monthly', { params })
export const getTopProducts = (params) => api.get('/reports/products', { params })
export const getReportSummary = () => api.get('/reports/summary')

// Users
export const getUsers = () => api.get('/users')
export const createUser = (data) => api.post('/users', data)
export const updateUser = (id, data) => api.put(`/users/${id}`, data)
export const deleteUser = (id) => api.delete(`/users/${id}`)

// Settings
export const getSettings = () => api.get('/settings')
export const updateSettings = (data) => api.post('/settings', data)
export const downloadBackup = () => api.get('/backup', { responseType: 'blob' })
/** FormData مع الحقل sql_file */
export const restoreBackup = (formData) =>
  api.post('/backup/restore', formData, {
    transformRequest: [
      (data, headers) => {
        if (data instanceof FormData) delete headers['Content-Type']
        return data
      },
    ],
  })

// Purchases (bulk)
export const createBulkPurchase = (data) => api.post('/purchases/bulk', data)

// Purchase Invoices
export const getPurchaseInvoices = (params) => api.get('/purchase-invoices', { params })
export const getPurchaseInvoice  = (id)     => api.get(`/purchase-invoices/${id}`)
export const deletePurchaseInvoice = (id)   => api.delete(`/purchase-invoices/${id}`)
export const addSupplierPayment = (id, data) => api.post(`/suppliers/${id}/payment`, data)

// Reports (profit)
export const getProfitReport = (params) => api.get('/reports/profit', { params })

// Customers
export const getCustomers    = ()           => api.get('/customers')
export const getCustomer     = (id)         => api.get(`/customers/${id}`)
export const createCustomer  = (data)       => api.post('/customers', data)
export const updateCustomer  = (id, data)   => api.put(`/customers/${id}`, data)
export const deleteCustomer  = (id)         => api.delete(`/customers/${id}`)
export const addCustomerPayment = (id, data) => api.post(`/customers/${id}/payment`, data)
