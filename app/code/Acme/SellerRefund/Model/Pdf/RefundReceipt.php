<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Pdf;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Reissued receipt / qualified tax invoice for a refund. Every figure is taken from the
 * stored refund snapshot through the calculator, so the receipt agrees with the other
 * presentation surfaces and the downstream export. Labels are ASCII only.
 */
class RefundReceipt
{
    private const MARGIN_LEFT = 50.0;
    private const MARGIN_RIGHT = 545.0;
    private const COLOR_ACCENT = [0.16, 0.32, 0.58];
    private const COLOR_MUTED = [0.4, 0.4, 0.4];
    private const COLOR_RULE = [0.6, 0.6, 0.6];
    private const COLOR_REFUND_FILL = [0.93, 0.96, 1.0];

    public function __construct(
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RefundTotalCalculator $calculator,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * View data for the receipt. This is the harness inspection point.
     *
     * @return array<string, mixed>
     */
    public function buildData(RefundInterface $refund): array
    {
        $items = $this->refundRepository->getItems((int) $refund->getEntityId());
        $order = $this->orderRepository->get($refund->getOrderId());
        $figures = $this->calculator->fromSnapshot($refund, $items, $order);

        return [
            'refund_no' => $refund->getRefundNo(),
            'currency' => $figures->currency,
            'is_partial' => $figures->isPartial(),
            'refund_date' => $this->refundDate($refund),
            'pre_refund' => [
                'subtotal' => $figures->preRefundSubtotal,
                'shipping' => $figures->preRefundShipping,
                'tax' => $figures->preRefundTax,
                'grand_total' => $figures->preRefundGrandTotal,
            ],
            'refund' => [
                'subtotal' => $figures->refundSubtotal,
                'shipping' => $figures->refundShipping,
                'tax' => $figures->refundTax,
                'grand_total' => $figures->refundGrandTotal,
            ],
            'lines' => array_map(
                static fn (\Acme\SellerRefund\Model\Total\RefundFigureLine $line): array => $line->toArray(),
                $figures->lines
            ),
        ];
    }

    /**
     * Render the receipt as PDF bytes.
     */
    public function render(RefundInterface $refund): string
    {
        $data = $this->buildData($refund);
        $currency = (string) $data['currency'];

        $pdf = new \Zend_Pdf();
        $page = new \Zend_Pdf_Page(\Zend_Pdf_Page::SIZE_A4);
        $pdf->pages[] = $page;
        $font = \Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA);
        $bold = \Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA_BOLD);

        $y = $this->drawHeader($page, $bold, $font);
        $y = $this->drawMetaBar($page, $bold, $font, $data, $y - 14);
        $y = $this->drawLineItems($page, $bold, $font, $data, $currency, $y - 26);
        $this->drawTotalsCards($page, $bold, $font, $data, $currency, $y - 22);

