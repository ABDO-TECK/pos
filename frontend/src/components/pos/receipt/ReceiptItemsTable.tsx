// @ts-nocheck
import { formatNumber } from '../../../utils/formatters'
import { Th, Td } from './ReceiptHelpers'

export default function ReceiptItemsTable({ items }) {
    return (
        <table style={{ width: '100%', borderCollapse: 'collapse', margin: '1.5mm 0' }}>
            <thead>
                <tr>
                    <Th>#</Th>
                    <Th align="right">المنتج</Th>
                    <Th>الكمية</Th>
                    <Th>السعر</Th>
                    <Th>الإجمالي</Th>
                </tr>
            </thead>
            <tbody>
                {(items ?? []).map((item, i) => {
                    const qty = parseFloat(item.quantity)
                    const isByWeight = parseInt(item.sell_by_weight) === 1 || (qty % 1 !== 0 && qty < 100)
                    const qtyDisplay = isByWeight ? `${qty.toFixed(3)}` : formatNumber(item.quantity)
                    return (
                        <tr key={item.id ?? i}>
                            <Td>{formatNumber(i + 1)}</Td>
                            <Td align="right" isName>{item.product_name ?? item.name}{isByWeight ? ' ⚖️' : ''}</Td>
                            <Td>{qtyDisplay}{isByWeight ? ' كجم' : ''}</Td>
                            <Td>{formatNumber(parseFloat(item.price).toFixed(2))}</Td>
                            <Td>{formatNumber((parseFloat(item.price) * qty).toFixed(2))}</Td>
                        </tr>
                    )
                })}
            </tbody>
        </table>
    )
}
