<?php

class SaleController extends Controller {

    private Invoice $invoiceModel;
    private Product $productModel;
    private PDO     $db;
    private Customer $customerModel;

    public function __construct() {
        $this->invoiceModel  = new Invoice();
        $this->productModel  = new Product();
        $this->customerModel = new Customer();
        $this->db            = Database::getInstance();
    }

    private function getSettings(): array {
        try {
            $rows = $this->db->query('SELECT `key`, `value` FROM settings')->fetchAll();
            $s = [];
            foreach ($rows as $r) { $s[$r['key']] = $r['value']; }
            return $s;
        } catch (Throwable $e) {
            // settings table may not exist yet; return safe defaults
            return ['tax_enabled' => '1', 'tax_rate' => '15'];
        }
    }

    public function index(): void {
        $filters = [
            'date'  => $this->getParam('date'),
            'month' => $this->getParam('month'),
            'year'  => $this->getParam('year'),
        ];
        Response::success($this->invoiceModel->all($filters));
    }

    public function show(string $id): void {
        $invoice = $this->invoiceModel->findById((int)$id);
        if (!$invoice) Response::notFound('Invoice not found');
        Response::success($invoice);
    }

    public function store(): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'items'          => 'required',
            'payment_method' => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        if (empty($data['items']) || !is_array($data['items'])) {
            Response::error('Cart cannot be empty', 400);
        }

        $replaceInvoiceId = isset($data['invoice_id']) ? (int) $data['invoice_id'] : 0;
        $existingInvoice  = null;
        if ($replaceInvoiceId > 0) {
            $existingInvoice = $this->invoiceModel->findById($replaceInvoiceId);
            if (!$existingInvoice) {
                Response::notFound('Invoice not found');
            }
        }

        $db = $this->db;

        // Validate all products and stock before starting transaction
        $enrichedItems = [];
        foreach ($data['items'] as $item) {
            if (empty($item['product_id']) || empty($item['quantity'])) {
                Response::error('Invalid item data', 400);
            }
            $product = $this->productModel->findById((int)$item['product_id']);
            if (!$product) {
                Response::error("Product ID {$item['product_id']} not found", 400);
            }
            // Negative stock allowed — no stock check
            $enrichedItems[] = [
                'product_id' => $product['id'],
                'quantity'   => (int)$item['quantity'],
                'price'      => (float)$product['price'],
                'product'    => $product,
            ];
        }

        // Calculate totals (read tax from settings)
        $settings = $this->getSettings();
        $taxEnabled = (bool)(int)($settings['tax_enabled'] ?? 1);
        $taxRate    = (float)($settings['tax_rate'] ?? 15) / 100;

