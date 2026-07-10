declare global {
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
    payment_method: string;
    paid_amount: number;
    due_amount: number;
    created_at?: string;
    items?: SaleItem[];
    invoice?: { id: number; status: string };
    low_stock_alerts?: { product_id: number; name: string; quantity: number }[];
    driver_name?: string | null;
    vehicle_number?: string | null;
    delivery_date?: string | null;
    delivery_notes?: string | null;
  }
}

export {};
