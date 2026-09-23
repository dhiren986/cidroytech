<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Pdf;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\Data\RefundItemInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Pdf\RefundReceipt;
use Acme\SellerRefund\Model\ResourceModel\Refund;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

/**
 * Covers the reissued-receipt layout on a pure-seller order: the header restates the original
 * order figures and a single tax-rate group is produced.
 */
class RefundReceiptTest extends TestCase
{
    public function testBuildDataRestatesOrderHeaderAndGroupsTax(): void
    {
        $refundRepository = $this->createMock(RefundRepositoryInterface::class);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $refundResource = $this->createMock(Refund::class);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-09-01 10:00:00'));

        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getEntityId')->willReturn(42);
        $refund->method('getOrderId')->willReturn(7);
        $refund->method('getRefundNo')->willReturn('SR-20260901-000042');
        $refund->method('getRefundType')->willReturn(RefundInterface::TYPE_FULL);
        $refund->method('getCreatedAt')->willReturn('2026-09-01 10:00:00');

        $orderItem = $this->createMock(OrderItemInterface::class);
        $orderItem->method('getRowTotal')->willReturn(2400.0);
        $orderItem->method('getTaxAmount')->willReturn(240.0);
        $orderItem->method('getQtyOrdered')->willReturn(2.0);

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getShippingAmount')->willReturn(0.0);
        $order->method('getOrderCurrencyCode')->willReturn('JPY');
        $order->method('getAllVisibleItems')->willReturn([$orderItem]);
        $orderRepository->method('get')->with(7)->willReturn($order);

        $item = $this->createMock(RefundItemInterface::class);
        $item->method('getOrderItemId')->willReturn(101);
        $item->method('getSku')->willReturn('SELLER-RED-01');
        $item->method('getProductName')->willReturn('Red Seller Item');
        $item->method('getQtyRefund')->willReturn('2.0000');
        $item->method('getRowAmount')->willReturn('2400.0000');
        $item->method('getShippingAmount')->willReturn('0.0000');
        $item->method('getTaxRate')->willReturn('0.1000');
        $item->method('getTaxCode')->willReturn('010');
        $item->method('getTaxAmount')->willReturn('240.0000');
        $item->method('getGrandTotal')->willReturn('2640.0000');
        $refundRepository->method('getItems')->with(42)->willReturn([$item]);
        $refundResource->method('loadRefundedQtyByOrder')->with(7)->willReturn([]);

        $data = (new RefundReceipt($refundRepository, $orderRepository, $refundResource, $timezone))
            ->buildData($refund);

        $expected = ['subtotal' => '2400.0000', 'shipping' => '0.0000', 'tax' => '240.0000', 'grand_total' => '2640.0000'];
        self::assertFalse($data['is_partial']);
        self::assertSame('JPY', $data['currency']);
        self::assertSame($expected, $data['pre_refund']);
        self::assertSame($expected, $data['refund']);
        self::assertSame([['rate' => '0.1000', 'taxable' => '2400.0000', 'tax' => '240.0000']], $data['tax_groups']);
        self::assertCount(1, $data['lines']);
        self::assertSame('SELLER-RED-01', $data['lines'][0]['sku']);
    }
}
