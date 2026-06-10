/// <reference path="./auth.d.ts" />
/// <reference path="./product.d.ts" />
/// <reference path="./sale.d.ts" />
/// <reference path="./supplier.d.ts" />
/// <reference path="./customer.d.ts" />
/// <reference path="./expense.d.ts" />
/// <reference path="./report.d.ts" />
/// <reference path="./settings.d.ts" />

declare global {
  interface ApiResponse<T> {
    success: boolean;
    data: T;
    message?: string;
    pagination?: { page: number; limit: number; total: number; pages: number };
  }

  interface ApiQueryParams {
    page?: number | string;
    limit?: number | string;
    search?: string;
    category_id?: number | string;
    supplier_id?: number | string;
    customer_id?: number | string;
    status?: string;
    date_from?: string;
    date_to?: string;
    date?: string;
    month?: number | string;
    year?: number | string;
    type?: string;
    sort?: string;
    order?: 'asc' | 'desc';
    barcode?: string;
    brand?: string;
  }
}

export {};
