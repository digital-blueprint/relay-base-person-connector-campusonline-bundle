<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Migrations;

use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPerson;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPersonStaging;
use Doctrine\DBAL\Schema\Schema;

final class Version20260629152000 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'add personTypeKey to persons cache tables';
    }

    public function up(Schema $schema): void
    {
        $personTypeKeyColumn = CachedPerson::PERSON_TYPE_KEY;

        $this->addSql('ALTER TABLE '.CachedPerson::TABLE_NAME." ADD $personTypeKeyColumn VARCHAR(4) NULL");
        $this->addSql('ALTER TABLE '.CachedPersonStaging::TABLE_NAME." ADD $personTypeKeyColumn VARCHAR(4) NULL");
    }
}
