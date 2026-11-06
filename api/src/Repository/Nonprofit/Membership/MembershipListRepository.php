<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Nonprofit\Membership\MembershipReconciliationService;
use PDO;

final class MembershipListRepository
{
    public function __construct(private readonly Connection $db) {}

    /** Souhrn i filtr úhrady před stránkováním v jediném dotazu; částky souhrnů zůstávají přesné řetězce. */
    public function page(int $supplier, int $after, int $limit, array $filter): array
    {
        [$sql, $params] = $this->query($supplier, $after, $limit, $filter);
        $q = $this->db->pdo()->prepare($sql); $q->execute($params);
        return array_map(static function (array $row): array {
            foreach (['id', 'supplier_id', 'client_id', 'created_by'] as $field) $row[$field] = (int) $row[$field];
            $row['revision_no'] = $row['revision_no'] === null ? null : (int) $row['revision_no'];
            $row['contribution'] = ['status' => $row['dues_status'], 'claim_count' => (int) $row['dues_count'],
                'prescribed_cents' => (string) $row['dues_prescribed'], 'paid_cents' => $row['dues_paid'], 'open_cents' => $row['dues_open']];
            foreach (['dues_status', 'dues_count', 'dues_prescribed', 'dues_paid', 'dues_open'] as $field) unset($row[$field]);
            return $row;
        }, $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Veřejné sestavení pro EXPLAIN stejného dotazu jako v aplikaci, bez odlišné měřicí kopie. */
    public function query(int $supplier, int $after, int $limit, array $filter): array
    {
        $limit = max(1, min(101, $limit));
        $params = [$supplier, $after];
        $search = '';
        if ($filter['search'] !== '') {
            $search = " AND (m.member_number LIKE ? ESCAPE '=' OR c.company_name LIKE ? ESCAPE '=')";
            $like = '%' . strtr($filter['search'], ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
            array_push($params, $like, $like);
        }
        $condition = 'cc.supplier_id=?'; $params[] = $supplier;
        if ($filter['year'] !== null) {
            $condition .= ' AND cc.period_to>=? AND cc.period_from<?';
            array_push($params, $filter['year'] . '-01-01', ($filter['year'] + 1) . '-01-01');
        }
        if ($filter['title_key'] !== '') { $condition .= ' AND cc.title_key=?'; $params[] = $filter['title_key']; }
        $allStates = $filter['payment_status'] === 'all';
        $index = $allStates ? 'uq_nmcc_identity' : ($filter['title_key'] === '' ? 'ix_nmcc_list_period' : 'ix_nmcc_list_title');
        // Bez filtru úhrady nejdřív omezíme členy; do finanční agregace vstupuje jen tato stránka.
        // Při filtru úhrady se naopak musí nejdřív zjistit stav všech kandidátů a až pak použít LIMIT.
        $scopeLimit = $allStates ? " ORDER BY m.id LIMIT {$limit}" : '';
        $claimsSource = $allStates
            ? "scope m STRAIGHT_JOIN nonprofit_membership_charge_cases cc FORCE INDEX({$index}) ON cc.supplier_id=m.supplier_id AND cc.member_id=m.id"
            : "nonprofit_membership_charge_cases cc FORCE INDEX({$index}) JOIN scope m ON m.supplier_id=cc.supplier_id AND m.id=cc.member_id";
        $pairingSql = MembershipBalanceRepository::nativePairingTotalsSql();
        $statusSql = MembershipReconciliationService::settlementStatusSql();
        $params[] = $supplier;
        $statusFilter = '';
        if ($filter['payment_status'] !== 'all') { $statusFilter = ' WHERE dues_status=?'; $params[] = $filter['payment_status']; }
        $params[] = $supplier;
        $sql = "WITH scope AS (
            SELECT m.*,c.company_name FROM nonprofit_members m FORCE INDEX(uq_nm_tenant)
              JOIN clients c ON c.supplier_id=m.supplier_id AND c.id=m.client_id
              WHERE m.supplier_id=? AND m.id>?{$search}{$scopeLimit}
          ), claims AS (
            SELECT c.id claim_id,c.supplier_id,c.member_id,c.amount_cents,o.journal_entry_id,o.receivable_line_id,
              l.line_no,l.account_id,e.posted_at,e.reversed_by,NULL reversal_claim_id
            FROM {$claimsSource}
              JOIN nonprofit_membership_charge_operations o ON o.supplier_id=cc.supplier_id AND o.case_id=cc.id AND o.id=cc.head_operation_id AND o.operation='issue'
              JOIN nonprofit_membership_charge_claims c ON c.supplier_id=o.supplier_id AND c.operation_id=o.id AND c.kind='issue'
              LEFT JOIN journal_entries e ON e.supplier_id=o.supplier_id AND e.id=o.journal_entry_id
              LEFT JOIN journal_entry_lines l ON l.supplier_id=o.supplier_id AND l.id=o.receivable_line_id AND l.entry_id=o.journal_entry_id
            WHERE {$condition}
          ), requested AS (
            SELECT c.claim_id,i.pairing_id,c.account_id FROM claims c
              JOIN journal_line_pairing_items i ON i.supplier_id=c.supplier_id AND i.entry_id=c.journal_entry_id AND i.line_no=c.line_no AND i.account_id=c.account_id
              WHERE EXISTS(SELECT 1 FROM journal_line_pairing_items other WHERE other.supplier_id=i.supplier_id AND other.pairing_id=i.pairing_id
                AND (other.entry_id<>i.entry_id OR other.line_no<>i.line_no))
          ), pairing_totals AS ({$pairingSql}), facts AS (
            SELECT c.*,p.net_cents,{$statusSql} settlement_status FROM claims c LEFT JOIN pairing_totals p ON p.claim_id=c.claim_id
          ), totals AS (
            SELECT member_id,COUNT(*) claim_count,CAST(SUM(amount_cents) AS CHAR) prescribed,
              CASE WHEN SUM(settlement_status NOT IN ('settled','partially_settled'))>0 THEN NULL
                ELSE CAST(SUM(amount_cents-net_cents) AS CHAR) END paid,
              CASE WHEN SUM(settlement_status NOT IN ('settled','partially_settled'))>0 THEN NULL
                ELSE CAST(SUM(net_cents) AS CHAR) END open_cents,
              SUM(settlement_status NOT IN ('settled','partially_settled')) unknown_count
            FROM facts GROUP BY member_id
          ), filtered AS (
            SELECT m.*,COALESCE(t.claim_count,0) dues_count,COALESCE(t.prescribed,'0') dues_prescribed,
              CASE WHEN t.claim_count IS NULL THEN '0' ELSE t.paid END dues_paid,
              CASE WHEN t.claim_count IS NULL THEN '0' ELSE t.open_cents END dues_open,
              CASE WHEN t.claim_count IS NULL THEN 'none' WHEN t.unknown_count>0 THEN 'unproven'
                WHEN t.open_cents=0 THEN 'paid' ELSE 'unpaid' END dues_status
            FROM scope m LEFT JOIN totals t ON t.member_id=m.id
          ), paged AS (SELECT * FROM filtered{$statusFilter} ORDER BY id LIMIT {$limit}), revisions AS (
            SELECT r.member_id,r.revision_no,r.effective_from,r.status,
              ROW_NUMBER() OVER (PARTITION BY r.member_id ORDER BY r.revision_no DESC) rn
            FROM nonprofit_member_revisions r JOIN paged m ON m.id=r.member_id AND m.supplier_id=r.supplier_id WHERE r.supplier_id=?
          ) SELECT p.*,r.revision_no,r.effective_from,r.status FROM paged p LEFT JOIN revisions r ON r.member_id=p.id AND r.rn=1 ORDER BY p.id";
        return [$sql, $params];
    }
}
