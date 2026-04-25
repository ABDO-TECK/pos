<?php

class CustomerController extends Controller {

    private Customer $model;
    private CustomerService $service;

    public function __construct() {
        $this->model = new Customer();
        $this->service = new CustomerService();
    }

    /** GET /api/customers */
    public function index() {
        $filters = [];
        if ($this->getParam('search'))  $filters['search']  = $this->getParam('search');
        if ($this->getParam('page'))    $filters['page']    = $this->getParam('page');
        if ($this->getParam('limit'))   $filters['limit']   = $this->getParam('limit');

        $result = $this->model->all($filters);

        // إذا أُرجع pagination — إرسال مع metadata
        if (isset($result['pagination'])) {
            return Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result);
        }
    }

    /** GET /api/customers/{id} — بيانات العميل + كشف الحساب */
    public function show(string $id) {
        $data = $this->model->getLedger((int)$id);
        if (!$data['customer']) {
            return Response::notFound('العميل غير موجود');
        }
        return Response::success($data);
    }

    /** POST /api/customers */
    public function store() {
        try {
            $request = new CustomerRequest($this->getBody());
            $data = $request->validated();
            
            $id = $this->service->createCustomer($data);
            return Response::success($this->model->findById($id), 'تم إضافة العميل', 201);
        } catch (ValidationException $e) {
            return Response::error('Validation failed', 422, $e->getErrors());
        } catch (Throwable $e) {
            return Response::serverError($e->getMessage());
        }
    }



    /** PUT /api/customers/{id} */
    public function update(string $id) {
        try {
            $request = new CustomerRequest($this->getBody());
            $data = $request->validated();
            
            $cid = (int)$id;
            if (!$this->model->findById($cid)) {
                return Response::notFound('العميل غير موجود');
            }
            $this->model->update($cid, $data);
            return Response::success($this->model->findById($cid), 'تم تحديث العميل');
        } catch (ValidationException $e) {
            return Response::error('Validation failed', 422, $e->getErrors());
        }
    }

    /** DELETE /api/customers/{id} */
    public function destroy(string $id) {
        $cid = (int)$id;
        if (!$this->model->findById($cid)) {
            return Response::notFound('العميل غير موجود');
        }
        $this->model->delete($cid);
        return Response::success(null, 'تم حذف العميل');
    }

    /**
     * POST /api/customers/{id}/payment
     * body: { amount: float, description?: string }
     * تسجيل دفعة (قيد دائن) في كشف حساب العميل
     */
    public function addPayment(string $id) {
        $cid  = (int)$id;
        $data = $this->getBody();
        $auth = $_SERVER['AUTH_USER'];

        try {
            $ledger = $this->service->addPayment($cid, $data, $auth);
            return Response::success($ledger, 'تم تسجيل الدفعة');
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            return $code === 404 ? Response::notFound($e->getMessage()) : Response::error($e->getMessage(), $code);
        }
    }

    /**
     * PUT /api/customers/ledger/{entryId}
     * body: { type: 'debit'|'credit', amount: float, description?: string }
     */
    public function updateLedgerEntry(string $entryId) {
        $eid  = (int)$entryId;
        $data = $this->getBody();

        try {
            $ledger = $this->service->updateLedgerEntry($eid, $data);
            return Response::success($ledger, 'تم تحديث القيد');
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            return $code === 404 ? Response::notFound($e->getMessage()) : Response::error($e->getMessage(), $code);
        }
    }
}


