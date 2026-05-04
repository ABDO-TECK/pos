/**
 * TypeScript Definitions for POS API
 * This file provides JSDoc and TypeScript definitions for better IntelliSense.
 */

declare global {
  // ── Auth ─────────────────────────────────────────────────────────────
  interface User {
    id: number;
    name: string;
    username?: string;
    email: string;
    password?: string;
    role: string;
    force_password_change?: number;
    created_at?: string;
    updated_at?: string;
  }

  interface AuthResponse {
    user: User;
    message?: string;
    data?: User; // To allow res.data.data access
  }

  // ── Categories ───────────────────────────────────────────────────────
  interface Category {
    id: number;
    name: string;
    description: string | null;
    created_at?: string;
  }

  // ── Products ─────────────────────────────────────────────────────────
  interface Product {
    id: number;
    barcode: string;
    name: string;
    category_id: number | null;
    category_name?: string;
    purchase_price: number;
    sale_price: number;
    stock_quantity: number;
    sell_by_weight: 0 | 1;
    units_per_box?: number;
    box_barcode?: string | null;
    additional_barcodes?: string[];
    low_stock_threshold?: number;
    created_at?: string;
    updated_at?: string;
  }

  // ── Sales ────────────────────────────────────────────────────────────
  interface SaleItem {
    product_id: number;
    quantity: number;
    unit_price: number;
    subtotal: number;
  }

  interface Sale {
    id: number;
    user_id: number;
    customer_id: number | null;
    total_amount: number;
    discount: number;
    tax: number;
    net_amount: number;
    payment_method: string; // Changed to string to support all methods
    paid_amount: number;
    due_amount: number;
    created_at?: string;
    items?: SaleItem[];
    invoice?: { id: number; status: string };
    low_stock_alerts?: { product_id: number; name: string; quantity: number }[];
  }

  // ── Suppliers ────────────────────────────────────────────────────────
  interface Supplier {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
    balance: number;
    created_at?: string;
  }

  // ── Customers ────────────────────────────────────────────────────────
  interface Customer {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
    balance: number;
    created_at?: string;
  }

  // ── Ledger / Payments ────────────────────────────────────────────────
  interface PaymentPayload {
    amount: number;
    type: string;
    notes?: string;
    description?: string;
  }

  // ── Expenses ─────────────────────────────────────────────────────────
  interface ExpenseCategory {
    id: number;
    name: string;
    created_at?: string;
  }

  interface Expense {
    id: number;
    category_id: number;
    category_name?: string;
    user_id: number;
    user_name?: string;
    amount: number;
    notes: string | null;
    expense_date: string;
    created_at?: string;
    updated_at?: string;
  }

  // ── Purchases ──────────────────────────────────────────────────
  interface PurchaseItem {
    product_id: number;
    product_name?: string;
    quantity: number;
    unit_cost: number;
    subtotal: number;
  }

  interface PurchaseInvoice {
    id: number;
    supplier_id: number;
    supplier_name?: string;
    user_id: number;
    user_name?: string;
    total: number;
    notes?: string | null;
    items?: PurchaseItem[];
    created_at?: string;
  }

  interface BulkPurchasePayload {
    supplier_id: number;
    items: { product_id: number; quantity: number; unit_cost: number; update_cost?: boolean }[];
    notes?: string;
    payment_amount?: number;
    payment_type?: string;
    deposit?: number;
    replace_invoice_id?: number | null;
  }

  // ── Reports ───────────────────────────────────────────────────
  interface DailyReport {
    date: string;
    total_sales: number;
    total_invoices: number;
    total_discount: number;
    total_tax: number;
    net_amount: number;
    payment_methods: Record<string, number>;
  }

  interface MonthlySummary {
    month: string;
    total_sales: number;
    total_invoices: number;
  }

  interface TopProduct {
    product_id: number;
    product_name: string;
    total_quantity: number;
    total_revenue: number;
  }

  interface ReportSummary {
    today_sales: number;
    today_invoices: number;
    month_sales: number;
    month_invoices: number;
    total_products: number;
    low_stock_count: number;
  }

  interface ProfitReport {
    total_revenue: number;
    total_cost: number;
    gross_profit: number;
    items: { product_name: string; revenue: number; cost: number; profit: number }[];
  }

  // ── Settings ──────────────────────────────────────────────────
  interface AppSettings {
    store_name?: string;
    tax_rate?: string;
    currency?: string;
    receipt_header?: string;
    receipt_footer?: string;
    [key: string]: string | undefined;
  }

  // ── Updates ───────────────────────────────────────────────────
  interface UpdateCheckResult {
    current_version: string;
    latest_version: string;
    has_update: boolean;
    released_at: string | null;
    changelog: { version: string; date: string; changes: string[] }[];
    requires_npm_install: boolean;
  }

  interface UpdateApplyResult {
    message: string;
    latest_version: string;
    changelog: { version: string; date: string; changes: string[] }[];
    logs: string[];
  }

  // ── Ledger ────────────────────────────────────────────────────
  interface LedgerEntry {
    id: number;
    type: 'debit' | 'credit';
    amount: number;
    description: string;
    invoice_id: number | null;
    created_by: number | null;
    created_at: string;
  }

  // ── API Responses ────────────────────────────────────────────────────
  interface ApiResponse<T> {
    success: boolean;
    data: T;
    message?: string;
    pagination?: { page: number; limit: number; total: number; pages: number };
  }
}

export {};
