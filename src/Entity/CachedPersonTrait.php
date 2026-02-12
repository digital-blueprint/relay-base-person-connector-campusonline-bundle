<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

trait CachedPersonTrait
{
    public const UID_COLUMN_NAME = 'uid';
    public const GIVEN_NAME_COLUMN_NAME = 'givenName';
    public const SURNAME_COLUMN_NAME = 'surname';
    public const DATE_OF_BIRTH_COLUMN_NAME = 'dateOfBirth';
    public const EMAIL_COLUMN_NAME = 'email';
    public const MATRICULATION_NUMBER_COLUMN_NAME = 'matriculationNumber';
    public const TITLE_PREFIX_COLUMN_NAME = 'titlePrefix';
    public const GENDER_KEY_COLUMN_NAME = 'genderKey';
    public const PERSON_GROUPS_COLUMN_NAME = 'personGroups';

    public const BASE_ENTITY_ATTRIBUTE_MAPPING = [
        'identifier' => self::UID_COLUMN_NAME,
        'givenName' => self::GIVEN_NAME_COLUMN_NAME,
        'familyName' => self::SURNAME_COLUMN_NAME,
    ];

    public const LOCAL_DATA_SOURCE_ATTRIBUTES = [
        self::DATE_OF_BIRTH_COLUMN_NAME => 'getDateOfBirth',
        self::EMAIL_COLUMN_NAME => 'getEmail',
        self::MATRICULATION_NUMBER_COLUMN_NAME => 'getMatriculationNumber',
        self::TITLE_PREFIX_COLUMN_NAME => 'getTitlePrefix',
        self::GENDER_KEY_COLUMN_NAME => 'getGenderKey',
        self::PERSON_GROUPS_COLUMN_NAME => 'getPersonGroups',
    ];

    public const STUDENT_PERSON_GROUP_MASK = 0b00000001;
    public const EMPLOYEE_PERSON_GROUP_MASK = 0b00000010;
    public const EXTERNAL_PERSON_GROUP_MASK = 0b00000100;

    #[ORM\Id]
    #[ORM\Column(name: self::UID_COLUMN_NAME, type: 'string', length: 32)]
    private ?string $uid = null;
    #[ORM\Column(name: self::GIVEN_NAME_COLUMN_NAME, type: 'string', length: 128, nullable: true)]
    private ?string $givenName = null;
    #[ORM\Column(name: self::SURNAME_COLUMN_NAME, type: 'string', length: 128, nullable: true)]
    private ?string $surname = null;
    #[ORM\Column(name: self::DATE_OF_BIRTH_COLUMN_NAME, type: 'datetime', nullable: true)]
    private ?\DateTime $dateOfBirth = null;
    #[ORM\Column(name: self::EMAIL_COLUMN_NAME, type: 'string', length: 128, nullable: true)]
    private ?string $email = null;
    #[ORM\Column(name: self::MATRICULATION_NUMBER_COLUMN_NAME, type: 'string', length: 16, nullable: true)]
    private ?string $matriculationNumber = null;
    #[ORM\Column(name: self::TITLE_PREFIX_COLUMN_NAME, type: 'string', length: 128, nullable: true)]
    private ?string $titlePrefix = null;
    #[ORM\Column(name: self::GENDER_KEY_COLUMN_NAME, type: 'string', length: 4, nullable: true)]
    private ?string $genderKey = null;
    #[ORM\Column(name: self::PERSON_GROUPS_COLUMN_NAME, type: 'integer', nullable: true)]
    private ?int $personGroups = null;

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(?string $uid): void
    {
        $this->uid = $uid;
    }

    public function getGivenName(): ?string
    {
        return $this->givenName;
    }

    public function setGivenName(?string $givenName): void
    {
        $this->givenName = $givenName;
    }

    public function getSurname(): ?string
    {
        return $this->surname;
    }

    public function setSurname(?string $surname): void
    {
        $this->surname = $surname;
    }

    public function getDateOfBirth(): ?\DateTime
    {
        return $this->dateOfBirth;
    }

    public function setDateOfBirth(?\DateTime $dateOfBirth): void
    {
        $this->dateOfBirth = $dateOfBirth;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getMatriculationNumber(): ?string
    {
        return $this->matriculationNumber;
    }

    public function setMatriculationNumber(?string $matriculationNumber): void
    {
        $this->matriculationNumber = $matriculationNumber;
    }

    public function getTitlePrefix(): ?string
    {
        return $this->titlePrefix;
    }

    public function setTitlePrefix(?string $titlePrefix): void
    {
        $this->titlePrefix = $titlePrefix;
    }

    public function getGenderKey(): ?string
    {
        return $this->genderKey;
    }

    public function setGenderKey(?string $genderKey): void
    {
        $this->genderKey = $genderKey;
    }

    public function getPersonGroups(): ?int
    {
        return $this->personGroups;
    }

    public function setPersonGroups(?int $personGroups): void
    {
        $this->personGroups = $personGroups;
    }

    public function getLocalDataSourceAttributeValues(): array
    {
        return array_map(function (string $getterMethod) {
            return $this->$getterMethod();
        }, self::LOCAL_DATA_SOURCE_ATTRIBUTES);
    }

    public static function testIsStudent(int $personGroups): bool
    {
        return ($personGroups & self::STUDENT_PERSON_GROUP_MASK) === self::STUDENT_PERSON_GROUP_MASK;
    }

    public static function testIsEmployee(int $personGroups): bool
    {
        return ($personGroups & self::EMPLOYEE_PERSON_GROUP_MASK) === self::EMPLOYEE_PERSON_GROUP_MASK;
    }

    public static function testIsExternalPerson(int $personGroups): bool
    {
        return ($personGroups & self::EXTERNAL_PERSON_GROUP_MASK) === self::EXTERNAL_PERSON_GROUP_MASK;
    }
}
