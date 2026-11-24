<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known values of companies[].role and of the roles filter. Open: unknown values are kept as sent.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class CompanyRole
{
    public const ACQUIRER = 'acquirer';
    public const TARGET = 'target';
    public const SELLER = 'seller';
    public const DEFENDANT = 'defendant';
    public const SUBJECT = 'subject';
    public const COUNTERPARTY = 'counterparty';
    public const SUPPLIER = 'supplier';
    public const CUSTOMER = 'customer';
    public const COMPETITOR = 'competitor';
    public const MENTIONED = 'mentioned';

    /** @var list<string> */
    public const ALL = [self::ACQUIRER, self::TARGET, self::SELLER, self::DEFENDANT, self::SUBJECT, self::COUNTERPARTY, self::SUPPLIER, self::CUSTOMER, self::COMPETITOR, self::MENTIONED];

    private function __construct()
    {
    }
}
