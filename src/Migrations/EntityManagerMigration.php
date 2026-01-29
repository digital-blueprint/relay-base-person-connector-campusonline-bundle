<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Migrations;

use Dbp\Relay\BasePersonConnectorCampusonlineBundle\DependencyInjection\DbpRelayBasePersonConnectorCampusonlineExtension;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPerson;
use Dbp\Relay\CoreBundle\Doctrine\AbstractEntityManagerMigration;

abstract class EntityManagerMigration extends AbstractEntityManagerMigration
{
    protected function getEntityManagerId(): string
    {
        return DbpRelayBasePersonConnectorCampusonlineExtension::ENTITY_MANAGER_ID;
    }

    protected function recreateCacheTables(): void
    {
        $personTableName = CachedPerson::TABLE_NAME;
        $personsStagingTableName = CachedPerson::STAGING_TABLE_NAME;

        $this->addSql("DROP TABLE IF EXISTS $personsStagingTableName");
        $this->addSql("DROP TABLE IF EXISTS $personTableName");

        $uidColumn = CachedPerson::UID_COLUMN_NAME;

        $createStatement = <<<STMT
               CREATE TABLE $personTableName (
                   $uidColumn VARCHAR(32) NOT NULL,
                   PRIMARY KEY($uidColumn)
               ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
            STMT;
        $this->addSql($createStatement);

        $this->addSql("CREATE TABLE $personsStagingTableName LIKE $personTableName");
    }
}
