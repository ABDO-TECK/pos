<?php

namespace App\Repositories;

use App\Models\Supplier;

class SupplierRepository
{
    private Supplier $model;

    public function __construct(Supplier $model)
    {
        $this->model = $model;
    }

    public function all(array $filters = []): array
    {
        return $this->model->all($filters);
    }

    public function findById(int $id): ?array
    {
        return $this->model->findById($id);
    }

    public function create(array $data): int
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): void
    {
        $this->model->update($id, $data);
    }

    public function delete(int $id): void
    {
        $this->model->delete($id);
    }

    public function createPurchaseInvoice(array $data): int
    {
        return $this->model->createPurchaseInvoice($data);
    }

    public function createPurchase(array $data): int
    {
        return $this->model->createPurchase($data);
    }

    public function getPurchaseInvoices(array $filters = []): array
    {
        return $this->model->getPurchaseInvoices($filters);
    }

    public function getPurchaseInvoice(int $id): ?array
    {
        return $this->model->getPurchaseInvoice($id);
    }

    public function deletePurchaseInvoiceItems(int $id): void
    {
        $this->model->deletePurchaseInvoiceItems($id);
    }

    public function updatePurchaseInvoiceTotals(int $id, array $data): void
    {
        $this->model->updatePurchaseInvoiceTotals($id, $data);
    }

    public function deletePurchaseInvoice(int $id): array
    {
        return $this->model->deletePurchaseInvoice($id);
    }

    public function getPurchases(array $filters = []): array
    {
        return $this->model->getPurchases($filters);
    }

    public function getLedger(int $supplierId): array
    {
        return $this->model->getLedger($supplierId);
    }

    public function addLedgerEntry(array $data): int
    {
        return $this->model->addLedgerEntry($data);
    }

    public function updateLedgerEntry(int $entryId, array $data): void
    {
        $this->model->updateLedgerEntry($entryId, $data);
    }

    public function getLedgerEntry(int $entryId): ?array
    {
        return $this->model->getLedgerEntry($entryId);
    }

    public function getTotalSuppliersCount(): int
    {
        return $this->model->getTotalSuppliersCount();
    }

    public function getModel(): Supplier
    {
        return $this->model;
    }
}
