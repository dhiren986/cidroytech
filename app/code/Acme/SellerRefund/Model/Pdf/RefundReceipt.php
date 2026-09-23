<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Pdf;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\Data\RefundItemInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\ResourceModel\Refund;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Reissued receipt / qualified tax invoice for a refund. The header restates the original
 * order figures and the per-rate tax groups the qualified invoice requires, and the body
 * lists the refunded lines. Labels are ASCII only.
 */
class RefundReceipt
{
    private const SCALE = 4;
    private const ZERO = '0.0000';

    public function __construct(
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Refund $refundResource,
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

        $currency = $this->currency($order);
        $shipping = $this->num($order->getShippingAmount());

        // Header figures: the original order as first invoiced, summed straight off the order
        // lines with the per-rate tax groups the qualified invoice needs.
        $preSubtotal = self::ZERO;
        $preTax = self::ZERO;
        $groups = [];
        foreach ($order->getAllVisibleItems() as $orderItem) {
            $row = $this->num($orderItem->getRowTotal());
            $tax = $this->num($orderItem->getTaxAmount());
            $rate = $this->rateFor($row, $tax);
            $preSubtotal = bcadd($preSubtotal, $row, self::SCALE);
            $preTax = bcadd($preTax, $tax, self::SCALE);

            if (!isset($groups[$rate])) {
                $groups[$rate] = ['rate' => $rate, 'taxable' => self::ZERO, 'tax' => self::ZERO];
            }
            $groups[$rate]['taxable'] = bcadd($groups[$rate]['taxable'], $row, self::SCALE);
            $groups[$rate]['tax'] = bcadd($groups[$rate]['tax'], $tax, self::SCALE);
        }
        $preGrandTotal = bcadd(bcadd($preSubtotal, $shipping, self::SCALE), $preTax, self::SCALE);

        $refundedBefore = $this->refundResource->loadRefundedQtyByOrder((int) $order->getEntityId());

        return [
            'refund_no' => $refund->getRefundNo(),
            'currency' => $currency,
            'is_partial' => $refund->getRefundType() === RefundInterface::TYPE_PARTIAL,
            'refund_date' => $this->refundDate($refund),
            'pre_refund' => [
                'subtotal' => $preSubtotal,
                'shipping' => $shipping,
                'tax' => $preTax,
                'grand_total' => $preGrandTotal,
            ],
            'refund' => $this->refundSection($items),
            'tax_groups' => array_values($groups),
            'lines' => array_map(
                fn (RefundItemInterface $item): array => $this->lineData($item, $refundedBefore),
                $items
            ),
        ];
    }

