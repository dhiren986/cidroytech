<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\ResourceModel;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Refund extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('mp_refund', 'entity_id');
    }

    /**
     * Optimistic compare-and-set. Bumps version by one only when the stored version still
     * matches the expected value. Returns true when exactly one row was updated.
     *
     * @param array<string, mixed> $data
     */
    public function updateWithVersion(int $id, int $expectedVersion, array $data): bool
    {
        $connection = $this->getConnection();

        $set = [];
        $bind = [];
        foreach ($data as $column => $value) {
            $set[] = $connection->quoteIdentifier($column) . ' = ?';
            $bind[] = $value;
        }
        $set[] = $connection->quoteIdentifier('version') . ' = ' . $connection->quoteIdentifier('version') . ' + 1';

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = ? AND %s = ?',
            $connection->quoteIdentifier($this->getMainTable()),
            implode(', ', $set),
            $connection->quoteIdentifier('entity_id'),
            $connection->quoteIdentifier('version')
        );
        $bind[] = $id;
        $bind[] = $expectedVersion;

        return $connection->query($sql, $bind)->rowCount() === 1;
    }

    /**
     * Fresh read used by the create-idempotency guard: has this refund_no already been
     * created upstream? Reads the current row directly rather than any in-memory copy.
     */
    public function isCreateSucceeded(string $refundNo): bool
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['entity_id'])
            ->where('refund_no = ?', $refundNo)
            ->where('create_status = ? OR erp_refund_id IS NOT NULL', RefundInterface::SUB_SUCCEEDED)
            ->limit(1);

        return (bool) $connection->fetchOne($select);
    }

    /**
     * Quantity already refunded per order item on this order, excluding cancelled and failed
     * refunds. Used by the receipt to show how much of each line had been refunded before.
     *
     * @return array<int, string> order_item_id => summed qty_refund
     */
    public function loadRefundedQtyByOrder(int $orderId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(
                ['ri' => $this->getTable('mp_refund_item')],
                ['ri.order_item_id', 'qty' => new \Zend_Db_Expr('SUM(ri.qty_refund)')]
            )
            ->join(
                ['r' => $this->getMainTable()],
                'r.entity_id = ri.refund_id',
                []
            )
            ->where('r.order_id = ?', $orderId)
            ->where('r.status NOT IN (?)', [RefundInterface::STATUS_CANCELLED, RefundInterface::STATUS_FAILED])
            ->group('ri.order_item_id');

        return $connection->fetchPairs($select);
    }

    protected function _beforeSave(AbstractModel $object): AbstractDb
    {
        if (!$object->isObjectNew()
            && ($object->dataHasChangedFor(RefundInterface::STATUS)
                || $object->dataHasChangedFor(RefundInterface::VERSION))
        ) {
            throw new \RuntimeException(
                'Refund status and version are updated only through the optimistic-locking path.'
            );
        }

        return parent::_beforeSave($object);
    }
}
