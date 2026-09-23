<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Block\Adminhtml\Refund;

use Acme\SellerRefund\Api\Data\RefundEventInterface;
use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\ResourceModel\RefundEvent\CollectionFactory as EventCollectionFactory;
use Acme\SellerRefund\Model\Total\RefundFigures;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\AuthorizationInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Refund detail backing block: the stored refund, its figures rebuilt from the snapshot,
 * and the append-only audit trail.
 */
class View extends Template
{
    private ?RefundInterface $refund = null;

    public function __construct(
        Context $context,
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RefundTotalCalculator $calculator,
        private readonly EventCollectionFactory $eventCollectionFactory,
        private readonly AuthorizationInterface $authorization,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getRefund(): RefundInterface
    {
        if ($this->refund === null) {
            $this->refund = $this->refundRepository->getById($this->getRefundId());
        }

        return $this->refund;
    }

    public function getRefundId(): int
    {
        return (int) $this->getRequest()->getParam('refund_id');
    }

    public function getFigures(): RefundFigures
    {
        $refund = $this->getRefund();
        $items = $this->refundRepository->getItems((int) $refund->getEntityId());
        $order = $this->orderRepository->get($refund->getOrderId());

        return $this->calculator->fromSnapshot($refund, $items, $order);
    }

    /**
     * @return RefundEventInterface[]
     */
    public function getEvents(): array
    {
        return $this->eventCollectionFactory->create()
            ->addRefundFilter($this->getRefundId())
            ->getItems();
    }

    public function canSeeRawPayload(): bool
    {
        return $this->authorization->isAllowed('Acme_SellerRefund::audit');
    }

    public function getActionUrl(string $action): string
    {
        return $this->getUrl('acme_refund/refund/' . $action, ['refund_id' => $this->getRefundId()]);
    }

    public function formatMoney(?string $amount, ?string $currency = null): string
    {
        $currency = $currency ?? $this->getRefund()->getCurrencyCode();

        return $currency . ' ' . number_format((float) $amount, 0, '.', ',');
    }

    /**
     * CSS modifier for a status/sub-status pill: is-good, is-bad, or is-pending.
     */
    public function statusPillClass(?string $value): string
    {
        return match ($value) {
            RefundInterface::STATUS_ERP_CONFIRMED,
            RefundInterface::SUB_SUCCEEDED => 'is-good',
            RefundInterface::STATUS_FAILED,
            RefundInterface::STATUS_CANCELLED,
            RefundInterface::SUB_BUSINESS_REJECTED,
            RefundInterface::SUB_RETRYABLE_ERROR => 'is-bad',
            default => 'is-pending',
        };
    }
}
