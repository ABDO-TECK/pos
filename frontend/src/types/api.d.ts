/**
 * TypeScript Definitions for POS API
 * This file provides JSDoc and TypeScript definitions for better IntelliSense.
 */

declare global {
  // ── Auth ─────────────────────────────────────────────────────────────
  interface User {
    id: number;
    username: string;
    role: 'admin' | 'cashier';
    created_at?: string;
    updated_at?: string;
  }

  interface AuthResponse {
    user: User;
    message?: string;
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
    payment_method: 'cash' | 'card' | 'credit';
    paid_amount: number;
    due_amount: number;
    created_at?: string;
    items?: SaleItem[];
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
    type: 'payment' | 'receipt';
    notes?: string;
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

  // ── API Responses ────────────────────────────────────────────────────
  interface ApiResponse<T> {
    success: boolean;
    data: T;
    message?: string;
  }
}

export {};
