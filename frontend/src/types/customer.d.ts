declare global {
  interface Customer {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
    balance: number;
    created_at?: string;
  }

  interface PaymentPayload {
    amount: number;
    type: string;
    notes?: string;
    description?: string;
  }

  interface LedgerEntry {
    id: number;
    type: 'debit' | 'credit';
    amount: number;
    description: string;
    invoice_id: number | null;
    created_by: number | null;
    created_at: string;
  }
}

export {};