    /**
     * Refund totals summed from the stored refund snapshot.
     *
     * @param RefundItemInterface[] $items
     *
     * @return array<string, string>
     */
    private function refundSection(array $items): array
    {
        $subtotal = self::ZERO;
        $shipping = self::ZERO;
        $tax = self::ZERO;
        $grandTotal = self::ZERO;
        foreach ($items as $item) {
            $subtotal = bcadd($subtotal, $this->num($item->getRowAmount()), self::SCALE);
            $shipping = bcadd($shipping, $this->num($item->getShippingAmount()), self::SCALE);
            $tax = bcadd($tax, $this->num($item->getTaxAmount()), self::SCALE);
            $grandTotal = bcadd($grandTotal, $this->num($item->getGrandTotal()), self::SCALE);
        }

        return [
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'tax' => $tax,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * @param array<int, string> $refundedBefore order_item_id => qty already refunded
     *
     * @return array<string, string|int>
     */
    private function lineData(RefundItemInterface $item, array $refundedBefore): array
    {
        return [
            'order_item_id' => $item->getOrderItemId(),
            'sku' => $item->getSku(),
            'product_name' => $item->getProductName(),
            'qty_refund' => $item->getQtyRefund(),
            'refunded_before' => $refundedBefore[$item->getOrderItemId()] ?? self::ZERO,
            'tax_rate' => $item->getTaxRate(),
            'row_amount' => $item->getRowAmount(),
            'tax_amount' => $item->getTaxAmount(),
            'grand_total' => $item->getGrandTotal(),
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

        $y = 800;
        $page->setFont($bold, 14);
        $y = $this->line($page, 'Refund Receipt / Qualified Invoice', $y, 18);

        $page->setFont($font, 10);
        $y = $this->line($page, 'Refund No: ' . $this->ascii((string) $data['refund_no']), $y);
        $y = $this->line($page, 'Refund Date: ' . $this->ascii((string) $data['refund_date']), $y);
        $y = $this->line($page, 'Refund Type: ' . ($data['is_partial'] ? 'Partial Refund' : 'Full Refund'), $y, 18);

        $page->setFont($bold, 11);
        $y = $this->line($page, 'Refunded Lines', $y, 14);
        $page->setFont($font, 9);
        foreach ((array) $data['lines'] as $lineData) {
            $text = sprintf(
                '%s  x%s  rate %s  ex-tax %s  tax %s  total %s',
                $this->ascii((string) $lineData['sku']),
                $this->ascii((string) $lineData['qty_refund']),
                $this->ascii((string) $lineData['tax_rate']),
                $this->money($lineData['row_amount'], $currency),
                $this->money($lineData['tax_amount'], $currency),
                $this->money($lineData['grand_total'], $currency)
            );
            $y = $this->line($page, $text, $y);
        }

        $y -= 8;
        $page->setFont($bold, 11);
        $y = $this->line($page, 'Pre-refund Total', $y, 14);
        $page->setFont($font, 10);
        $y = $this->totals($page, $data['pre_refund'], $currency, $y);

        $y -= 8;
        $page->setFont($bold, 11);
        $y = $this->line($page, 'Refund', $y, 14);
        $page->setFont($font, 10);
        $this->totals($page, $data['refund'], $currency, $y);

        return $pdf->render();
    }

    /**
     * @param array<string, string> $totals
     */
    private function totals(\Zend_Pdf_Page $page, array $totals, string $currency, float $y): float
    {
        $y = $this->line($page, 'Product Subtotal: ' . $this->money($totals['subtotal'], $currency), $y);
        $y = $this->line($page, 'Shipping: ' . $this->money($totals['shipping'], $currency), $y);
        $y = $this->line($page, 'Consumption Tax: ' . $this->money($totals['tax'], $currency), $y);
        $y = $this->line($page, 'Total: ' . $this->money($totals['grand_total'], $currency), $y);

        return $y;
    }

    private function line(\Zend_Pdf_Page $page, string $text, float $y, int $step = 14): float
    {
        $page->drawText($text, 50, $y, 'UTF-8');

        return $y - $step;
    }

    private function refundDate(RefundInterface $refund): string
    {
        $createdAt = $refund->getCreatedAt();
        if ($createdAt === null || $createdAt === '') {
            return $this->timezone->date()->format('Y-m-d');
        }

        return $this->timezone->date(new \DateTime($createdAt))->format('Y-m-d');
    }

    /**
     * Derive the applied rate from a line's ex-tax amount and tax so lines can be grouped.
     */
    private function rateFor(string $rowAmount, string $taxAmount): string
    {
        if (bccomp($rowAmount, self::ZERO, self::SCALE) <= 0) {
            return self::ZERO;
        }

        return bcdiv($taxAmount, $rowAmount, self::SCALE);
    }

    private function currency(\Magento\Sales\Api\Data\OrderInterface $order): string
    {
        $code = $order->getOrderCurrencyCode();

        return $code !== null && $code !== '' ? (string) $code : 'JPY';
    }

    private function money(mixed $amount, string $currency): string
    {
        return $currency . ' ' . number_format((float) $amount, 0, '.', ',');
    }

    private function num(mixed $value): string
    {
        return sprintf('%.4F', (float) $value);
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
