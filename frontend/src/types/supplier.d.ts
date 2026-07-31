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
    cost?: number;
    name?: string;
    product_barcode?: string;
    size_name?: string;
    unit_type?: 'piece' | 'weight' | 'liter';
    sell_by_weight?: number;
  }

  interface PurchaseInvoice {
    id: number;
    invoice_id?: number;
    supplier_id: number;
    supplier_name?: string;
    user_id: number;
    user_name?: string;
    total: number;
    notes?: string | null;
    driver_name?: string | null;
    vehicle_number?: string | null;
    delivery_date?: string | null;
    delivery_notes?: string | null;
    items?: PurchaseItem[];
    items_count?: number;
    created_at?: string;
  }

  interface BulkPurchasePayload {
    supplier_id: number;
    items: { product_id: number; quantity: number; cost: number; update_cost?: boolean }[];
    notes?: string;
    payment_amount?: number;
    payment_type?: string;
    deposit?: number;
    replace_invoice_id?: number | null;
    driver_name?: string;
    vehicle_number?: string;
    delivery_date?: string;
    delivery_notes?: string;
  }
}

export {};
