<?php

class SaleController extends Controller {

    private SaleService $saleService;

    public function __construct() {
        $this->saleService = new SaleService();
    }

    public function index() {
        $filters = [
            'date'  => $this->getParam('date'),
            'month' => $this->getParam('month'),
            'year'  => $this->getParam('year'),
            'page'   => $this->getParam('page'),
            'limit'  => $this->getParam('limit'),
            'status' => $this->getParam('status'),
        ];

        $result = $this->saleService->getInvoiceModel()->all($filters);

        if (isset($result['pagination'])) {
            return Response::success($result['data'], null, 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result);
        }
    }

    public function show(string $id) {
        $invoice = $this->saleService->getInvoiceModel()->findById((int)$id);
        if (!$invoice) return Response::notFound('Invoice not found');
        return Response::success($invoice);
    }

    public function store() {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'items'          => 'required',
            'payment_method' => 'required',
        ]);
        if ($errors) return Response::error('Validation failed', 422, $errors);

        if (empty($data['items']) || !is_array($data['items'])) {
            return Response::error('Cart cannot be empty', 400);
        }

        // 1. إثراء والتحقق من البنود
        $enrichResult = $this->saleService->enrichItems($data['items']);
        if (!$enrichResult['ok']) {
            return Response::error($enrichResult['error'], $enrichResult['code']);
        }
        $enrichedItems = $enrichResult['items'];

        // 2. حساب الإجماليات
        $discount = (float)($data['discount'] ?? 0);
        $totals   = $this->saleService->calculateTotals($enrichedItems, $discount, $data);

        // 3. تنفيذ عملية البيع
        $auth   = $_SERVER['AUTH_USER'];
        $result = $this->saleService->processSale($enrichedItems, $totals, $data, $auth);

        if (!$result['ok']) {
            $code = $result['code'] ?? 500;
            return $code === 404
                ? Response::notFound($result['error'])
                : Response::error($result['error'], $code);
        }

        // 4. جلب الفاتورة الناتجة + تنبيهات المخزون
        $invoice  = $this->saleService->getInvoiceModel()->findById($result['invoice_id']);
        $lowStock = $this->saleService->getLowStockAlerts($enrichedItems);

        $isUpdate = $result['is_update'] ?? false;
        return Response::success([
            'invoice'          => $invoice,
            'low_stock_alerts' => $lowStock,
        ], $isUpdate ? 'Invoice updated' : 'Sale completed', $isUpdate ? 200 : 201);
    }

    public function updateStatus(string $id) {
        $data = $this->getBody();
        if (empty($data['status']) || !in_array($data['status'], ['completed', 'reserved', 'cancelled'])) {
            return Response::error('Invalid status', 400);
        }

        $invoice = $this->saleService->getInvoiceModel()->findById((int)$id);
        if (!$invoice) {
            return Response::notFound('Invoice not found');
        }

        $this->saleService->getInvoiceModel()->updateStatus((int)$id, $data['status']);
        return Response::success(null, 'Invoice status updated successfully');
    }

    /**
     * Permanently delete invoice and its lines; restore product quantities to stock.
     */
    public function destroy(string $id) {
        $result = $this->saleService->deleteInvoice((int) $id);

        if (!$result['ok']) {
            $code = $result['code'] ?? 500;
            return $code === 404
                ? Response::notFound($result['error'])
                : Response::serverError($result['error']);
        }

        return Response::success(null, 'Invoice deleted');
    }
}
