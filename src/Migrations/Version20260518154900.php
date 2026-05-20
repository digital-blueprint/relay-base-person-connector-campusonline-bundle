<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Migrations;

use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPerson;
use Doctrine\DBAL\Schema\Schema;

class Version20260518154900 extends EntityManagerMigration
{
    private const STUDENT_PERSON_GROUP_MASK = 0b00000001;
    private const EMPLOYEE_PERSON_GROUP_MASK = 0b00000010;
    private const ALUMNI_PERSON_GROUP_MASK = 0b00000100;

    public function getDescription(): string
    {
        return 'replace column personGroups by isStudent, isStaff, isAlumni and isExternal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE persons ADD COLUMN isStudent SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE persons ADD COLUMN isStaff SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE persons ADD COLUMN isAlumni SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE persons ADD COLUMN isExternal SMALLINT DEFAULT 0 NOT NULL');

        $this->addSql(
            'UPDATE persons SET isStudent = '.CachedPerson::YES_WITH_ACCOUNT.' WHERE personGroups & '.self::STUDENT_PERSON_GROUP_MASK.' != 0'
        );
        $this->addSql(
            'UPDATE persons SET isStaff = '.CachedPerson::YES_WITH_ACCOUNT.' WHERE personGroups & '.self::EMPLOYEE_PERSON_GROUP_MASK.' != 0'
        );
        $this->addSql(
            'UPDATE persons SET isAlumni = '.CachedPerson::YES_WITH_ACCOUNT.' WHERE personGroups & '.self::ALUMNI_PERSON_GROUP_MASK.' != 0'
        );

        $this->addSql('ALTER TABLE persons DROP COLUMN personGroups');

        // staging table:
        $this->addSql('ALTER TABLE persons_staging ADD COLUMN isStudent SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE persons_staging ADD COLUMN isStaff SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE persons_staging ADD COLUMN isAlumni SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE persons_staging ADD COLUMN isExternal SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE persons_staging DROP COLUMN personGroups');
    }
}
