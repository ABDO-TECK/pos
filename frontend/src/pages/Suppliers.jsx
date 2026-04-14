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
              style={{
                padding: '0.35rem 0.9rem',
                borderRadius: 'calc(var(--radius) - 2px)',
                border: 'none',
                fontSize: '0.88rem',
                fontWeight: tab === i ? 600 : 400,
                background: tab === i ? 'var(--surface)' : 'transparent',
                color: tab === i ? 'var(--primary-d)' : 'var(--text-muted)',
                boxShadow: tab === i ? 'var(--shadow)' : 'none',
                cursor: 'pointer',
                transition: 'all .15s',
                whiteSpace: 'nowrap',
              }}
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

      <style>{`
        /* سمة مورّدين بالأزرق — مميّزة عن نقطة البيع (الأخضر) */
        .sup-root {
          display: flex;
          flex-direction: column;
          gap: 0.75rem;
          height: calc(100dvh - 3rem);
          --primary: #2563eb;
          --primary-d: #1d4ed8;
          --secondary: #0891b2;
          --secondary-d: #0e7490;
          --sup-primary-soft: rgba(37, 99, 235, 0.11);
          --sup-settled-border: rgba(37, 99, 235, 0.32);
        }
        html[data-theme="dark"] .sup-root {
          --primary: #60a5fa;
          --primary-d: #3b82f6;
          --secondary: #22d3ee;
          --secondary-d: #06b6d4;
          --sup-primary-soft: rgba(96, 165, 250, 0.16);
          --sup-settled-border: rgba(96, 165, 250, 0.42);
        }
        .sup-root .input:focus {
          border-color: var(--primary);
          box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
        }
        html[data-theme="dark"] .sup-root .input:focus {
          box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.26);
        }
        .sup-root .badge-green {
          background: var(--sup-primary-soft);
          color: var(--primary-d);
        }
        html[data-theme="dark"] .sup-root .badge-green {
          color: #93c5fd;
        }
        .sup-root > .page-header {
          padding: 0.5rem 0.65rem;
          margin: -0.15rem 0 0.35rem 0;
          border-radius: var(--radius);
          border: 1px solid rgba(37, 99, 235, 0.2);
          background: linear-gradient(135deg, var(--sup-primary-soft), transparent);
        }
        html[data-theme="dark"] .sup-root > .page-header {
          border-color: rgba(96, 165, 250, 0.32);
          background: linear-gradient(135deg, rgba(96, 165, 250, 0.12), transparent);
        }
        @media (max-width: 767px) {
          .sup-root {
            height: calc(100dvh - 56px - 2rem);
          }
        }
      `}</style>
    </div>
  )
}
