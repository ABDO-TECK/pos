// @ts-nocheck
import { formatCurrency, formatPercent } from '../../../utils/formatters'
import { TotalLine } from './ReceiptHelpers'

export default function ReceiptTotals({ invoice, taxEnabled, taxRate, isCash, changeAmt }) {
    return (
        <div style={{ marginTop: '1mm' }}>
            <TotalLine label="المجموع الجزئي" value={formatCurrency(invoice.subtotal)} />
            {parseFloat(invoice.discount) > 0 && (
                <TotalLine label="الخصم" value={`- ${formatCurrency(invoice.discount)}`} />
            )}
            {taxEnabled && parseFloat(invoice.tax) > 0 && (
                <TotalLine label={`ضريبة (${formatPercent(taxRate)})`} value={formatCurrency(invoice.tax)} />
            )}
            <TotalLine label="الإجمالي" value={formatCurrency(invoice.total)} grand />
            {isCash && (
                <>
                    <TotalLine label="المدفوع" value={formatCurrency(invoice.amount_paid)} />
                    <TotalLine label="المسترد" value={formatCurrency(changeAmt)} />
                </>
            )}
            {invoice.payment_method === 'credit' && (
                <>
                    {parseFloat(invoice.amount_paid) > 0 && (
                        <TotalLine label="عربون مدفوع" value={formatCurrency(invoice.amount_paid)} />
                    )}
                    <TotalLine label="متبقي آجلاً" value={formatCurrency(invoice.amount_due ?? (invoice.total - invoice.amount_paid))} grand />
                </>
            )}
        </div>
    )
}
