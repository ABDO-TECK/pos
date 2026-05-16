declare global {
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

  interface Category {
    id: number;
    name: string;
    description: string | null;
    created_at?: string;
  }
}

export {};
