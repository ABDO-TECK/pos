<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LedgerPdfService;
use PHPUnit\Framework\TestCase;

final class LedgerPdfServiceTest extends TestCase
{
    public function testDatesUseA12HourClockForBilingualStatements(): void
    {
        $service = new LedgerPdfService();

        $pmDate = $service->fmtDate('2026-08-02 13:05:00');
        self::assertStringContainsString("\u{202B}أغسطس\u{202C}", $pmDate);
        self::assertSame('02/أغسطس/2026 - 01:05 م', $this->stripBidiControls($pmDate));
        self::assertSame('02/أغسطس/2026 - 01:05 ص', $this->stripBidiControls($service->fmtDate('2026-08-02 01:05:00')));
        self::assertSame('—', $service->fmtDate(null));
    }

    public function testStatementHtmlUsesSafeNumberAndDateDirection(): void
    {
        $service = new LedgerPdfService();

        $html = $service->buildLedgerHtml([
            'title' => 'كشف حساب عميل',
            'entityLabel' => 'اسم العميل',
            'entity' => ['name' => 'A & B', 'phone' => '01001234567'],
            'entries' => [[
                'date' => '2026-08-02 13:05:00',
                'description' => 'Sale',
                'type' => 'sale',
                'debit' => 100.0,
                'credit' => 0.0,
                'balance' => 100.0,
            ]],
            'balance' => 1250.5,
            'totalDebit' => 1500.5,
            'totalCredit' => 250.0,
            'storeName' => 'متجر الاختبار',
            'balDebitWord' => 'مدين',
            'balCreditWord' => 'دائن',
        ]);

        self::assertStringNotContainsString('CUSTOMER STATEMENT', $html);
        self::assertStringContainsString('class="summary-val"', $html);
        self::assertStringContainsString('class="date-value" dir="ltr"', $html);
        self::assertStringContainsString('unicode-bidi: bidi-override', $service->getCss());
        self::assertStringContainsString('A &amp; B', $html);
    }

    public function testMpdfUsesPhysicalArabicFontFiles(): void
    {
        $service = new LedgerPdfService();
        $mpdf = $service->createMpdf();

        $fontDirProperty = new \ReflectionProperty($mpdf, 'fontDir');
        $fontDirProperty->setAccessible(true);
        $fontDirs = $fontDirProperty->getValue($mpdf);
        $arabicFontDir = array_values(array_filter(
            $fontDirs,
            static fn (string $directory): bool => is_file($directory . DIRECTORY_SEPARATOR . 'XB Riyaz.ttf'),
        ))[0] ?? null;

        self::assertNotNull($arabicFontDir);
        self::assertStringNotContainsString('phar://', $arabicFontDir);
        self::assertFileExists($arabicFontDir . DIRECTORY_SEPARATOR . 'XB RiyazBd.ttf');
        self::assertFileExists($arabicFontDir . DIRECTORY_SEPARATOR . 'XB RiyazIt.ttf');
        self::assertFileExists($arabicFontDir . DIRECTORY_SEPARATOR . 'XB RiyazBdIt.ttf');
    }

    public function testCurrencyMarkupSeparatesLtrAmountFromRtlArabicCode(): void
    {
        $service = new LedgerPdfService();
        $currency = $service->fmtCurrency(1250.5);

        self::assertStringContainsString('class="currency-amount" dir="ltr">1,250.50</span>', $currency);
        self::assertStringContainsString('class="currency-code" dir="rtl">ج.م.</span>', $currency);
        self::assertStringContainsString('font-family: xbriyaz, Tahoma, Arial, sans-serif', $service->getCss());
    }

    public function testEmptyBalanceDescriptorDoesNotRenderEmptyParentheses(): void
    {
        $service = new LedgerPdfService();
        $html = $service->buildLedgerHtml([
            'title' => 'كشف حساب',
            'entityLabel' => 'الاسم',
            'entity' => ['name' => 'عميل'],
            'entries' => [],
            'balance' => 0.0,
            'totalDebit' => 0.0,
            'totalCredit' => 0.0,
            'storeName' => 'المتجر',
            'balDebitWord' => '',
            'balCreditWord' => '',
        ]);

        self::assertStringNotContainsString('class="balance-word"', $html);
        self::assertStringNotContainsString('()</span>', $html);
    }

    private function stripBidiControls(string $value): string
    {
        return preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value) ?? $value;
    }
}
