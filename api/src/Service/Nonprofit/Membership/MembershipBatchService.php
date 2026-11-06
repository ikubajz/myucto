<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBatchRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class MembershipBatchService
{
    private readonly MembershipBatchRepository $batches;

    public function __construct(private readonly Connection $db, private readonly MembershipChargeService $charges)
    {
        $this->batches = new MembershipBatchRepository($db);
    }

    public function create(int $supplier, array $body, int $actor): array
    {
        $fields = ['template', 'selection_mode', 'member_ids', 'idempotency_key'];
        if (array_diff(array_keys($body), $fields) !== [] || array_diff($fields, array_keys($body)) !== []
            || !is_array($body['template']) || !is_array($body['member_ids']) || !array_is_list($body['member_ids'])
            || !in_array($body['selection_mode'], ['active', 'selected'], true)) self::invalid();
        $ids = $body['member_ids'];
        if (count($ids) > 500 || ($body['selection_mode'] === 'active' ? $ids !== [] : $ids === [])) self::invalid();
        foreach ($ids as $id) if (!is_int($id) || $id <= 0) self::invalid();
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
        if (array_key_exists('member_id', $body['template'])) self::invalid();
        $normalized = MembershipChargeRequest::issue(['member_id' => 1] + $body['template']);
        if (!isset($normalized['rate_id'])) throw new MembershipException('nonprofit_membership_batch_rate_required', 'Pro hromadný předpis vyberte sazbu.', 422);
        $template = $normalized;
        unset($template['member_id'], $template['amount_cents'], $template['legal_title']);
        $template += ['title_reference' => $normalized['legal_title']['reference'], 'evidence' => $normalized['legal_title']['evidence'], 'nonreciprocal' => true];
        $key = MembershipChargeRequest::key($body['idempotency_key']);
        $hash = NonprofitTaxRules::hash(['template' => $template, 'selection_mode' => $body['selection_mode'], 'member_ids' => $ids, 'actor' => $actor]);
        $batchId = TransactionFailure::retrying($this->db->pdo(), fn (): int => (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $template, $ids, $key, $hash, $actor, $body, $normalized): int {
            // Krátký zámek serializuje založení se stejným klíčem, nikoli účtování celé dávky.
            $q = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id=? FOR UPDATE');
            $q->execute([$supplier]);
            if (($existing = $this->batches->byKey($supplier, $key)) !== null) {
                if (!hash_equals($existing['request_hash'], $hash)) throw new MembershipException('nonprofit_membership_idempotency_conflict', 'Klíč dávky už patří jinému požadavku.', 409);
                return $existing['id'];
            }
            (new MembershipRateService($this->db))->assertUsable($supplier, $normalized['rate_id'], true);
            $id = $this->batches->insert($supplier, $key, $hash, $template, $body['selection_mode'], $actor);
            $this->batches->seed($supplier, $id, $body['selection_mode'], $ids, $template['accounting_date'], $normalized['amount_cents']);
            return $id;
        }));
        return $this->detail($supplier, $batchId);
    }

    public function detail(int $supplier, int $id, int $after = 0): array
    {
        $batch = $this->batch($supplier, $id);
        $summary = $this->batches->summary($supplier, $id);
        $items = $this->batches->page($supplier, $id, $after);
        return ['batch' => $batch, 'summary' => $summary, 'items' => $items,
            'next_after_member_id' => count($items) === 50 ? $items[array_key_last($items)]['member_id'] : null,
            'accounts' => [
                ['account' => $batch['template']['receivable_account'], 'side' => 'debit', 'amount_cents' => $summary['planned_cents']],
                ['account' => $batch['template']['revenue_account'], 'side' => 'credit', 'amount_cents' => $summary['planned_cents']],
            ]];
    }

    public function prepare(int $supplier, int $id): array
    {
        $batch = $this->batch($supplier, $id);
        if ($batch['phase'] !== 'preparing') return $this->detail($supplier, $id);
        $work = $this->batches->workPage($supplier, $id, 'pending', 50);
        $ids = array_map('intval', array_column($work, 'member_id'));
        if ($ids !== []) {
            $results = $this->charges->previewIssuePage($supplier, $batch['template'], $ids);
            $rows = [];
            foreach ($results as $member => $result) {
                $preview = $result['preview'] ?? null;
                $existing = $result['existing'] ?? null;
                $error = $result['error'] ?? null;
                $rows[] = ['member_id' => $member, 'status' => $preview ? 'ready' : ($existing ? 'existing' : 'rejected'),
                    'amount_cents' => $existing ? (int) $existing['head_amount_cents'] : ($preview['request']['amount_cents'] ?? (int) $work[0]['amount_cents']),
                    'preview_hash' => $preview['preview_hash'] ?? null, 'expected_case_version' => $preview['expected_case_version'] ?? null,
                    'operation_id' => $existing['head_operation_id'] ?? null,
                    'deferred_cents' => isset($preview['deferral']) ? JournalLineAmount::cents($preview['deferral']['deferred_amount']) : 0,
                    'deferral_date' => $preview['deferral']['balance_sheet_date'] ?? null,
                    'error_code' => $error?->errorCode, 'error_message' => $error === null ? null : mb_substr($error->getMessage(), 0, 2000)];
            }
            $this->batches->prepared($supplier, $id, $rows);
        }
        $this->batches->finishPreparation($supplier, $id);
        return $this->detail($supplier, $id);
    }

    public function confirm(int $supplier, int $id, string $token, int $actor): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) self::invalid();
        $this->batches->beginPosting($supplier, $id, $token, $actor);
        $batch = $this->batch($supplier, $id);
        if (!is_string($batch['approval_token']) || !hash_equals($batch['approval_token'], $token)
            || !in_array($batch['phase'], ['posting', 'finished'], true)) {
            throw new MembershipException('nonprofit_membership_batch_not_ready', 'Nejdřív dokončete a zkontrolujte náhled celé dávky.', 409);
        }
        // Každý člen je samostatná účetní transakce stejnou cestou jako ruční předpis.
        // Žádná transakce ani pole výsledků přes celou dávku; po technické chybě zůstává řádek k opakování.
        foreach ($this->batches->workPage($supplier, $id, 'ready', 10) as $item) {
            $member = (int) $item['member_id'];
            try {
                $result = $this->charges->confirmIssue($supplier, ['member_id' => $member] + $batch['template'],
                    (int) $item['expected_case_version'], $item['preview_hash'], 'batch-' . $id . '-member-' . $member, $batch['confirmed_by']);
                // Replay je předpis vytvořený touto dávkou v předchozím pokusu, nikoli cizí dřívější předpis.
                $this->batches->posted($supplier, $id, $member, 'created', $result['operation']['id']);
            } catch (MembershipException|PostingException $e) {
                if ($e->httpStatus >= 500) throw $e;
                $this->batches->posted($supplier, $id, $member, 'rejected', null, $e->errorCode, $e->getMessage());
            }
        }
        $this->batches->finishPosting($supplier, $id);
        return $this->detail($supplier, $id);
    }

    private function batch(int $supplier, int $id): array
    {
        return $this->batches->find($supplier, $id)
            ?? throw new MembershipException('nonprofit_membership_batch_not_found', 'Dávka nebyla nalezena v této organizaci.', 404);
    }

    private static function invalid(): never
    {
        throw new MembershipException('nonprofit_membership_batch_invalid', 'Dávka vyžaduje platnou sazbu, společné údaje a výběr členů.', 422);
    }
}
