<?php

declare(strict_types=1);

namespace Finlight\Model;

enum OrderBy: string
{
    case PublishDate = 'publishDate';
    case CreatedAt = 'createdAt';
    case RevisedDate = 'revisedDate';
}
