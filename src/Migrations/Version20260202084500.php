<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Migrations;

use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPerson;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPersonStaging;
use Doctrine\DBAL\Schema\Schema;

final class Version20260202084500 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'creates the persons cache table';
    }

    public function up(Schema $schema): void
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
        $personGroupsColumn = 'personGroups'; // column name for person groups, not defined as constant in CachedPerson since it will be removed in a later migration

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
                   $personGroupsColumn INT DEFAULT 0 NOT NULL,
               ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
            STMT;
        $this->addSql($createStatement);

        $this->addSql("CREATE TABLE $personsStagingTableName LIKE $personTableName");
    }
}
