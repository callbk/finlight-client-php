<?php

declare(strict_types=1);

namespace Finlight\Model;

enum ArticleCategory: string
{
    case Markets = 'markets';
    case Economy = 'economy';
    case Business = 'business';
    case Politics = 'politics';
    case Geopolitics = 'geopolitics';
    case Regulation = 'regulation';
    case Technology = 'technology';
    case Energy = 'energy';
    case Commodities = 'commodities';
    case Crypto = 'crypto';
    case Health = 'health';
    case Climate = 'climate';
    case Security = 'security';
}
