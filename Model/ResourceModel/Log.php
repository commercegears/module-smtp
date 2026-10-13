<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\ResourceModel;

use CommerceGears\Smtp\Model\Log as LogModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Model\ResourceModel\Db\Context;

/**
 * Log table, plus the separate content table that holds the compressed raw message (kept out of the grid queries).
 */
class Log extends AbstractDb
{
    public const TABLE = 'commercegears_smtp_log';
    public const CONTENT_TABLE = 'commercegears_smtp_log_content';

    public function __construct(
        Context $context,
        private readonly Json $json,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'log_id');
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function saveContent(int $logId, array $envelope, string $raw): void
    {
        $compressed = gzcompress($raw, 6);
        $this->getConnection()->insertOnDuplicate(
            $this->getTable(self::CONTENT_TABLE),
            [
                'log_id' => $logId,
                'envelope' => $this->json->serialize($envelope),
                'raw_message' => $compressed === false ? $raw : $compressed,
            ],
            ['envelope', 'raw_message']
        );
    }

    /**
     * @return array{envelope: array<string, mixed>, raw: string}|null
     */
    public function loadContent(int $logId): ?array
    {
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->getTable(self::CONTENT_TABLE))->where('log_id = ?', $logId)
        );
        if (!is_array($row) || $row === []) {
            return null;
        }
        $raw = (string)$row['raw_message'];
        $inflated = @gzuncompress($raw);
        try {
            $envelope = $this->json->unserialize((string)$row['envelope']);
        } catch (\InvalidArgumentException) {
            $envelope = [];
        }
        return [
            'envelope' => is_array($envelope) ? $envelope : [],
            'raw' => $inflated === false ? $raw : $inflated,
        ];
    }

    public function hasContent(int $logId): bool
    {
        $connection = $this->getConnection();
        return (bool)$connection->fetchOne(
            $connection->select()->from($this->getTable(self::CONTENT_TABLE), ['log_id'])->where('log_id = ?', $logId)
        );
    }

    public function deleteContent(int $logId): void
    {
        $this->getConnection()->delete($this->getTable(self::CONTENT_TABLE), ['log_id = ?' => $logId]);
    }

    /**
     * Marks a queued row as being sent; false when another process took it first.
     */
    public function claim(int $logId): bool
    {
        return $this->getConnection()->update(
            $this->getMainTable(),
            ['status' => LogModel::STATUS_SENDING],
            [
                'log_id = ?' => $logId,
                'status IN (?)' => [LogModel::STATUS_PENDING, LogModel::STATUS_RETRY],
            ]
        ) === 1;
    }

    /**
     * IDs of queued emails that are due.
     *
     * @return int[]
     */
    public function getDueIds(string $now, int $limit): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['log_id'])
            ->where('status IN (?)', [LogModel::STATUS_PENDING, LogModel::STATUS_RETRY])
            ->where('next_attempt_at IS NULL OR next_attempt_at <= ?', $now)
            ->order('log_id ASC')
            ->limit($limit);
        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Puts rows back in the queue that stayed in "sending" (the process died while sending).
     */
    public function releaseStale(string $before): int
    {
        return (int)$this->getConnection()->update(
            $this->getMainTable(),
            ['status' => LogModel::STATUS_RETRY],
            ['status = ?' => LogModel::STATUS_SENDING, 'updated_at < ?' => $before]
        );
    }

    /**
     * Deletes log rows created before the date, in batches. Queued emails are kept.
     */
    public function deleteOlderThan(string $before, int $batch = 1000): int
    {
        $connection = $this->getConnection();
        $deleted = 0;
        do {
            $ids = $connection->fetchCol(
                $connection->select()
                    ->from($this->getMainTable(), ['log_id'])
                    ->where('created_at < ?', $before)
                    ->where('status NOT IN (?)', [LogModel::STATUS_PENDING, LogModel::STATUS_RETRY, LogModel::STATUS_SENDING])
                    ->limit($batch)
            );
            if ($ids === []) {
                break;
            }
            $deleted += (int)$connection->delete($this->getMainTable(), ['log_id IN (?)' => array_map('intval', $ids)]);
        } while (count($ids) === $batch);
        return $deleted;
    }

    /**
     * @param int[] $ids
     */
    public function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }
        return (int)$this->getConnection()->delete($this->getMainTable(), ['log_id IN (?)' => $ids]);
    }

    /**
     * Counts per status since a date (for the admin notice and the diagnostics panel).
     *
     * @return array<string, int>
     */
    public function countByStatusSince(string $since): array
    {
        $connection = $this->getConnection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->getMainTable(), ['status', 'COUNT(*)'])
                ->where('created_at >= ?', $since)
                ->group('status')
        );
        return array_map('intval', $rows);
    }
}
