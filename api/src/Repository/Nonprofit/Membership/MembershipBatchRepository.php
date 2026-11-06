<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class MembershipBatchRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $supplier, int $id): ?array
    {
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_membership_batches WHERE supplier_id=? AND id=?');
        $q->execute([$supplier, $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->row($row);
    }

    public function byKey(int $supplier, string $key): ?array
    {
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_membership_batches WHERE supplier_id=? AND idempotency_key=?');
        $q->execute([$supplier, $key]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->row($row);
    }

    public function recent(int $supplier, int $before = PHP_INT_MAX): array
    {
        $q = $this->db->pdo()->prepare("SELECT id,phase,member_count,created_at,
            JSON_UNQUOTE(JSON_EXTRACT(template_json,'$.title_key')) title_key,
            JSON_UNQUOTE(JSON_EXTRACT(template_json,'$.period_from')) period_from,
            JSON_UNQUOTE(JSON_EXTRACT(template_json,'$.period_to')) period_to
            FROM nonprofit_membership_batches WHERE supplier_id=? AND id<? ORDER BY id DESC LIMIT 20");
        $q->execute([$supplier, $before]);
        return array_map(static function (array $row): array {
            foreach (['id', 'member_count'] as $field) $row[$field] = (int) $row[$field];
            return $row;
        }, $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insert(int $supplier, string $key, string $hash, array $template, string $mode, int $actor): int
    {
        $q = $this->db->pdo()->prepare('INSERT INTO nonprofit_membership_batches(supplier_id,created_by,idempotency_key,request_hash,template_json,selection_mode,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $q->execute([$supplier, $actor, $key, $hash, NonprofitTaxRules::canonicalJson($template), $mode]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function seed(int $supplier, int $batch, string $mode, array $ids, string $date, int $cents): void
    {
        if ($mode === 'selected') {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $sql = "INSERT INTO nonprofit_membership_batch_members(supplier_id,batch_id,member_id,amount_cents)
                SELECT supplier_id,?,id,? FROM nonprofit_members WHERE supplier_id=? AND id IN ({$marks})";
            $params = [$batch, $cents, $supplier, ...$ids];
        } else {
            $sql = "INSERT INTO nonprofit_membership_batch_members(supplier_id,batch_id,member_id,amount_cents)
                WITH ranked AS (
                  SELECT member_id,status,ROW_NUMBER() OVER (PARTITION BY member_id ORDER BY effective_from DESC,revision_no DESC) rn
                  FROM nonprofit_member_revisions WHERE supplier_id=? AND effective_from<=?
                ) SELECT m.supplier_id,?,m.id,? FROM nonprofit_members m
                  JOIN ranked r ON r.member_id=m.id AND r.rn=1 AND r.status='active' WHERE m.supplier_id=?";
            $params = [$supplier, $date, $batch, $cents, $supplier];
        }
        $q = $this->db->pdo()->prepare($sql);
        $q->execute($params);
        $count = $q->rowCount();
        if ($mode === 'selected' && $count !== count($ids)) throw new MembershipException('nonprofit_membership_batch_selection_invalid', 'Výběr obsahuje člena mimo tuto organizaci.', 400);
        $this->db->pdo()->prepare('UPDATE nonprofit_membership_batches SET member_count=? WHERE supplier_id=? AND id=?')->execute([$count, $supplier, $batch]);
    }

    public function workPage(int $supplier, int $batch, string $status, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        // Stav je součást prefixu indexu: pokračování přeskočí i velký již dokončený začátek dávky.
        $q = $this->db->pdo()->prepare("SELECT * FROM nonprofit_membership_batch_members FORCE INDEX(ix_nmbm_status) WHERE supplier_id=? AND batch_id=? AND status=? ORDER BY member_id LIMIT {$limit}");
        $q->execute([$supplier, $batch, $status]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function prepared(int $supplier, int $batch, array $rows): void
    {
        if ($rows === []) return;
        $q = $this->db->pdo()->prepare("UPDATE nonprofit_membership_batch_members i
            JOIN nonprofit_membership_batches b ON b.supplier_id=i.supplier_id AND b.id=i.batch_id
            JOIN JSON_TABLE(?, '$[*]' COLUMNS(
              member_id BIGINT PATH '$.member_id', status VARCHAR(16) PATH '$.status', amount_cents BIGINT PATH '$.amount_cents',
              preview_hash VARCHAR(64) PATH '$.preview_hash', expected_case_version INT PATH '$.expected_case_version',
              operation_id BIGINT PATH '$.operation_id', deferred_cents BIGINT PATH '$.deferred_cents', deferral_date DATE PATH '$.deferral_date',
              error_code VARCHAR(100) PATH '$.error_code', error_message VARCHAR(2000) PATH '$.error_message'
            )) p ON p.member_id=i.member_id
            SET i.status=p.status,i.amount_cents=p.amount_cents,i.preview_hash=p.preview_hash,i.expected_case_version=p.expected_case_version,
                i.operation_id=p.operation_id,i.deferred_cents=p.deferred_cents,i.deferral_date=p.deferral_date,i.error_code=p.error_code,i.error_message=p.error_message
            WHERE i.supplier_id=? AND i.batch_id=? AND i.status='pending' AND b.phase='preparing'");
        $q->execute([NonprofitTaxRules::canonicalJson($rows), $supplier, $batch]);
    }

    public function finishPreparation(int $supplier, int $batch): void
    {
        $q = $this->db->pdo()->prepare("UPDATE nonprofit_membership_batches b SET phase='ready',approval_token=?
            WHERE b.supplier_id=? AND b.id=? AND b.phase='preparing' AND NOT EXISTS(
              SELECT 1 FROM nonprofit_membership_batch_members i FORCE INDEX(ix_nmbm_status) WHERE i.supplier_id=b.supplier_id AND i.batch_id=b.id AND i.status='pending')");
        $q->execute([bin2hex(random_bytes(32)), $supplier, $batch]);
    }

    public function beginPosting(int $supplier, int $batch, string $token, int $actor): void
    {
        $q = $this->db->pdo()->prepare("UPDATE nonprofit_membership_batches SET phase='posting',confirmed_by=? WHERE supplier_id=? AND id=? AND phase='ready' AND approval_token=?");
        $q->execute([$actor, $supplier, $batch, $token]);
    }

    public function posted(int $supplier, int $batch, int $member, string $status, ?int $operation, ?string $code = null, ?string $message = null): void
    {
        $q = $this->db->pdo()->prepare("UPDATE nonprofit_membership_batch_members SET status=?,operation_id=?,error_code=?,error_message=?
            WHERE supplier_id=? AND batch_id=? AND member_id=? AND status='ready'");
        $q->execute([$status, $operation, $code, $message === null ? null : mb_substr($message, 0, 2000), $supplier, $batch, $member]);
    }

    public function finishPosting(int $supplier, int $batch): void
    {
        $q = $this->db->pdo()->prepare("UPDATE nonprofit_membership_batches b SET phase='finished' WHERE b.supplier_id=? AND b.id=? AND b.phase='posting'
            AND NOT EXISTS(SELECT 1 FROM nonprofit_membership_batch_members i FORCE INDEX(ix_nmbm_status) WHERE i.supplier_id=b.supplier_id AND i.batch_id=b.id AND i.status='ready')");
        $q->execute([$supplier, $batch]);
    }

    public function summary(int $supplier, int $batch): array
    {
        $q = $this->db->pdo()->prepare("SELECT COUNT(*) total,
            COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='ready'),0) ready,COALESCE(SUM(status='created'),0) created,
            COALESCE(SUM(status='existing'),0) existing,COALESCE(SUM(status='rejected'),0) rejected,
            CAST(COALESCE(SUM(CASE WHEN status IN ('ready','created') THEN amount_cents ELSE 0 END),0) AS CHAR) planned_cents,
            CAST(COALESCE(SUM(CASE WHEN status='created' THEN amount_cents ELSE 0 END),0) AS CHAR) created_cents,
            CAST(COALESCE(SUM(CASE WHEN status IN ('ready','created') THEN deferred_cents ELSE 0 END),0) AS CHAR) deferred_cents,
            MIN(CASE WHEN status IN ('ready','created') AND deferred_cents>0 THEN deferral_date END) deferral_date
            FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=?");
        $q->execute([$supplier, $batch]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        foreach (['total', 'pending', 'ready', 'created', 'existing', 'rejected'] as $field) $row[$field] = (int) $row[$field];
        return $row;
    }

    public function page(int $supplier, int $batch, int $after): array
    {
        $q = $this->db->pdo()->prepare("SELECT i.member_id,i.status,i.amount_cents,i.operation_id,i.error_code,i.error_message,m.member_number,c.company_name
            FROM nonprofit_membership_batch_members i JOIN nonprofit_members m ON m.supplier_id=i.supplier_id AND m.id=i.member_id
            JOIN clients c ON c.supplier_id=m.supplier_id AND c.id=m.client_id
            WHERE i.supplier_id=? AND i.batch_id=? AND i.member_id>? ORDER BY i.member_id LIMIT 50");
        $q->execute([$supplier, $batch, $after]);
        return array_map(static function (array $row): array {
            $row['member_id'] = (int) $row['member_id'];
            $row['amount_cents'] = (int) $row['amount_cents'];
            $row['operation_id'] = $row['operation_id'] === null ? null : (int) $row['operation_id'];
            return $row;
        }, $q->fetchAll(PDO::FETCH_ASSOC));
    }

    private function row(array $row): array
    {
        foreach (['id', 'supplier_id', 'created_by', 'member_count'] as $field) $row[$field] = (int) $row[$field];
        $row['confirmed_by'] = $row['confirmed_by'] === null ? null : (int) $row['confirmed_by'];
        $row['template'] = json_decode($row['template_json'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['template_json']);
        return $row;
    }
}
