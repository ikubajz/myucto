<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit\Membership;

use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipListFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MembershipListFilterTest extends TestCase
{
    public function testTitleCanSelectAllItsPeriodsWithoutAssumingCalendarMembership(): void
    {
        self::assertSame(['search'=>'Synthetic','title_key'=>'SYNTHETIC','year'=>null,'payment_status'=>'unproven'],
            MembershipListFilter::parse(['search'=>' Synthetic ','title_key'=>'SYNTHETIC','year'=>'','payment_status'=>'unproven']));
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidFiltersAreValidationErrors(array $query): void
    {
        $this->expectException(MembershipException::class);
        MembershipListFilter::parse($query);
    }

    public static function invalidQueries(): iterable
    {
        yield 'prázdné období i titul' => [['year'=>'']];
        yield 'příliš krátký rok' => [['year'=>'26']];
        yield 'mezera roku' => [['year'=>' 2026']];
        yield 'přesah roku' => [['year'=>'9999']];
        yield 'pole roku' => [['year'=>[]]];
        yield 'pole hledání' => [['search'=>[]]];
        yield 'dlouhé hledání' => [['search'=>str_repeat('x',161)]];
        yield 'pole titulu' => [['title_key'=>[]]];
        yield 'neplatný titul' => [['title_key'=>'not a key']];
        yield 'cizí stav' => [['payment_status'=>'settled']];
    }
}
