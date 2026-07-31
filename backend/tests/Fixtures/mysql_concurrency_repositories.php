<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Repositories\InvoiceRepository;
use App\Repositories\SupplierRepository;

final class ControlledInvoiceRepository extends InvoiceRepository
{
    public function __construct(
        Invoice $model,
        private readonly Closure $beforeOrAfterLock,
        private readonly bool $notifyBeforeLock,
        private readonly ?Closure $afterLock = null
    ) {
        parent::__construct($model);
    }

    public function findByIdForUpdate(int $id): ?array
    {
        if ($this->notifyBeforeLock) {
            ($this->beforeOrAfterLock)();
        }
        $invoice = parent::findByIdForUpdate($id);
        if (!$this->notifyBeforeLock) {
            ($this->beforeOrAfterLock)();
        }
        if ($this->afterLock !== null) {
            ($this->afterLock)();
        }

        return $invoice;
    }
}

final class ControlledSupplierRepository extends SupplierRepository
{
    public function __construct(
        Supplier $model,
        PurchaseInvoice $purchaseInvoiceModel,
        SupplierLedger $ledgerModel,
        private readonly Closure $beforeOrAfterLock,
        private readonly bool $notifyBeforeLock,
        private readonly ?Closure $afterLock = null
    ) {
        parent::__construct($model, $purchaseInvoiceModel, $ledgerModel);
    }

    public function getPurchaseInvoiceHeaderForUpdate(int $id): ?array
    {
        if ($this->notifyBeforeLock) {
            ($this->beforeOrAfterLock)();
        }
        $invoice = parent::getPurchaseInvoiceHeaderForUpdate($id);
        if (!$this->notifyBeforeLock) {
            ($this->beforeOrAfterLock)();
        }
        if ($this->afterLock !== null) {
            ($this->afterLock)();
        }

        return $invoice;
    }
}
