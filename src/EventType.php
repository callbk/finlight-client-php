<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known values of events[].type and of the eventTypes filter. Open: unknown values are kept as sent.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class EventType
{
    public const EARNINGS = 'earnings';
    public const GUIDANCE = 'guidance';
    public const MNA = 'mna';
    public const IPO_LISTING = 'ipo_listing';
    public const CAPITAL_ACTION = 'capital_action';
    public const RATING_ACTION = 'rating_action';
    public const MANAGEMENT_CHANGE = 'management_change';
    public const LEGAL = 'legal';
    public const REGULATORY = 'regulatory';
    public const BANKRUPTCY_DISTRESS = 'bankruptcy_distress';
    public const PRODUCT_PARTNERSHIP = 'product_partnership';
    public const INVESTMENT_EXPANSION = 'investment_expansion';
    public const OPERATIONAL_INCIDENT = 'operational_incident';
    public const LABOR_RESTRUCTURING = 'labor_restructuring';
    public const MACRO = 'macro';
    public const GEOPOLITICS = 'geopolitics';

    /** @var list<string> */
    public const ALL = [self::EARNINGS, self::GUIDANCE, self::MNA, self::IPO_LISTING, self::CAPITAL_ACTION, self::RATING_ACTION, self::MANAGEMENT_CHANGE, self::LEGAL, self::REGULATORY, self::BANKRUPTCY_DISTRESS, self::PRODUCT_PARTNERSHIP, self::INVESTMENT_EXPANSION, self::OPERATIONAL_INCIDENT, self::LABOR_RESTRUCTURING, self::MACRO, self::GEOPOLITICS];

    private function __construct()
    {
    }
}
