<?php

/**
 * LedgerPdfController
 * Generates server-side PDF for Customer/Supplier account statements using mPDF.
 * mPDF handles Arabic RTL, ligatures, and mixed text perfectly.
 */

require_once __DIR__ . '/../vendor/autoload.php';

class LedgerPdfController extends Controller {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /* ── helpers ─────────────────────────────────────────────── */

    private function getStoreName(): string {
        $stmt = $this->db->prepare("SELECT `value` FROM settings WHERE `key` = 'store_name' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? $row['value'] : 'سوبر ماركت';
    }

    private function fmtCurrency(float $n): string {
        return number_format($n, 2) . ' ج.م.';
    }

    private function fmtDate(?string $d): string {
        if (!$d) return '—';
        $ts = strtotime($d);
        if ($ts === false) return '—';
        return date('d M Y, H:i', $ts);
    }

    /* ── shared mPDF factory ─────────────────────────────────── */

    private function createMpdf(): \Mpdf\Mpdf {
        $tmpDir = __DIR__ . '/../storage/mpdf_tmp';
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

        return new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'default_font'  => 'xbriyaz',   // Arabic font built into mPDF
            'directionality'=> 'rtl',
            'tempDir'       => $tmpDir,
            'margin_top'    => 15,
            'margin_bottom' => 15,
            'margin_left'   => 12,
            'margin_right'  => 12,
        ]);
    }

    /* ── shared CSS ──────────────────────────────────────────── */

    private function getCss(): string {
        return '
        body {
            font-family: xbriyaz, sans-serif;
            font-size: 13px;
            color: #222222;
            direction: rtl;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        .header-table {
            border-bottom: 2px solid #333333;
            margin-bottom: 20px;
            padding-bottom: 10px;
        }
        .header-title {
            font-size: 24px;
            font-weight: bold;
            color: #111111;
            margin: 0;
            padding: 0;
        }
        .header-subtitle {
            font-size: 14px;
            color: #555555;
            margin-top: 5px;
        }
        .header-meta {
            font-size: 12px;
            color: #444444;
            line-height: 1.6;
            text-align: left;
        }
        .summary-table {
            margin-bottom: 25px;
        }
        .summary-table td {
            width: 25%;
            padding: 10px;
            border: 1px solid #dddddd;
            text-align: center;
            background-color: #f9f9f9;
        }
        .summary-label {
            font-size: 11px;
            color: #666666;
            text-transform: uppercase;
        }
        .summary-val {
            font-size: 16px;
            font-weight: bold;
            color: #222222;
            margin-top: 4px;
        }
        .ledger-table {
            margin-bottom: 15px;
        }
        .ledger-table thead th {
            background-color: #f2f2f2;
            color: #333333;
            font-weight: bold;
            padding: 10px;
            border: 1px solid #cccccc;
            text-align: right;
            font-size: 12px;
        }
        .ledger-table thead th.center { text-align: center; }
        .ledger-table tbody td {
            padding: 8px 10px;
            border: 1px solid #eeeeee;
            font-size: 12px;
            color: #333333;
        }
        .ledger-table tfoot td {
            background-color: #f2f2f2;
            color: #222222;
            font-weight: bold;
            padding: 10px;
            border: 1px solid #cccccc;
            font-size: 12px;
        }
        .col-num { width: 5%; text-align: center; }
        .col-date { width: 15%; text-align: right; }
        .col-desc { width: 35%; text-align: right; }
        .col-debit { width: 15%; text-align: right; color: #333333; }
        .col-credit { width: 15%; text-align: right; color: #333333; }
        .col-bal { width: 15%; text-align: right; font-weight: bold; }
        
        .footer-table {
            border-top: 1px solid #dddddd;
            padding-top: 10px;
            font-size: 10px;
            color: #777777;
            margin-top: 20px;
        }
        ';
    }

    /* ══════════════════════════════════════════════════════════
     * Customer PDF
     * GET /api/customers/{id}/pdf
     *═══════════════════════════════════════════════════════════ */

    public function customerPdf(string $id) {
        $cModel = new Customer();
        $data   = $cModel->getLedger((int)$id);

        if (!$data['customer']) {
            http_response_code(404);
            echo 'العميل غير موجود';
            exit;
        }

        $customer    = $data['customer'];
        $entries     = $data['entries'];
        $balance     = (float)$data['balance'];
        $storeName   = $this->getStoreName();
        $totalDebit  = 0;
        $totalCredit = 0;
        foreach ($entries as $e) {
            $totalDebit  += (float)($e['debit'] ?? 0);
            $totalCredit += (float)($e['credit'] ?? 0);
        }

        $html = $this->buildLedgerHtml([
            'title'       => 'كشف حساب عميل',
            'entityLabel' => 'اسم العميل',
            'entity'      => $customer,
            'entries'      => $entries,
            'balance'      => $balance,
            'totalDebit'   => $totalDebit,
            'totalCredit'  => $totalCredit,
            'storeName'    => $storeName,
            'balDebitWord' => 'مدين',
            'balCreditWord'=> 'دائن',
        ]);

        $mpdf = $this->createMpdf();
        $mpdf->WriteHTML('<style>' . $this->getCss() . '</style>' . $html);

        $filename = 'كشف_حساب_' . ($customer['name'] ?? 'عميل') . '_' . date('Y-m-d') . '.pdf';

        // Output directly — bypass the framework's JSON response
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
        exit;
    }

    /* ══════════════════════════════════════════════════════════
     * Supplier PDF
     * GET /api/suppliers/{id}/pdf
     *═══════════════════════════════════════════════════════════ */

    public function supplierPdf(string $id) {
        $sModel = new Supplier();
        $data   = $sModel->getLedger((int)$id);

        if (!$data['supplier']) {
            http_response_code(404);
            echo 'المورد غير موجود';
            exit;
        }

        $supplier    = $data['supplier'];
        $entries     = $data['entries'];
        $balance     = (float)$data['balance'];
        $storeName   = $this->getStoreName();
        $totalDebit  = 0;
        $totalCredit = 0;
        foreach ($entries as $e) {
            $totalDebit  += (float)($e['debit'] ?? 0);
            $totalCredit += (float)($e['credit'] ?? 0);
        }

        $html = $this->buildLedgerHtml([
            'title'       => 'كشف حساب مورد',
            'entityLabel' => 'اسم المورد',
            'entity'      => $supplier,
            'entries'      => $entries,
            'balance'      => $balance,
            'totalDebit'   => $totalDebit,
            'totalCredit'  => $totalCredit,
            'storeName'    => $storeName,
            'balDebitWord' => 'مستحق',
            'balCreditWord'=> 'مُسدَّد',
        ]);

        $mpdf = $this->createMpdf();
        $mpdf->WriteHTML('<style>' . $this->getCss() . '</style>' . $html);

        $filename = 'كشف_حساب_' . ($supplier['name'] ?? 'مورد') . '_' . date('Y-m-d') . '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
        exit;
    }

    /* ══════════════════════════════════════════════════════════
     * Shared HTML builder
     *═══════════════════════════════════════════════════════════ */

    private function buildLedgerHtml(array $p): string {
        $entity      = $p['entity'];
        $entries     = $p['entries'];
        $balance     = $p['balance'];
        $totalDebit  = $p['totalDebit'];
        $totalCredit = $p['totalCredit'];
        $storeName   = $p['storeName'];
        $now         = $this->fmtDate(date('Y-m-d H:i:s'));
        $balAbs      = $this->fmtCurrency(abs($balance));
        $balWord     = $balance > 0 ? $p['balDebitWord'] : $p['balCreditWord'];

        $metaRows = '<strong>' . $p['entityLabel'] . ':</strong> ' . htmlspecialchars($entity['name'] ?? '') . '<br>';
        if (!empty($entity['phone']))   $metaRows .= '<strong>رقم الهاتف:</strong> ' . htmlspecialchars($entity['phone']) . '<br>';
        if (!empty($entity['address'])) $metaRows .= '<strong>العنوان:</strong> ' . htmlspecialchars($entity['address']) . '<br>';
        if (!empty($entity['email']))   $metaRows .= '<strong>البريد الإلكتروني:</strong> ' . htmlspecialchars($entity['email']) . '<br>';
        $metaRows .= '<strong>تاريخ الإصدار:</strong> ' . $now;

        // ── Header
        $html = '
        <table class="header-table">
          <tr>
            <td style="vertical-align: top; width: 60%;">
              <div class="header-title">' . $p['title'] . '</div>
              <div class="header-subtitle">' . htmlspecialchars($storeName) . '</div>
            </td>
            <td class="header-meta" style="vertical-align: top; width: 40%;">
              ' . $metaRows . '
            </td>
          </tr>
        </table>';

        // ── Summary Cards
        $html .= '
        <table class="summary-table">
          <tr>
            <td>
              <div class="summary-label">عدد الحركات</div>
              <div class="summary-val">' . count($entries) . '</div>
            </td>
            <td>
              <div class="summary-label">إجمالي المدين</div>
              <div class="summary-val">' . $this->fmtCurrency($totalDebit) . '</div>
            </td>
            <td>
              <div class="summary-label">إجمالي الدائن</div>
              <div class="summary-val">' . $this->fmtCurrency($totalCredit) . '</div>
            </td>
            <td>
              <div class="summary-label">الرصيد الحالي</div>
              <div class="summary-val" style="color: #000;">' . $balAbs . ' <br><span style="font-size: 11px; font-weight: normal; color: #555;">(' . $balWord . ')</span></div>
            </td>
          </tr>
        </table>';

        // ── Table
        $html .= '
        <table class="ledger-table">
          <thead>
            <tr>
              <th class="col-num center">#</th>
              <th class="col-date">التاريخ</th>
              <th class="col-desc">البيان</th>
              <th class="col-debit">مدين</th>
              <th class="col-credit">دائن</th>
              <th class="col-bal">الرصيد</th>
            </tr>
          </thead>
          <tbody>';

        foreach ($entries as $i => $row) {
            $isDebit   = ((float)($row['debit'] ?? 0)) > 0;
            $isCredit  = ((float)($row['credit'] ?? 0)) > 0;
            $rowBal    = (float)($row['balance'] ?? 0);
            $balLabel  = $rowBal > 0 ? $p['balDebitWord'] : ($rowBal < 0 ? $p['balCreditWord'] : '');
            
            $desc = htmlspecialchars($row['description'] ?? '—');
            if (($row['type'] ?? '') === 'initial') {
                $desc .= ' <span style="font-size:10px; color:#555;">(رصيد مبدئي)</span>';
            }

            $html .= '
            <tr>
              <td class="col-num">' . ($i + 1) . '</td>
              <td class="col-date">' . $this->fmtDate($row['date'] ?? null) . '</td>
              <td class="col-desc">' . $desc . '</td>
              <td class="col-debit">' . ($isDebit ? $this->fmtCurrency((float)$row['debit']) : '—') . '</td>
              <td class="col-credit">' . ($isCredit ? $this->fmtCurrency((float)$row['credit']) : '—') . '</td>
              <td class="col-bal">' . $this->fmtCurrency(abs($rowBal)) . ' <span style="font-size:10px; font-weight:normal; color:#555;">' . $balLabel . '</span></td>
            </tr>';
        }

        $html .= '
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3" style="text-align: left;">الإجمالي الكلي</td>
              <td class="col-debit">' . $this->fmtCurrency($totalDebit) . '</td>
              <td class="col-credit">' . $this->fmtCurrency($totalCredit) . '</td>
              <td class="col-bal">' . $balAbs . ' <span style="font-size:10px; font-weight:normal; color:#555;">' . $balWord . '</span></td>
            </tr>
          </tfoot>
        </table>';

        // ── Footer
        $html .= '
        <table class="footer-table">
          <tr>
            <td style="text-align: right; width: 50%;">تم إنشاء هذا التقرير رسمياً بواسطة النظام الخاص بنا — ' . htmlspecialchars($storeName) . '</td>
            <td style="text-align: left; width: 50%;">' . $now . '</td>
          </tr>
        </table>';

        return $html;
    }
}
