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
                    const unitType = item.unit_type ?? (parseInt(item.sell_by_weight) === 1 ? 'weight' : 'piece')
                    const isByWeight = unitType === 'weight'
                    const isByLiter = unitType === 'liter'
                    
                    const qtyDisplay = isByWeight 
                        ? `${qty.toFixed(3)} كجم` 
                        : isByLiter 
                        ? `${qty.toFixed(2)} لتر` 
                        : formatNumber(item.quantity)
                        
                    const nameDisplay = (item.product_name ?? item.name) + (item.size_name ? ` (${item.size_name})` : '')

                    return (
                        <tr key={item.id ?? i}>
                            <Td>{formatNumber(i + 1)}</Td>
                            <Td align="right" isName>{nameDisplay}</Td>
                            <Td>{qtyDisplay}</Td>
                            <Td>{formatNumber(parseFloat(item.price).toFixed(2))}</Td>
                            <Td>{formatNumber((parseFloat(item.price) * qty).toFixed(2))}</Td>
                        </tr>
                    )
                })}
            </tbody>
        </table>
    )
}
