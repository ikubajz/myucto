<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

final class MembershipListFilter
{
    public static function parse(array $query): array
    {
        $search = $query['search'] ?? '';
        $title = $query['title_key'] ?? '';
        $year = $query['year'] ?? (int) date('Y');
        $status = $query['payment_status'] ?? 'all';
        if (!is_string($search) || mb_strlen(trim($search)) > 160 || !is_string($title)
            || !in_array($status, ['all', 'paid', 'unpaid', 'unproven', 'none'], true)) self::invalid();
        $search = trim($search); $title = trim($title);
        if ($title !== '') $title = MembershipChargeRequest::key($title, 'titulu');
        if ($year === '') $year = null;
        elseif ((is_int($year) || is_string($year)) && preg_match('/^[0-9]{4}$/D', (string) $year) && (int) $year >= 1900 && (int) $year <= 9998) $year = (int) $year;
        else self::invalid();
        if ($year === null && $title === '') self::invalid();
        return ['search' => $search, 'title_key' => $title, 'year' => $year, 'payment_status' => $status];
    }

    private static function invalid(): never
    {
        throw new MembershipException('nonprofit_membership_list_filter_invalid', 'Zadejte platný rok nebo označení příspěvku, hledání nejvýše 160 znaků a platný stav úhrady.', 422);
    }
}