        return $pdf->render();
    }

    private function drawHeader(\Zend_Pdf_Page $page, \Zend_Pdf_Resource_Font $bold, \Zend_Pdf_Resource_Font $font): float
    {
        $page->setFillColor($this->rgb(self::COLOR_ACCENT));
        $page->setFont($bold, 20);
        $page->drawText('Refund Receipt', self::MARGIN_LEFT, 780, 'UTF-8');

        $page->setFillColor($this->rgb(self::COLOR_MUTED));
        $page->setFont($font, 10);
        $page->drawText('Qualified Tax Invoice', self::MARGIN_LEFT, 764, 'UTF-8');

        $page->setLineColor($this->rgb(self::COLOR_ACCENT));
        $page->setLineWidth(1.5);
        $page->drawLine(self::MARGIN_LEFT, 754, self::MARGIN_RIGHT, 754);

        $this->resetInk($page);

        return 754;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function drawMetaBar(\Zend_Pdf_Page $page, \Zend_Pdf_Resource_Font $bold, \Zend_Pdf_Resource_Font $font, array $data, float $y): float
    {
        $barTop = $y;
        $barBottom = $y - 24;
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0.96));
        $page->drawRectangle(self::MARGIN_LEFT, $barBottom, self::MARGIN_RIGHT, $barTop, \Zend_Pdf_Page::SHAPE_DRAW_FILL);

        $textY = $barTop - 16;
        $this->labelledValue($page, $bold, $font, 'Refund No', $this->ascii((string) $data['refund_no']), self::MARGIN_LEFT + 10, $textY);
        $this->labelledValue($page, $bold, $font, 'Date', $this->ascii((string) $data['refund_date']), self::MARGIN_LEFT + 210, $textY);
        $this->labelledValue($page, $bold, $font, 'Type', $data['is_partial'] ? 'Partial Refund' : 'Full Refund', self::MARGIN_LEFT + 360, $textY);

        $this->resetInk($page);

        return $barBottom;
    }

    private function labelledValue(\Zend_Pdf_Page $page, \Zend_Pdf_Resource_Font $bold, \Zend_Pdf_Resource_Font $font, string $label, string $value, float $x, float $y): void
    {
        $page->setFillColor($this->rgb(self::COLOR_MUTED));
        $page->setFont($bold, 8);
        $page->drawText(strtoupper($label), $x, $y, 'UTF-8');

        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $page->setFont($font, 10);
        $page->drawText($value, $x, $y - 12, 'UTF-8');
    }

    /**
     * A column table of the refunded lines: SKU, quantity, tax rate, ex-tax amount, tax
     * amount, and line total.
     *
     * @param array<string, mixed> $data
     */
    private function drawLineItems(\Zend_Pdf_Page $page, \Zend_Pdf_Resource_Font $bold, \Zend_Pdf_Resource_Font $font, array $data, string $currency, float $y): float
    {
        $columns = [
            'sku' => ['label' => 'SKU', 'x' => self::MARGIN_LEFT],
            'qty' => ['label' => 'Qty', 'x' => self::MARGIN_LEFT + 200],
            'rate' => ['label' => 'Rate', 'x' => self::MARGIN_LEFT + 255],
            'ex_tax' => ['label' => 'Ex-Tax', 'x' => self::MARGIN_LEFT + 310],
            'tax' => ['label' => 'Tax', 'x' => self::MARGIN_LEFT + 395],
            'total' => ['label' => 'Total', 'x' => self::MARGIN_LEFT + 465],
        ];

        $page->setFillColor($this->rgb(self::COLOR_ACCENT));
        $page->setFont($bold, 12);
        $page->drawText('Refunded Lines', self::MARGIN_LEFT, $y, 'UTF-8');
        $y -= 20;

        $page->setFillColor($this->rgb(self::COLOR_MUTED));
        $page->setFont($bold, 8);
        foreach ($columns as $column) {
            $page->drawText(strtoupper($column['label']), $column['x'], $y, 'UTF-8');
        }

        $y -= 4;
        $page->setLineColor($this->rgb(self::COLOR_RULE));
        $page->setLineWidth(0.75);
        $page->drawLine(self::MARGIN_LEFT, $y, self::MARGIN_RIGHT, $y);
        $y -= 14;

        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $page->setFont($font, 9);
        foreach ((array) $data['lines'] as $lineData) {
            $page->drawText($this->ascii((string) $lineData['sku']), $columns['sku']['x'], $y, 'UTF-8');
            $page->drawText($this->ascii((string) $lineData['qty_refund']), $columns['qty']['x'], $y, 'UTF-8');
            $page->drawText($this->taxRatePercent((string) $lineData['tax_rate']), $columns['rate']['x'], $y, 'UTF-8');
            $page->drawText($this->money($lineData['row_amount'], $currency), $columns['ex_tax']['x'], $y, 'UTF-8');
            $page->drawText($this->money($lineData['tax_amount'], $currency), $columns['tax']['x'], $y, 'UTF-8');
            $page->drawText($this->money($lineData['grand_total'], $currency), $columns['total']['x'], $y, 'UTF-8');
            $y -= 16;
        }

        $y += 2;
        $page->setLineColor($this->rgb(self::COLOR_RULE));
        $page->drawLine(self::MARGIN_LEFT, $y, self::MARGIN_RIGHT, $y);

        return $y;
    }

    /**
     * Two side-by-side cards: the original pre-refund total, and the refund itself. The
     * refund card is visually distinct (filled background) per FRD 10.4's requirement that
     * refunded amounts appear as a clearly separated section beneath the original figures.
     *
     * @param array<string, mixed> $data
     */
    private function drawTotalsCards(\Zend_Pdf_Page $page, \Zend_Pdf_Resource_Font $bold, \Zend_Pdf_Resource_Font $font, array $data, string $currency, float $y): void
    {
        $cardWidth = (self::MARGIN_RIGHT - self::MARGIN_LEFT - 16) / 2;
        $leftX = self::MARGIN_LEFT;
        $rightX = self::MARGIN_LEFT + $cardWidth + 16;
        $cardTop = $y;
        $cardBottom = $y - 96;

        $this->totalsCard($page, $bold, $font, 'Pre-Refund Total', $data['pre_refund'], $currency, $leftX, $cardTop, $cardWidth, $cardBottom, false);
        $this->totalsCard($page, $bold, $font, 'Refund', $data['refund'], $currency, $rightX, $cardTop, $cardWidth, $cardBottom, true);
    }

    /**
     * @param array<string, string> $totals
     */
    private function totalsCard(
        \Zend_Pdf_Page $page,
        \Zend_Pdf_Resource_Font $bold,
        \Zend_Pdf_Resource_Font $font,
        string $title,
        array $totals,
        string $currency,
        float $x,
        float $top,
        float $width,
        float $bottom,
        bool $emphasise
    ): void {
        if ($emphasise) {
            $page->setFillColor($this->rgb(self::COLOR_REFUND_FILL));
            $page->drawRectangle($x, $bottom, $x + $width, $top, \Zend_Pdf_Page::SHAPE_DRAW_FILL);
        }

        $page->setLineColor($this->rgb($emphasise ? self::COLOR_ACCENT : self::COLOR_RULE));
        $page->setLineWidth($emphasise ? 1.25 : 0.75);
        $page->drawRectangle($x, $bottom, $x + $width, $top, \Zend_Pdf_Page::SHAPE_DRAW_STROKE);

        $textX = $x + 10;
        $lineY = $top - 16;

        $page->setFillColor($this->rgb(self::COLOR_ACCENT));
        $page->setFont($bold, 11);
        $page->drawText($title, $textX, $lineY, 'UTF-8');
        $lineY -= 18;

        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0.15));
        $page->setFont($font, 9);
        $lineY = $this->cardLine($page, 'Product Subtotal', $this->money($totals['subtotal'], $currency), $textX, $lineY);
        $lineY = $this->cardLine($page, 'Shipping', $this->money($totals['shipping'], $currency), $textX, $lineY);
        $lineY = $this->cardLine($page, 'Consumption Tax', $this->money($totals['tax'], $currency), $textX, $lineY);

        $page->setFont($bold, 10);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->cardLine($page, 'Total', $this->money($totals['grand_total'], $currency), $textX, $lineY);

        $this->resetInk($page);
    }

    private function cardLine(\Zend_Pdf_Page $page, string $label, string $value, float $x, float $y): float
    {
        $page->drawText($label . ':', $x, $y, 'UTF-8');
        $page->drawText($value, $x + 110, $y, 'UTF-8');

        return $y - 14;
    }

    private function rgb(array $components): \Zend_Pdf_Color_Rgb
    {
        return new \Zend_Pdf_Color_Rgb($components[0], $components[1], $components[2]);
    }

    private function resetInk(\Zend_Pdf_Page $page): void
    {
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $page->setLineColor(new \Zend_Pdf_Color_GrayScale(0));
        $page->setLineWidth(1);
    }

    private function taxRatePercent(string $rate): string
    {
        return sprintf('%.2F%%', ((float) $rate) * 100);
    }

    private function refundDate(RefundInterface $refund): string
    {
        $createdAt = $refund->getCreatedAt();
        if ($createdAt === null || $createdAt === '') {
            return $this->timezone->date()->format('Y-m-d');
        }

        return $this->timezone->date(new \DateTime($createdAt))->format('Y-m-d');
    }

    private function money(mixed $amount, string $currency): string
    {
        return $currency . ' ' . number_format((float) $amount, 0, '.', ',');
    }

    private function ascii(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($converted === false) {
            $converted = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);
        }

        return (string) preg_replace('/[^\x20-\x7E]/', '?', $converted);
    }
}
