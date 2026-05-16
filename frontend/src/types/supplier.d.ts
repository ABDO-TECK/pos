declare global {
  interface Supplier {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
    balance: number;
    created_at?: string;
  }

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
}

export {};
