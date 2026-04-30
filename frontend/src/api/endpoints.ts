import api from './axios'

// Auth
export const getCsrfCookie = () => api.get('/csrf-cookie')
export const login = (data: Record<string, unknown>) => api.post<AuthResponse>('/login', data)
export const logout = () => api.post('/logout')
export const getMe = () => api.get<ApiResponse<User>>('/user')

export const getChangelog = () => api.get('/update/changelog')

// Categories
export const getCategories = (params?: Record<string, unknown>) => api.get<ApiResponse<Category[]>>('/categories', { params })
export const createCategory = (data: Partial<Category>) => api.post<ApiResponse<Category>>('/categories', data)
export const updateCategory = (id: number | string, data: Partial<Category>) => api.put<ApiResponse<Category>>(`/categories/${id}`, data)
export const deleteCategory = (id: number | string) => api.delete<ApiResponse<null>>(`/categories/${id}`)

// Products
export const getProducts = (params?: Record<string, unknown>) => api.get<ApiResponse<Product[]>>('/products', { params })
export const getProduct = (id: number | string) => api.get<ApiResponse<Product>>(`/products/${id}`)
export const getProductByBarcode = (barcode: string) =>
  api.get<ApiResponse<Product>>('/products/barcode', { params: { barcode } })
export const createProduct = (data: Partial<Product>) => api.post<ApiResponse<Product>>('/products', data)
export const updateProduct = (id: number | string, data: Partial<Product>) => api.put<ApiResponse<Product>>(`/products/${id}`, data)
export const deleteProduct = (id: number | string) => api.delete<ApiResponse<null>>(`/products/${id}`)

// Sales
export const createSale = (data: Partial<Sale>) => api.post<ApiResponse<Sale>>('/sales', data)
export const getSales = (params?: Record<string, unknown>) => api.get<ApiResponse<Sale[]>>('/sales', { params })
export const getSale = (id: number | string) => api.get<ApiResponse<Sale>>(`/sales/${id}`)
export const updateSaleStatus = (id: number | string, data: { status: string }) => api.put<ApiResponse<null>>(`/sales/${id}/status`, data)
export const deleteSale = (id: number | string) => api.delete<ApiResponse<null>>(`/sales/${id}`)

// Inventory
export const getInventory = (params?: Record<string, unknown>) => api.get<ApiResponse<Product[]>>('/inventory', { params })
export const getLowStock = () => api.get<ApiResponse<Product[]>>('/inventory/low-stock')
export const adjustInventory = (id: number | string, data: { type: 'add' | 'subtract' | 'set'; quantity: number }) => api.put<ApiResponse<Product>>(`/inventory/${id}`, data)

// Suppliers
export const getSuppliers = (params?: Record<string, unknown>) => api.get<ApiResponse<Supplier[]>>('/suppliers', { params: { page: 1, limit: 50, ...params } })
export const getSupplier = (id: number | string) => api.get<ApiResponse<Supplier>>(`/suppliers/${id}`)
export const createSupplier = (data: Partial<Supplier>) => api.post<ApiResponse<Supplier>>('/suppliers', data)
export const updateSupplier = (id: number | string, data: Partial<Supplier>) => api.put<ApiResponse<Supplier>>(`/suppliers/${id}`, data)
export const deleteSupplier = (id: number | string) => api.delete<ApiResponse<null>>(`/suppliers/${id}`)
export const createPurchase = (data: BulkPurchasePayload) => api.post<ApiResponse<PurchaseInvoice>>('/purchases', data)
export const getPurchases = (params?: Record<string, unknown>) => api.get<ApiResponse<PurchaseInvoice[]>>('/purchases', { params })

// Reports
export const getDailyReport = (params?: Record<string, unknown>) => api.get<ApiResponse<DailyReport>>('/reports/daily', { params })
export const getMonthlyReport = (params?: Record<string, unknown>) => api.get<ApiResponse<MonthlySummary[]>>('/reports/monthly', { params })
export const getTopProducts = (params?: Record<string, unknown>) => api.get<ApiResponse<TopProduct[]>>('/reports/products', { params })
export const getReportSummary = () => api.get<ApiResponse<ReportSummary>>('/reports/summary')

// Users
export const getUsers = (params?: Record<string, unknown>) => api.get<ApiResponse<User[]>>('/users', { params: { page: 1, limit: 50, ...params } })
export const createUser = (data: Partial<User>) => api.post<ApiResponse<User>>('/users', data)
export const updateUser = (id: number | string, data: Partial<User>) => api.put<ApiResponse<User>>(`/users/${id}`, data)
export const deleteUser = (id: number | string) => api.delete<ApiResponse<null>>(`/users/${id}`)

// Settings
export const getSettings = () => api.get<ApiResponse<AppSettings>>('/settings')
export const updateSettings = (data: Partial<AppSettings>) => api.post<ApiResponse<AppSettings>>('/settings', data)

// Updates
export const checkUpdate = () => api.get<ApiResponse<UpdateCheckResult>>('/update/check')
export const applyUpdate = (force = false) => api.post<ApiResponse<UpdateApplyResult>>('/update/apply', force ? { force: true } : null, { timeout: 300_000 })

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
export const createBulkPurchase = (data: BulkPurchasePayload) => api.post<ApiResponse<PurchaseInvoice>>('/purchases/bulk', data)

// Purchase Invoices
export const getPurchaseInvoices = (params?: Record<string, unknown>) => api.get<ApiResponse<PurchaseInvoice[]>>('/purchase-invoices', { params })
export const getPurchaseInvoice  = (id: number | string)     => api.get<ApiResponse<PurchaseInvoice>>(`/purchase-invoices/${id}`)
export const deletePurchaseInvoice = (id: number | string)   => api.delete<ApiResponse<null>>(`/purchase-invoices/${id}`)
export const addSupplierPayment = (id: number | string, data: PaymentPayload) => api.post<ApiResponse<LedgerEntry[]>>(`/suppliers/${id}/payment`, data)
export const updateSupplierLedgerEntry = (entryId: number | string, data: PaymentPayload) => api.put<ApiResponse<LedgerEntry[]>>(`/suppliers/ledger/${entryId}`, data)

// Reports (profit)
export const getProfitReport = (params?: Record<string, unknown>) => api.get<ApiResponse<ProfitReport>>('/reports/profit', { params })

// Customers
export const getCustomers    = (params?: Record<string, unknown>)           => api.get<ApiResponse<Customer[]>>('/customers', { params: { page: 1, limit: 50, ...params } })
export const getCustomer     = (id: number | string)         => api.get<ApiResponse<Customer>>(`/customers/${id}`)
export const createCustomer  = (data: Partial<Customer>)       => api.post<ApiResponse<Customer>>('/customers', data)
export const updateCustomer  = (id: number | string, data: Partial<Customer>)   => api.put<ApiResponse<Customer>>(`/customers/${id}`, data)
export const deleteCustomer  = (id: number | string)         => api.delete<ApiResponse<null>>(`/customers/${id}`)
export const addCustomerPayment = (id: number | string, data: PaymentPayload) => api.post<ApiResponse<LedgerEntry[]>>(`/customers/${id}/payment`, data)
export const updateCustomerLedgerEntry = (entryId: number | string, data: PaymentPayload) => api.put<ApiResponse<LedgerEntry[]>>(`/customers/ledger/${entryId}`, data)
