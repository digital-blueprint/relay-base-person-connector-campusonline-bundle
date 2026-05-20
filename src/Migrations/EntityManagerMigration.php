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

        $uidColumn = CachedPerson::UID;
        $givenNameColumn = CachedPerson::GIVEN_NAME;
        $surnameColumn = CachedPerson::SURNAME;
        $dateOfBirthColumn = CachedPerson::DATE_OF_BIRTH;
        $emailColumn = CachedPerson::EMAIL;
        $matriculationNumberColumn = CachedPerson::MATRICULATION_NUMBER;
        $titlePrefixColumn = CachedPerson::TITLE_PREFIX;
        $titleSuffixColumn = CachedPerson::TITLE_SUFFIX;
        $genderKeyColumn = CachedPerson::GENDER_KEY;
        $isStaffColumn = CachedPerson::IS_STAFF;
        $isStudentColumn = CachedPerson::IS_STUDENT;
        $isAlumniColumn = CachedPerson::IS_ALUMNI;
        $isExternalColumn = CachedPerson::IS_EXTERNAL;

        $createStatement = <<<STMT
               CREATE TABLE $personTableName (
                   $uidColumn VARCHAR(32) NOT NULL,
                   $givenNameColumn VARCHAR(128) NULL,
                   $surnameColumn VARCHAR(128) NULL,
                   $dateOfBirthColumn VARCHAR(10) NULL,
                   $emailColumn VARCHAR(128) NULL,
                   $matriculationNumberColumn VARCHAR(16) NULL,
                   $titlePrefixColumn VARCHAR(128) NULL,
                   $titleSuffixColumn VARCHAR(128) NULL,
                   $genderKeyColumn VARCHAR(4) NULL,
                   $isStaffColumn SMALLINT DEFAULT 0 NOT NULL,
                   $isStudentColumn SMALLINT DEFAULT 0 NOT NULL,
                   $isAlumniColumn SMALLINT DEFAULT 0 NOT NULL,
                   $isExternalColumn SMALLINT DEFAULT 0 NOT NULL,
                   PRIMARY KEY($uidColumn)
               ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
            STMT;
        $this->addSql($createStatement);

        $this->addSql("CREATE TABLE $personsStagingTableName LIKE $personTableName");
    }
}
