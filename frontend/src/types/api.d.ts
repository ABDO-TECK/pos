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
}

export {};
