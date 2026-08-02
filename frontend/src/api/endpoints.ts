import api from './axios'
import type { AxiosRequestConfig } from 'axios'
import { DEFAULT_PAGE, DEFAULT_PAGE_SIZE } from './constants'

// Auth
export const getCsrfCookie = () => api.get('/csrf-cookie')
export const login = (data: Record<string, string>) => api.post<AuthResponse>('/login', data)
export const refreshToken = () => api.post('/refresh')
export const logout = () => api.post('/logout')
export const getMe = () => api.get<ApiResponse<User>>('/user')

export const getChangelog = () => api.get('/update/changelog')

// Categories
export const getCategories = (params?: ApiQueryParams) => api.get<ApiResponse<Category[]>>('/categories', { params: { page: DEFAULT_PAGE, limit: DEFAULT_PAGE_SIZE, ...params } })
export const createCategory = (data: Partial<Category>) => api.post<ApiResponse<Category>>('/categories', data)
export const updateCategory = (id: number | string, data: Partial<Category>) => api.put<ApiResponse<Category>>(`/categories/${id}`, data)
export const deleteCategory = (id: number | string) => api.delete<ApiResponse<null>>(`/categories/${id}`)

// Products
export const getProducts = (params?: ApiQueryParams) => api.get<ApiResponse<Product[]>>('/products', { params })
export async function getProductCatalogPage(
  checkpoint?: string,
  pageSize = 500,
): Promise<ProductCatalogPage> {
  const limit = Math.max(1, Math.min(500, Math.trunc(pageSize)))
  const response = await api.get<ApiResponse<Product[]>>('/products/sync', {
    params: { checkpoint, limit },
  })
  const pagination = response.data.pagination
  if (
    !response.data.catalog_scope
    || response.data.catalog_version === undefined
    || pagination?.type !== 'cursor'
    || !pagination.mode
    || !pagination.next_checkpoint
  ) {
    throw new Error('Invalid product catalog sync response')
  }

  return {
    products: Array.isArray(response.data.data) ? response.data.data : [],
    scope: response.data.catalog_scope,
    version: response.data.catalog_version,
    pagination: {
      type: 'cursor',
      mode: pagination.mode,
      limit: pagination.limit,
      hasMore: pagination.has_more === true,
      truncated: pagination.truncated === true,
      reset: pagination.reset === true,
      nextCheckpoint: pagination.next_checkpoint,
    },
  }
}
export const getProduct = (id: number | string) => api.get<ApiResponse<Product>>(`/products/${id}`)
export const getProductByBarcode = (barcode: string) =>
  api.get<ApiResponse<Product>>('/products/barcode', { params: { barcode } })
export const createProduct = (data: Partial<Product>) => api.post<ApiResponse<Product>>('/products', data)
export const updateProduct = (id: number | string, data: Partial<Product>) => api.put<ApiResponse<Product>>(`/products/${id}`, data)
export const deleteProduct = (id: number | string) => api.delete<ApiResponse<null>>(`/products/${id}`)

// Sales
export const createSale = (data: SaleCreatePayload) => api.post<ApiResponse<Sale>>('/sales', data)
export const getSales = (params?: ApiQueryParams) => api.get<ApiResponse<Sale[]>>('/sales', { params })
export const getSale = (id: number | string) => api.get<ApiResponse<Sale>>(`/sales/${id}`)
export const updateSaleStatus = (id: number | string, data: { status: string }) => api.put<ApiResponse<null>>(`/sales/${id}/status`, data)
export const deleteSale = (id: number | string) => api.delete<ApiResponse<null>>(`/sales/${id}`)

// Inventory
export const getInventory = (params?: ApiQueryParams) => api.get<ApiResponse<Product[]>>('/inventory', { params: { page: DEFAULT_PAGE, limit: DEFAULT_PAGE_SIZE, ...params } })
export const getLowStock = (params?: ApiQueryParams) => api.get<ApiResponse<Product[]>>('/inventory/low-stock', { params: { page: DEFAULT_PAGE, limit: DEFAULT_PAGE_SIZE, ...params } })
export const adjustInventory = (id: number | string, data: { type: 'add' | 'subtract' | 'set'; quantity: number }) => api.put<ApiResponse<Product>>(`/inventory/${id}`, data)

// Suppliers
export const getSuppliers = (params?: ApiQueryParams, config?: AxiosRequestConfig) => api.get<ApiResponse<Supplier[]>>('/suppliers', { ...config, params: { page: DEFAULT_PAGE, limit: DEFAULT_PAGE_SIZE, ...params } })
export const getSupplier = (id: number | string, config?: AxiosRequestConfig) => api.get<ApiResponse<SupplierLedgerData>>(`/suppliers/${id}`, config)
export const searchSuppliers = async (search: string, signal?: AbortSignal): Promise<Supplier[]> => {
  const response = await getSuppliers({ page: 1, limit: 20, search: search || undefined }, { signal })
  return Array.isArray(response.data.data) ? response.data.data : []
}
export const getSupplierOption = async (id: number, signal?: AbortSignal): Promise<Supplier | null> => {
  const response = await getSupplier(id, { signal })
  return response.data.data?.supplier ?? null
}
export const createSupplier = (data: Partial<Supplier>) => api.post<ApiResponse<Supplier>>('/suppliers', data)
export const updateSupplier = (id: number | string, data: Partial<Supplier>) => api.put<ApiResponse<Supplier>>(`/suppliers/${id}`, data)
export const deleteSupplier = (id: number | string) => api.delete<ApiResponse<null>>(`/suppliers/${id}`)
export const createPurchase = (data: BulkPurchasePayload) => api.post<ApiResponse<PurchaseInvoice>>('/purchases', data)
export const getPurchases = (params?: ApiQueryParams) => api.get<ApiResponse<PurchaseInvoice[]>>('/purchases', { params })

