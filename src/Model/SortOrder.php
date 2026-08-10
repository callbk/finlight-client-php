<?php

declare(strict_types=1);

namespace Finlight\Model;

enum SortOrder: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}
