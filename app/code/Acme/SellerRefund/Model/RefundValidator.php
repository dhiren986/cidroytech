<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model;

use Acme\SellerRefund\Exception\ValidationException;
use Acme\SellerRefund\Model\Submission\RefundSubmission;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Server-side re-check of a refund submission at the moment of submit (BR-03). Browser-side
 * validation is advisory; this is authoritative.
 */
class RefundValidator
{
    public const ALLOWED_REASONS = [
        'defect',
        'shortage',
        'customer_return',
        'price_adjustment',
        'other',
    ];

    public function __construct(
        private readonly SellerLineResolver $sellerLineResolver
    ) {
    }

    /**
     * @param array<int|string, string> $priorRefundedByItem order_item_id => already refunded qty
     *
     * @throws ValidationException
     */
    public function validate(OrderInterface $order, RefundSubmission $submission, array $priorRefundedByItem): void
    {
        if (!in_array($submission->reasonCode, self::ALLOWED_REASONS, true)) {
            throw new ValidationException(__('The refund reason "%1" is not recognised.', $submission->reasonCode));
        }

        if ($submission->qtyByItem === []) {
            throw new ValidationException(__('Select at least one seller line to refund.'));
        }

        $sellerLines = $this->sellerLineResolver->sellerLines($order);

        foreach ($submission->qtyByItem as $orderItemId => $qty) {
            if (!isset($sellerLines[$orderItemId])) {
                throw new ValidationException(
                    __('Order item %1 is not a refundable seller line.', $orderItemId)
                );
            }

            if (bccomp($qty, '0', 4) <= 0) {
                throw new ValidationException(
                    __('The refund quantity for order item %1 must be greater than zero.', $orderItemId)
                );
            }

            $qtyOrdered = (string) $sellerLines[$orderItemId]->getQtyOrdered();
            $prior = (string) ($priorRefundedByItem[$orderItemId] ?? '0');
            $remaining = bcsub($qtyOrdered, $prior, 4);

            if (bccomp($qty, $remaining, 4) === 1) {
                throw new ValidationException(
                    __(
                        'The refund quantity %1 for order item %2 exceeds the remaining refundable quantity %3 '
                        . '(ordered %4, already refunded %5).',
                        $qty,
                        $orderItemId,
                        $remaining,
                        $qtyOrdered,
                        $prior
                    )
                );
            }
        }
    }
}
