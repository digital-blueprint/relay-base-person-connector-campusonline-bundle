<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

trait CachedPersonTrait
{
    public const UID = 'uid';
    public const GIVEN_NAME = 'givenName';
    public const SURNAME = 'surname';
    public const DATE_OF_BIRTH = 'dateOfBirth';
    public const EMAIL = 'email';
    public const MATRICULATION_NUMBER = 'matriculationNumber';
    public const TITLE_PREFIX = 'titlePrefix';
    public const TITLE_SUFFIX = 'titleSuffix';
    public const GENDER_KEY = 'genderKey';
    public const IS_STAFF = 'isStaff';
    public const IS_STUDENT = 'isStudent';
    public const IS_ALUMNI = 'isAlumni';
    public const IS_EXTERNAL = 'isExternal';

    public const NO = 0;
    public const YES_WITHOUT_ACCOUNT = 1;
    public const YES_WITH_ACCOUNT = 2;

    public const BASE_ENTITY_ATTRIBUTE_MAPPING = [
        'identifier' => self::UID,
        'givenName' => self::GIVEN_NAME,
        'familyName' => self::SURNAME,
    ];

    public const LOCAL_DATA_SOURCE_ATTRIBUTES = [
        self::DATE_OF_BIRTH => 'getDateOfBirth',
        self::EMAIL => 'getEmail',
        self::MATRICULATION_NUMBER => 'getMatriculationNumber',
        self::TITLE_PREFIX => 'getTitlePrefix',
        self::TITLE_SUFFIX => 'getTitleSuffix',
        self::GENDER_KEY => 'getGenderKey',
        self::IS_STAFF => 'getIsStaff',
        self::IS_STUDENT => 'getIsStudent',
        self::IS_ALUMNI => 'getIsAlumni',
        self::IS_EXTERNAL => 'getIsExternal',
        self::PERSON_TYPE_KEY => 'getPersonTypeKey',
    ];
    public const PERSON_TYPE_KEY = 'personTypeKey';

    #[ORM\Id]
    #[ORM\Column(name: self::UID, type: 'string', length: 32)]
    private ?string $uid = null;
    #[ORM\Column(name: self::GIVEN_NAME, type: 'string', length: 128, nullable: true)]
    private ?string $givenName = null;
    #[ORM\Column(name: self::SURNAME, type: 'string', length: 128, nullable: true)]
    private ?string $surname = null;
    #[ORM\Column(name: self::DATE_OF_BIRTH, type: 'string', length: 10, nullable: true)]
    private ?string $dateOfBirth = null;
    #[ORM\Column(name: self::EMAIL, type: 'string', length: 128, nullable: true)]
    private ?string $email = null;
    #[ORM\Column(name: self::MATRICULATION_NUMBER, type: 'string', length: 16, nullable: true)]
    private ?string $matriculationNumber = null;
    #[ORM\Column(name: self::TITLE_PREFIX, type: 'string', length: 128, nullable: true)]
    private ?string $titlePrefix = null;
    #[ORM\Column(name: self::TITLE_SUFFIX, type: 'string', length: 128, nullable: true)]
    private ?string $titleSuffix = null;
    #[ORM\Column(name: self::GENDER_KEY, type: 'string', length: 4, nullable: true)]
    private ?string $genderKey = null;
    #[ORM\Column(name: self::PERSON_TYPE_KEY, type: 'string', length: 4, nullable: true)]
    private ?string $personTypeKey = null;
    #[ORM\Column(name: self::IS_STAFF, type: 'smallint')]
    private int $isStaff = self::NO;
    #[ORM\Column(name: self::IS_STUDENT, type: 'smallint')]
    private int $isStudent = self::NO;
    #[ORM\Column(name: self::IS_ALUMNI, type: 'smallint')]
    private int $isAlumni = self::NO;
    #[ORM\Column(name: self::IS_EXTERNAL, type: 'smallint')]
    private int $isExternal = self::NO;

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

    public function getDateOfBirth(): ?string
    {
        return $this->dateOfBirth;
    }

    public function setDateOfBirth(?string $dateOfBirth): void
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

    public function getTitleSuffix(): ?string
    {
        return $this->titleSuffix;
    }

    public function setTitleSuffix(?string $titleSuffix): void
    {
        $this->titleSuffix = $titleSuffix;
    }

    public function getGenderKey(): ?string
    {
        return $this->genderKey;
    }

    public function setGenderKey(?string $genderKey): void
    {
        $this->genderKey = $genderKey;
    }

    public function getPersonTypeKey(): ?string
    {
        return $this->personTypeKey;
    }

    public function setPersonTypeKey(?string $personTypeKey): void
    {
        $this->personTypeKey = $personTypeKey;
    }

    public function getIsStaff(): int
    {
        return $this->isStaff;
    }

    public function setIsStaff(int $isStaff): void
    {
        $this->isStaff = $isStaff;
    }

    public function getIsStudent(): int
    {
        return $this->isStudent;
    }

    public function setIsStudent(int $isStudent): void
    {
        $this->isStudent = $isStudent;
    }

    public function getIsAlumni(): int
    {
        return $this->isAlumni;
    }

    public function setIsAlumni(int $isAlumni): void
    {
        $this->isAlumni = $isAlumni;
    }

    public function getIsExternal(): int
    {
        return $this->isExternal;
    }

    public function setIsExternal(int $isExternal): void
    {
        $this->isExternal = $isExternal;
    }

    public function getLocalDataSourceAttributeValues(): array
    {
        return array_map(function (string $getterMethod) {
            return $this->$getterMethod();
        }, self::LOCAL_DATA_SOURCE_ATTRIBUTES);
    }
}
