declare global {
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
}

export {};