// Reports
export const getDailyReport = (params?: ApiQueryParams) => api.get<ApiResponse<DailyReport>>('/reports/daily', { params })
export const getMonthlyReport = (params?: ApiQueryParams) => api.get<ApiResponse<MonthlySummary[]>>('/reports/monthly', { params })
export const getTopProducts = (params?: ApiQueryParams) => api.get<ApiResponse<TopProduct[]>>('/reports/products', { params })
export const getReportSummary = () => api.get<ApiResponse<ReportSummary>>('/reports/summary')

// Users
export const getUsers = (params?: ApiQueryParams) => api.get<ApiResponse<User[]>>('/users', { params: { page: DEFAULT_PAGE, limit: DEFAULT_PAGE_SIZE, ...params } })
export const createUser = (data: Partial<User>) => api.post<ApiResponse<User>>('/users', data)
export const updateUser = (id: number | string, data: UserUpdatePayload) => api.put<ApiResponse<User>>(`/users/${id}`, data)
export const deleteUser = (id: number | string) => api.delete<ApiResponse<null>>(`/users/${id}`)

// Settings
export const getSettings = () => api.get<ApiResponse<AppSettings>>('/settings')
export const updateSettings = (data: Partial<AppSettings>) => api.post<ApiResponse<AppSettings>>('/settings', data)

// Updates
type UpdateRequestConfig = AxiosRequestConfig & { hideGlobalError?: boolean }
export const checkUpdate = (config?: UpdateRequestConfig) => api.get<ApiResponse<UpdateCheckResult>>('/update/check', config)
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
export const getPurchaseInvoices = (params?: ApiQueryParams) => api.get<ApiResponse<PurchaseInvoice[]>>('/purchase-invoices', { params })
export const getPurchaseInvoice  = (id: number | string)     => api.get<ApiResponse<PurchaseInvoice>>(`/purchase-invoices/${id}`)
export const deletePurchaseInvoice = (id: number | string)   => api.delete<ApiResponse<null>>(`/purchase-invoices/${id}`)
export const updateSupplierLedgerEntry = (entryId: number | string, data: PaymentPayload) => api.put<ApiResponse<LedgerEntry[]>>(`/suppliers/ledger/${entryId}`, data)
export const deleteSupplierLedgerEntry = (entryId: number | string) => api.delete<ApiResponse<LedgerEntry[]>>(`/suppliers/ledger/${entryId}`)
export const addSupplierPayment = (id: number | string, data: PaymentPayload) => api.post<ApiResponse<LedgerEntry[]>>(`/suppliers/${id}/payment`, data)

// Reports (profit)
export const getProfitReport = (params?: ApiQueryParams) => api.get<ApiResponse<ProfitReport>>('/reports/profit', { params })

// Customers
export const getCustomers    = (params?: ApiQueryParams, config?: AxiosRequestConfig) => api.get<ApiResponse<Customer[]>>('/customers', { ...config, params: { page: DEFAULT_PAGE, limit: DEFAULT_PAGE_SIZE, ...params } })
export const getCustomer     = (id: number | string, config?: AxiosRequestConfig) => api.get<ApiResponse<CustomerLedgerData>>(`/customers/${id}`, config)
export const searchCustomers = async (search: string, signal?: AbortSignal): Promise<Customer[]> => {
  const response = await getCustomers({ page: 1, limit: 20, search: search || undefined }, { signal })
  return Array.isArray(response.data.data) ? response.data.data : []
}
export const getCustomerOption = async (id: number, signal?: AbortSignal): Promise<Customer | null> => {
  const response = await getCustomer(id, { signal })
  return response.data.data?.customer ?? null
}
export const createCustomer  = (data: Partial<Customer>)       => api.post<ApiResponse<Customer>>('/customers', data)
export const updateCustomer  = (id: number | string, data: Partial<Customer>)   => api.put<ApiResponse<Customer>>(`/customers/${id}`, data)
export const deleteCustomer  = (id: number | string)         => api.delete<ApiResponse<null>>(`/customers/${id}`)
export const addCustomerPayment = (id: number | string, data: PaymentPayload) => api.post<ApiResponse<CustomerLedgerData>>(`/customers/${id}/payment`, data)
export const updateCustomerLedgerEntry = (entryId: number | string, data: PaymentPayload) => api.put<ApiResponse<CustomerLedgerData>>(`/customers/ledger/${entryId}`, data)
export const deleteCustomerLedgerEntry = (entryId: number | string) => api.delete<ApiResponse<CustomerLedgerData>>(`/customers/ledger/${entryId}`)

// Health Check
export const getHealthCheck = () => api.get('/health')
export interface HealthDiagnostics {
  status: 'ok' | 'degraded' | 'failed'
  critical_failed: boolean
  version: string
  checks: {
    database: { status: string; latency_ms?: number | null }
    disk: { status: string; free_gb: number; total_gb: number; used_percent: number }
    memory: { status: string; usage_mb: number; peak_mb: number; limit: string }
    php: { status: string; version: string; extensions: Record<string, boolean> }
  }
}
export const getHealthDiagnostics = () => api.get<HealthDiagnostics>('/health/diagnostics')
export const getHealthMetrics = () => api.get('/health/metrics')
export const getNetworkInfo = () => api.get<ApiResponse<{ ips: string[]; port: string; protocol: string }>>('/system/network-info')
