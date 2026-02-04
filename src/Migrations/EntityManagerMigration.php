<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Migrations;

use Dbp\Relay\BasePersonConnectorCampusonlineBundle\DependencyInjection\DbpRelayBasePersonConnectorCampusonlineExtension;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPerson;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPersonStaging;
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
        $personsStagingTableName = CachedPersonStaging::TABLE_NAME;

        $this->addSql("DROP TABLE IF EXISTS $personsStagingTableName");
        $this->addSql("DROP TABLE IF EXISTS $personTableName");

        $uidColumn = CachedPerson::UID_COLUMN_NAME;
        $givenNameColumn = CachedPerson::GIVEN_NAME_COLUMN_NAME;
        $surnameColumn = CachedPerson::SURNAME_COLUMN_NAME;
        $dateOfBirthColumn = CachedPerson::DATE_OF_BIRTH_COLUMN_NAME;
        $emailColumn = CachedPerson::EMAIL_COLUMN_NAME;
        $matriculationNumberColumn = CachedPerson::MATRICULATION_NUMBER_COLUMN_NAME;
        $titlePrefixColumn = CachedPerson::TITLE_PREFIX_COLUMN_NAME;
        $genderKeyColumn = CachedPerson::GENDER_KEY_COLUMN_NAME;
        $personGroupsColumn = CachedPerson::PERSON_GROUPS_COLUMN_NAME;

        $createStatement = <<<STMT
               CREATE TABLE $personTableName (
                   $uidColumn VARCHAR(32) NOT NULL,
                   $givenNameColumn VARCHAR(128) NULL,
                   $surnameColumn VARCHAR(128) NULL,
                   $dateOfBirthColumn DATETIME NULL,
                   $emailColumn VARCHAR(128) NULL,
                   $matriculationNumberColumn VARCHAR(16) NULL,
                   $titlePrefixColumn VARCHAR(128) NULL,
                   $genderKeyColumn VARCHAR(4) NULL,
                   $personGroupsColumn INT NULL,
                   PRIMARY KEY($uidColumn)
               ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
            STMT;
        $this->addSql($createStatement);

        $this->addSql("CREATE TABLE $personsStagingTableName LIKE $personTableName");
    }
}