        $subtotal = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $enrichedItems));
        $discount = (float)($data['discount'] ?? 0);
        $taxable  = $subtotal - $discount;
        $tax      = $taxEnabled ? round($taxable * $taxRate, 2) : 0;
        $total    = round($taxable + $tax, 2);
        $amountPaid = (float)($data['amount_paid'] ?? $total);
        $changeDue  = round($amountPaid - $total, 2);

        // البيع الآجل — معرف العميل وقيمة العربون
        $customerId = isset($data['customer_id']) && $data['customer_id'] > 0
            ? (int)$data['customer_id']
            : null;
        $isCreditSale = ($data['payment_method'] ?? '') === 'credit' || $customerId !== null;
        // العربون: المبلغ المدفوع فعلاً عند البيع الآجل
        $deposit = $isCreditSale ? (float)($data['deposit'] ?? 0) : 0;
        if ($isCreditSale) {
            $amountPaid = $deposit;
            $changeDue  = 0;
        }
        $amountDue = $isCreditSale ? round($total - $deposit, 2) : 0;

        $auth = $_SERVER['AUTH_USER'];

        // Begin transaction
        $db->beginTransaction();
        try {
            if ($replaceInvoiceId > 0) {
                foreach ($existingInvoice['items'] as $old) {
                    $this->productModel->incrementQuantity((int) $old['product_id'], (int) $old['quantity']);
                }
                $this->invoiceModel->deleteItemsByInvoiceId($replaceInvoiceId);
                $this->invoiceModel->updateTotals($replaceInvoiceId, [
                    'subtotal'       => $subtotal,
                    'discount'       => $discount,
                    'tax'            => $tax,
                    'total'          => $total,
                    'payment_method' => $data['payment_method'],
                    'amount_paid'    => $amountPaid,
                    'change_due'     => max(0, $changeDue),
                ]);
                $invoiceId = $replaceInvoiceId;
            } else {
                // إنشاء عميل جديد داخل الـ transaction إذا أُرسلت بياناته
                if ($customerId === null && !empty($data['new_customer']['name'])) {
                    $nc = $data['new_customer'];
                    $customerId = $this->customerModel->create([
                        'name'            => trim($nc['name']),
                        'phone'           => $nc['phone'] ?? null,
                        'address'         => $nc['address'] ?? null,
                        'initial_balance' => 0,
                    ]);
                }

                $invoiceId = $this->invoiceModel->create([
                    'user_id'        => $auth['id'],
                    'customer_id'    => $customerId,
                    'subtotal'       => $subtotal,
                    'discount'       => $discount,
                    'tax'            => $tax,
                    'total'          => $total,
                    'payment_method' => $data['payment_method'],
                    'amount_paid'    => $amountPaid,
                    'change_due'     => max(0, $changeDue),
                    'amount_due'     => $amountDue,
                ]);

                // تسجيل قيد مدين في كشف حساب العميل
                if ($customerId !== null) {
                    $depositDesc = $deposit > 0 ? " (عربون {$deposit})" : '';
                    $this->customerModel->addLedgerEntry([
                        'customer_id' => $customerId,
                        'type'        => 'debit',
                        'amount'      => $total,
                        'description' => "فاتورة بيع #{$invoiceId}{$depositDesc}",
                        'invoice_id'  => $invoiceId,
                        'created_by'  => $auth['id'],
                    ]);
                    // تسجيل العربون كقيد دائن إذا كان موجوداً
                    if ($deposit > 0) {
                        $this->customerModel->addLedgerEntry([
                            'customer_id' => $customerId,
                            'type'        => 'credit',
                            'amount'      => $deposit,
                            'description' => "عربون فاتورة #{$invoiceId}",
                            'invoice_id'  => $invoiceId,
                            'created_by'  => $auth['id'],
                        ]);
                    }
                }
            }

            foreach ($enrichedItems as $item) {
                $this->invoiceModel->addItem($invoiceId, $item);
                $this->productModel->decrementQuantity($item['product_id'], $item['quantity']);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log($e->getMessage());
            Response::serverError('Failed to process sale');
        }

        $invoice = $this->invoiceModel->findById($invoiceId);

        // Check and return low-stock warnings
        $lowStock = array_filter(
            array_map(fn($i) => $this->productModel->findById($i['product_id']), $enrichedItems),
            fn($p) => $p['quantity'] <= $p['low_stock_threshold']
        );

        $isUpdate = $replaceInvoiceId > 0;
        Response::success([
            'invoice'          => $invoice,
            'low_stock_alerts' => array_values($lowStock),
        ], $isUpdate ? 'Invoice updated' : 'Sale completed', $isUpdate ? 200 : 201);
    }

    /**
     * Permanently delete invoice and its lines; restore product quantities to stock.
     */
    public function destroy(string $id): void {
        $invoiceId = (int) $id;
        $invoice   = $this->invoiceModel->findById($invoiceId);
        if (!$invoice) {
            Response::notFound('Invoice not found');
        }

        $db = $this->db;
        $db->beginTransaction();
        try {
            foreach ($invoice['items'] as $item) {
                $this->productModel->incrementQuantity((int) $item['product_id'], (int) $item['quantity']);
            }
            $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$invoiceId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log($e->getMessage());
            Response::serverError('Failed to delete invoice');
        }

        Response::success(null, 'Invoice deleted');
    }
}
