<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: self::TABLE_NAME)]
#[ORM\Entity]
class CachedPersonStaging
{
    use CachedPersonTrait;

    public const TABLE_NAME = 'persons_staging';
}
