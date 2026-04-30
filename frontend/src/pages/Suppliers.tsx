import { useState } from 'react'
import ReceiveGoods from './suppliers/ReceiveGoods'
import PurchaseHistory from './suppliers/PurchaseHistory'
import ManageSuppliers from './suppliers/ManageSuppliers'
import SupplierAccounts from './suppliers/SupplierAccounts'

export default function Suppliers() {
  const [tab, setTab] = useState(0)
  const [receiveCart, setReceiveCart] = useState([])
  const [receiveSupplierId, setReceiveSupplierId] = useState('')
  const [receiveInvoiceId, setReceiveInvoiceId] = useState(null)

  return (
    <div className="sup-root">
      {/* Header + tab selector */}
      <div className="page-header col-mobile" style={{ marginBottom: 0 }}>
        <h2>الموردون</h2>
        <div className="page-tabs-bar">
          {['استلام بضاعة', 'سجل المشتريات', 'إدارة الموردين', 'حسابات الموردين'].map((t, i) => (
            <button
              key={i}
              onClick={() => setTab(i)}
              className={`sup-tab-btn ${tab === i ? 'active' : ''}`}
            >
              {t}
            </button>
          ))}
        </div>
      </div>

      {tab === 0 && <ReceiveGoods cart={receiveCart} setCart={setReceiveCart} supplierId={receiveSupplierId} setSupplierId={setReceiveSupplierId} invoiceId={receiveInvoiceId} setInvoiceId={setReceiveInvoiceId} />}
      {tab === 1 && <PurchaseHistory onReturnToCart={(items, sId, originalInvoiceId) => {
        setReceiveSupplierId(String(sId))
        setReceiveInvoiceId(originalInvoiceId)
        setReceiveCart(items.map(i => ({
          product: { id: i.product_id, name: i.product_name, barcode: i.product_barcode, price: i.price, cost: i.cost, units_per_box: 1 },
          quantity: parseInt(i.quantity, 10),
          cost: parseFloat(i.cost)
        })))
        setTab(0)
      }} />}
      {tab === 2 && <ManageSuppliers />}
      {tab === 3 && <SupplierAccounts />}

    </div>
  )
}
