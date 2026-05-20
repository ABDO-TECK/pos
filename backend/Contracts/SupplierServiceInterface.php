<?php
namespace App\Contracts;

interface SupplierServiceInterface
{
    public function addPayment(int $supplierId, array $data, array $authUser): array;
    public function updateLedgerEntry(int $entryId, array $data): array;
}
