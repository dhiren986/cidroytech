<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Integration;

use Acme\SellerRefund\Exception\ValidationException;
use Acme\SellerRefund\Service\RefundProcessor;
use Acme\SellerRefund\Test\Integration\Framework\DbTransactionTestCase;
use Acme\SellerRefund\Test\Integration\Framework\SeedOrders;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * BR-03 end-to-end: a line's refundable quantity is qty_ordered minus qty already refunded
 * by prior refunds, not qty_ordered alone. SR-ORD-1003 seeds SELLER-YEL-04 at qty_ordered=3
 * with 2 already refunded by a prior, confirmed refund - remaining is 1. A request for 2
 * (within the ordered quantity of 3, but past the remaining quantity of 1) must be rejected;
 * a request for exactly the remaining quantity must succeed.
 *
 * This is the scenario a check of "requested <= qty_ordered" alone (dropping the
 * prior-refunded term) would wrongly accept - exactly the regression found in
 * review/pr-02-erp-refund-sync's RefundValidator change (see submission/01-code-review.md).
 */
class RefundQuantityGuardTest extends DbTransactionTestCase
{
    private const ORDER_INCREMENT_ID = 'SR-ORD-1003';
    private const SKU = 'SELLER-YEL-04';

    public function testRequestExceedingRemainingButNotOrderedQuantityIsRejected(): void
    {
        $seed = $this->get(SeedOrders::class);
        $order = $seed->loadByIncrementId(self::ORDER_INCREMENT_ID);
        $orderItemId = $this->orderItemIdForSku($seed, $order, self::SKU);

        // 2 is within qty_ordered (3) but exceeds the remaining refundable quantity (1).
        $submission = $seed->partialSubmission($orderItemId, '2');

        $this->expectException(ValidationException::class);
        $this->get(RefundProcessor::class)->submit((int) $order->getEntityId(), $submission);
    }

    public function testRequestAtExactlyTheRemainingQuantityIsAccepted(): void
    {
        $seed = $this->get(SeedOrders::class);
        $order = $seed->loadByIncrementId(self::ORDER_INCREMENT_ID);
        $orderItemId = $this->orderItemIdForSku($seed, $order, self::SKU);

        $submission = $seed->partialSubmission($orderItemId, '1');

        $refund = $this->get(RefundProcessor::class)->submit((int) $order->getEntityId(), $submission);

        self::assertGreaterThan(0, (int) $refund->getEntityId());
    }

    private function orderItemIdForSku(SeedOrders $seed, OrderInterface $order, string $sku): int
    {
        foreach ($seed->sellerLines($order) as $orderItemId => $item) {
            if ($item->getSku() === $sku) {
                return (int) $orderItemId;
            }
        }

        throw new \RuntimeException(sprintf('Seller line "%s" was not found on order "%s".', $sku, self::ORDER_INCREMENT_ID));
    }
}
