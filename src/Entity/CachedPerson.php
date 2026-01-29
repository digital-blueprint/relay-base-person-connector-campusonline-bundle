<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: self::TABLE_NAME)]
#[ORM\Entity]
class CachedPerson
{
    public const TABLE_NAME = 'persons';
    public const STAGING_TABLE_NAME = 'persons_staging';

    public const UID_COLUMN_NAME = 'uid';

    public const ALL_COLUMN_NAMES = [
        self::UID_COLUMN_NAME,
    ];

    #[ORM\Id]
    #[ORM\Column(name: self::UID_COLUMN_NAME, type: 'string', length: 32)]
    private ?string $uid = null;

    public function __construct()
    {
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(?string $uid): void
    {
        $this->uid = $uid;
    }
}
