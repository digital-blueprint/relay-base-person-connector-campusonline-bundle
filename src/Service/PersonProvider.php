<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\Helpers\ApiException;
use Dbp\CampusonlineApi\PublicRestApi\Accounts\Common as AccountsCommon;
use Dbp\CampusonlineApi\PublicRestApi\Accounts\UserApi;
use Dbp\CampusonlineApi\PublicRestApi\Accounts\UserResource;
use Dbp\CampusonlineApi\PublicRestApi\Connection;
use Dbp\CampusonlineApi\PublicRestApi\CursorBasedResourcePage;
use Dbp\CampusonlineApi\PublicRestApi\Persons\Common;
use Dbp\CampusonlineApi\PublicRestApi\Persons\PersonClaimsApi;
use Dbp\CampusonlineApi\PublicRestApi\Persons\PersonClaimsResource;
use Dbp\CampusonlineApi\PublicRestApi\Studies\DegreeProgrammeApi;
use Dbp\CampusonlineApi\PublicRestApi\Studies\DegreeProgrammeResource;
use Dbp\CampusonlineApi\PublicRestApi\Studies\StudiesApi;
use Dbp\CampusonlineApi\PublicRestApi\Studies\StudiesResource;
use Dbp\Relay\BasePersonBundle\API\PersonProviderInterface;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPerson;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPersonStaging;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Event\PersonPostEvent;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Event\PersonPreEvent;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Event\RecreatePersonCachePostEvent;
use Dbp\Relay\CoreBundle\Authorization\AbstractAuthorizationService;
use Dbp\Relay\CoreBundle\Doctrine\QueryHelper;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Helpers\Tools;
use Dbp\Relay\CoreBundle\LocalData\LocalDataEventDispatcher;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Filter;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterException;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTools;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Dbp\Relay\CoreBundle\Rest\Query\Pagination\Pagination;
use Dbp\Relay\CoreBundle\Rest\Query\Sort\SortTools;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Symfony\Contracts\Service\Attribute\Required;

class PersonProvider extends AbstractAuthorizationService implements PersonProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const CURRENT_PERSON_IDENTIFIER_AUTHORIZATION_ATTRIBUTE = 'cpi';
    private const DEFAULT_LANGUAGE_TAG = 'de';

    private const ACCOUNT_STATUS_KEYS_TO_FETCH = [
        AccountsCommon::OK_ACCOUNT_STATUS_KEY,
    ];

    private const STAFF_ACCOUNT_TYPE_KEY = 'STAFF';
    private const STUDENT_ACCOUNT_TYPE_KEY = 'STUDENT';
    private const ALUMNI_ACCOUNT_TYPE_KEY = 'A';
    private const BASEACCOUNT_ACCOUNT_TYPE_KEY = 'BASEACCOUNT';
    // TODO: make non-const account type keys configurable via config instead of hardcoding them
    private const ACCOUNT_TYPE_KEYS_TO_FETCH = [
        self::STAFF_ACCOUNT_TYPE_KEY,
        self::STUDENT_ACCOUNT_TYPE_KEY,
        self::ALUMNI_ACCOUNT_TYPE_KEY,
        self::BASEACCOUNT_ACCOUNT_TYPE_KEY,
    ];

    private const PERSON_CLAIMS_REQUIRED_FOR_CACHE = [
        Common::NAME_CLAIM,
        Common::DATE_OF_BIRTH_CLAIM,
        Common::EMAIL_CLAIM,
        Common::EMAIL_EMPLOYEE_CLAIM,
        Common::EMAIL_STUDENT_CLAIM,
        Common::EMAIL_EXTPERS_CLAIM,
        Common::MATRICULATION_NUMBER_CLAIM,
        Common::GENDER_CLAIM,
        Common::TITLE_CLAIM,
    ];
    private const ALL_CLAIMS = [
        Common::ALL_CLAIM,
    ];

    private const MAX_NUM_PERSON_UIDS_PER_REQUEST = 50;

    private const STUDENT_MASK = 0b00000001;
    private const EMPLOYEE_MASK = 0b00000010;
    private const ALUMNI_MASK = 0b00000100;
    private const EXTERNAL_MASK = 0b00001000;

    private ?Connection $connection = null;
    private ?PersonClaimsApi $personClaimsApi = null;
    private ?UserApi $userApi = null;

    private ?StudiesApi $studiesApi = null;
    private ?DegreeProgrammeApi $degreeProgrammeApi = null;
    private LocalDataEventDispatcher $eventDispatcher;
    private CacheItemPoolInterface $campusonlineApiCache;
    private ?string $currentPersonIdentifier = null;

    /**
     * @var Person|bool|null
     *
     * False means not initialized
     */
    private mixed $currentPerson = false;
    private bool $wasCurrentPersonIdentifierRetrieved = false;
    private bool $currentlyRecreatingPersonsCache = false;

    /**
     * @var array<string, mixed>
     */
    private array $config = [];

    /**
     * @var array<string, ?PersonClaimsResource>|null
     */
    private ?array $personClaimsResourcesRequestCache = null;

    /**
     * @var array<string, ?UserResource>|null
     */
    private ?array $userResourcesRequestCache = null;

    /**
     * @var array<string, array<int, StudiesResource>>|null
     */
    private ?array $studiesRequestCache = null;
    /**
     * @var array<string, DegreeProgrammeResource>
     */
    private array $degreeProgrammeResourcesRequestCache = [];

    /**
     * @var array<string, CachedPerson>
     */
    private array $cachedPersonsRequestCache = [];

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        EventDispatcherInterface $eventDispatcher
    ) {
        parent::__construct();

        $this->eventDispatcher = new LocalDataEventDispatcher('', $eventDispatcher);
    }

    public function setConfig(array $config): void
    {
        $this->config = $config[Configuration::CAMPUS_ONLINE_NODE];

        $attributes = [
            self::CURRENT_PERSON_IDENTIFIER_AUTHORIZATION_ATTRIBUTE => $config[Configuration::CURRENT_PERSON_IDENTIFIER_EXPRESSION_ATTRIBUTE],
        ];
        $this->setUpAccessControlPolicies(attributes: $attributes);
    }

    #[Required]
    public function setCache(CacheItemPoolInterface $cache): void
    {
        $this->campusonlineApiCache = $cache instanceof NamespacedPoolInterface ?
            $cache->withSubNamespace(Connection::CACHE_SUBNAMESPACE) :
            $cache;
        $this->connection?->setCache($this->campusonlineApiCache);
    }

    public function setClientHandler(object $stack): void
    {
        $this->getConnection()->setClientHandler($stack);
    }

    public function checkPersonClaimsApi(): void
    {
        $this->getPersonClaimsApi()->getPersonClaimsCursorBased(
            claims: self::ALL_CLAIMS,
            maxNumItems: 1
        );
    }

    public function checkUsersApi(): void
    {
        $this->getUserApi()->getUsersCursorBased(
            queryParameters: [
                UserApi::ACCOUNT_STATUS_KEY_QUERY_PARAMETER_NAME => self::ACCOUNT_STATUS_KEYS_TO_FETCH,
                UserApi::ACCOUNT_TYPE_KEY_QUERY_PARAMETER_NAME => self::ACCOUNT_TYPE_KEYS_TO_FETCH,
            ],
            maxNumItems: 1
        );
    }

    public function reset(): void
    {
        parent::reset();

        $this->currentPerson = false;
        $this->currentPersonIdentifier = null;
        $this->wasCurrentPersonIdentifierRetrieved = false;
        $this->connection = null;
        $this->personClaimsApi = null;
        $this->userApi = null;
        $this->cachedPersonsRequestCache = [];
        $this->personClaimsResourcesRequestCache = null;
        $this->userResourcesRequestCache = null;
        $this->studiesApi = null;
        $this->degreeProgrammeApi = null;
        $this->studiesRequestCache = null;
        $this->degreeProgrammeResourcesRequestCache = [];
    }

    /**
     * @throws \Throwable
     */
    public function recreatePersonsCache(): void
    {
        $connection = $this->entityManager->getConnection();
        try {
            $this->currentlyRecreatingPersonsCache = true;
            $this->cachePersonsWithAccount();

            $this->eventDispatcher->dispatch(new RecreatePersonCachePostEvent($this->entityManager));

            $personsStagingTableName = CachedPersonStaging::TABLE_NAME;
            $personsLiveTableName = CachedPerson::TABLE_NAME;
            $personsTempTableName = $personsLiveTableName.'_temp';

            // swap live and staging tables:
            $connection->executeStatement(<<<STMT
                RENAME TABLE
                $personsLiveTableName TO $personsTempTableName,
                $personsStagingTableName TO $personsLiveTableName,
                $personsTempTableName TO $personsStagingTableName
                STMT
            );
        } catch (\Throwable $throwable) {
            $this->logger?->error('Error recreating person cache: '.$throwable->getMessage());
            throw $throwable;
        } finally {
            $this->currentlyRecreatingPersonsCache = false;
            $connection->executeStatement('TRUNCATE TABLE '.CachedPersonStaging::TABLE_NAME);
        }
    }

    public function getPerson(string $identifier, array $options = []): Person
    {
        $this->eventDispatcher->onNewOperation($options);

        $preEvent = new PersonPreEvent($options, $identifier, $this);
        $this->eventDispatcher->dispatch($preEvent);

        $identifier = $preEvent->getIdentifier();
        $options = $preEvent->getOptions();

        try {
            $filter = FilterTreeBuilder::create()
                ->equals('identifier', $identifier)
                ->createFilter();
        } catch (FilterException $filterException) {
            $this->logger->error('failed to build filter for person identifier query: '.$filterException->getMessage(), [$filterException]);
            throw new \RuntimeException('failed to build filter for person identifier query');
        }

        $persons = $this->getPersonsInternal(1, 2, $filter, $options);

        $numPersons = count($persons);
        if ($numPersons === 0) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, sprintf("person with identifier '%s' could not be found!", $identifier));
        } elseif ($numPersons > 1) {
            throw new \RuntimeException(sprintf("multiple persons found for identifier '%s'", $identifier));
        }

        return $persons[0];
    }

    public function getPersons(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $this->eventDispatcher->onNewOperation($options);

        $preEvent = new PersonPreEvent($options, null, $this);
        $this->eventDispatcher->dispatch($preEvent);
        $options = $preEvent->getOptions();

        $filter = null;
        if ($searchOption = $options[Person::SEARCH_PARAMETER_NAME] ?? null) {
            try {
                // the full name MUST contain ALL search terms
                $filterTreeBuilder = FilterTreeBuilder::create();
                foreach (explode(' ', $searchOption) as $searchTerm) {
                    $searchTerm = trim($searchTerm);
                    $filterTreeBuilder
                        ->or()
                        ->iContains('givenName', $searchTerm)
                        ->iContains('familyName', $searchTerm)
                        ->end();
                }
                $filter = $filterTreeBuilder->createFilter();
                unset($options[Person::SEARCH_PARAMETER_NAME]);
            } catch (FilterException $filterException) {
                $this->logger->error('failed to build filter for person search parameter: '.$filterException->getMessage(), [$filterException]);
                throw new \RuntimeException('failed to build filter for person search parameter');
            }
        }

        return $this->getPersonsInternal($currentPageNumber, $maxNumItemsPerPage, $filter, $options);
    }

    public function getCurrentPerson(array $options = []): ?Person
    {
        if ($this->currentPerson === false
            || ($this->currentPerson !== null
                && false === LocalDataEventDispatcher::areRequestedLocalDataAttributesIdentical(
                    $this->currentPerson,
                    Options::getLocalDataAttributes($options)
                ))) {
            // currentPerson not initialized yet (=== false) or requested local data attributes have changed
            if ($currentCachedPerson = $this->getCurrentCachedPerson()) {
                $this->eventDispatcher->onNewOperation($options);
                $this->currentPerson = $this->postProcessPerson(
                    self::createPersonAndExtraDataFromCachedPerson($currentCachedPerson, $options),
                    $options
                );
            } else {
                $this->currentPerson = null;
            }
        }

        return $this->currentPerson;
    }

    public function getCurrentPersonIdentifier(): ?string
    {
        return $this->getCurrentPersonIdentifierInternal();
    }

    public function getPersonIdentifierByUsername(string $username): ?string
    {
        try {
            /** @var ?UserResource $userResource */
            $userResource = iterator_to_array($this->getUsersFromCOApi(['username' => $username])->getResources())[0] ?? null;
        } catch (\Throwable $throwable) {
            throw $this->dispatchException($throwable, 'failed to get users form CO Users API');
        }

        return $userResource?->getPersonUid();
    }

    public function getPersonIdentifierByEmail(string $email): ?string
    {
        try {
            /** @var ?UserResource $userResource */
            $userResource = iterator_to_array($this->getUsersFromCOApi(['email' => $email])->getResources())[0] ?? null;
        } catch (\Throwable $throwable) {
            throw $this->dispatchException($throwable, 'failed to get users form CO Users API');
        }

        return $userResource?->getPersonUid();
    }

    /**
     * May only be called during person cache re-creation, i.e. on @throws \Throwable.
     *
     * @see RecreatePersonCachePostEvent.
     */
    public function addPersonsToStagingTable(
        array $personIdentifiers,
        bool $areAllEmployees = false,
        bool $areAllStudents = false,
        bool $areAllAlumni = false,
        bool $areAllExternals = false
    ): void {
        if (false === $this->currentlyRecreatingPersonsCache) {
            throw new \LogicException('adding employees to staging table is only allowed during person cache recreation');
        }

        $personGroupMask = 0;
        if ($areAllEmployees) {
            $personGroupMask |= self::EMPLOYEE_MASK;
        }
        if ($areAllStudents) {
            $personGroupMask |= self::STUDENT_MASK;
        }
        if ($areAllAlumni) {
            $personGroupMask |= self::ALUMNI_MASK;
        }
        if ($areAllExternals) {
            $personGroupMask |= self::EXTERNAL_MASK;
        }

        try {
            $this->addPersonsToStagingTableInternal(array_fill_keys($personIdentifiers, $personGroupMask), false);
        } catch (\Throwable $throwable) {
            throw $this->dispatchException($throwable, 'Error adding persons to staging table');
        }
    }

    //    /**
    //     * This can't be used to determine employment relationships, since we get
    //     * - inactive organizations
    //     * - organizations where persons have other functions than employee.
    //     *
    //     * @return string[]
    //     */
    //    public function getPersonIdentifiersByOrganization(string $organizationIdentifier): array
    //    {
    //        // NOTE: the CO person-organisations operation needs deduplication.
    //        $personFunctionsApi = new PersonOrganisationApi($this->getConnection());
    //        $nextCursor = null;
    //        $personIdentifiers = [];
    //        do {
    //            $resourcePage = $personFunctionsApi->getPersonOrganisationsCursorBased(
    //                queryParameters: [
    //                    PersonOrganisationApi::ORGANISATION_UID_QUERY_PARAMETER_NAME => $organizationIdentifier,
    //                ],
    //                cursor: $nextCursor,
    //                maxNumItems: 1000);
    //
    //            /** @var PersonOrganisationResource $personOrganisationResource */
    //            foreach ($resourcePage->getResources() as $personOrganisationResource) {
    //                $personIdentifiers[$personOrganisationResource->getPersonUid()] = null; // deduplicate via array keys
    //            }
    //        } while (($nextCursor = $resourcePage->getNextCursor()) !== null);
    //
    //        return array_keys($personIdentifiers);
    //    }

    //    /**
    //     * This can't be used to determine employment relationships, since we get
    //     * - inactive organizations
    //     * - organizations where persons have other functions than employee.
    //     *
    //     * @return string[]
    //     */
    //    public function getOrganizationIdentifiersByPerson(?string $personIdentifier): array
    //    {
    //        // NOTE: the CO person-organisations operation needs deduplication.
    //        $personFunctionsApi = new PersonOrganisationApi($this->getConnection());
    //        $nextCursor = null;
    //        $organizationIdentifiers = [];
    //        do {
    //            $resourcePage = $personFunctionsApi->getPersonOrganisationsCursorBased(
    //                queryParameters: [
    //                    PersonOrganisationApi::PERSON_UID_QUERY_PARAMETER_NAME => $personIdentifier,
    //                ],
    //                cursor: $nextCursor,
    //                maxNumItems: 1000);
    //
    //            /** @var PersonOrganisationResource $personOrganisationResource */
    //            foreach ($resourcePage->getResources() as $personOrganisationResource) {
    //                $organizationIdentifiers[$personOrganisationResource->getOrganisationUid()] = null; // deduplicate via array keys
    //            }
    //        } while (($nextCursor = $resourcePage->getNextCursor()) !== null);
    //
    //        return array_keys($organizationIdentifiers);
    //    }

    /**
     * @return string[]
     */
    public function getCurrentResultPersonIdentifiers(): array
    {
        return array_keys($this->cachedPersonsRequestCache);
    }

    /**
     * @return array<string, string>|null
     */
    public function getEmployeePostalAddress(?string $personIdentifier): ?array
    {
        return $this->getEmployeeAddress($personIdentifier, 'PA');
    }

    /**
     * @return array<string, string>|null
     */
    public function getEmployeeWorkAddress(string $personIdentifier): ?array
    {
        return $this->getEmployeeAddress($personIdentifier, 'DO');
    }

    public function getPersonClaimsResourceFromApiCached(string $personIdentifier): ?PersonClaimsResource
    {
        $this->requestCachePersonClaimsResources();
        if (false === array_key_exists($personIdentifier, $this->personClaimsResourcesRequestCache)) {
            $personClaimsResource = null;
            try {
                $personClaimsResource = $this->getPersonClaimsApi()->getPersonClaimsByPersonUid(
                    $personIdentifier,
                    self::ALL_CLAIMS
                );
            } catch (\Throwable $throwable) {
                if (false === $throwable instanceof ApiException
                    || false === $throwable->isHttpResponseCodeNotFound()) {
                    throw $this->dispatchException($throwable, 'failed to get person from CO person claims API');
                }
            }
            $this->personClaimsResourcesRequestCache[$personIdentifier] = $personClaimsResource;
        }

        return $this->personClaimsResourcesRequestCache[$personIdentifier];
    }

    public function getUserResourceFromApiCached(string $personIdentifier): ?UserResource
    {
        $this->requestCacheUserResources();
        if (false === array_key_exists($personIdentifier, $this->userResourcesRequestCache)) {
            $userResource = null;
            try {
                $userResource = $this->getUserApi()->getUserByPersonUid($personIdentifier);
            } catch (\Throwable $throwable) {
                if (false === $throwable instanceof ApiException
                    || false === $throwable->isHttpResponseCodeNotFound()) {
                    throw $this->dispatchException($throwable, 'failed to get user from CO Users API');
                }
            }
            $this->userResourcesRequestCache[$personIdentifier] = $userResource;
        }

        return $this->userResourcesRequestCache[$personIdentifier];
    }

    /**
     * @return array<int, array{key: string|null, name: string|null}>
     */
    public function getStudiesFromApiCached(string $personIdentifier, array $options = []): array
    {
        $this->requestCacheStudies();

        return array_map(
            fn (StudiesResource $studyResource): array => $this->createStudyArray($studyResource, $options),
            $this->studiesRequestCache[$personIdentifier] ?? []
        );
    }

    public function isCurrentUserAnEmployee(): ?int
    {
        return $this->getCurrentCachedPerson()?->getIsStaff();
    }

    public function isCurrentUserAStudent(): ?int
    {
        return $this->getCurrentCachedPerson()?->getIsStudent();
    }

    public function isCurrentUserAnAlumni(): ?int
    {
        return $this->getCurrentCachedPerson()?->getIsAlumni();
    }

    public function isCurrentUserExternal(): ?int
    {
        return $this->getCurrentCachedPerson()?->getIsExternal();
    }

    /**
     * Gets all PersonClaimsResource objects corresponding to the current result set from the API and caches them locally,
     * so that not every person has to be requested individually on setting local data attributes.
     */
    private function requestCachePersonClaimsResources(): array
    {
        if ($this->personClaimsResourcesRequestCache === null) {
            $this->personClaimsResourcesRequestCache = array_fill_keys(array_keys($this->cachedPersonsRequestCache), null);

            try {
                $currentPersonIndex = 0;
                while ($currentPersonIndex < count($this->cachedPersonsRequestCache)) {
                    $resourcePage = $this->getPersonClaimsApi()->getPersonClaimsCursorBased(
                        queryParameters: [
                            PersonClaimsApi::PERSON_UID_QUERY_PARAMETER_NAME => array_keys(
                                array_slice(
                                    $this->cachedPersonsRequestCache,
                                    $currentPersonIndex,
                                    self::MAX_NUM_PERSON_UIDS_PER_REQUEST,
                                    true
                                )
                            ),
                        ],
                        claims: self::ALL_CLAIMS,
                        maxNumItems: self::MAX_NUM_PERSON_UIDS_PER_REQUEST
                    );

                    /** @var PersonClaimsResource $personClaimsResource */
                    foreach ($resourcePage->getResources() as $personClaimsResource) {
                        $this->personClaimsResourcesRequestCache[$personClaimsResource->getUid()] = $personClaimsResource;
                    }
                    $currentPersonIndex += self::MAX_NUM_PERSON_UIDS_PER_REQUEST;
                }
            } catch (\Throwable $throwable) {
                throw $this->dispatchException($throwable, 'failed to get persons form CO person claims API');
            }
        }

        return $this->personClaimsResourcesRequestCache;
    }

    /**
     * Gets all UserResource objects corresponding to the current result set from the API and caches them locally,
     * so that not every person has to be requested individually on setting local data attributes.
     */
    private function requestCacheUserResources(): array
    {
        if ($this->userResourcesRequestCache === null) {
            $this->userResourcesRequestCache = array_fill_keys(array_keys($this->cachedPersonsRequestCache), null);
            try {
                $currentPersonIndex = 0;
                while ($currentPersonIndex < count($this->cachedPersonsRequestCache)) {
                    foreach ($this->getUsersFromCOApi(
                        queryParameters: [
                            UserApi::PERSON_UID_QUERY_PARAMETER_NAME => array_keys(
                                array_slice(
                                    $this->cachedPersonsRequestCache,
                                    $currentPersonIndex,
                                    self::MAX_NUM_PERSON_UIDS_PER_REQUEST,
                                    true
                                )
                            ),
                        ]
                    )->getResources() as $userResource) {
                        $this->userResourcesRequestCache[$userResource->getPersonUid()] = $userResource;
                    }
                    $currentPersonIndex += self::MAX_NUM_PERSON_UIDS_PER_REQUEST;
                }
            } catch (\Throwable $throwable) {
                throw $this->dispatchException($throwable, 'failed to get users form CO Users API');
            }
        }

        return $this->userResourcesRequestCache;
    }

    /**
     * Gets all study data corresponding to the current result set from the API and caches it locally
     * for the duration of the current request.
     */
    private function requestCacheStudies(): void
    {
        if ($this->studiesRequestCache !== null) {
            return;
        }

        $this->studiesRequestCache = array_fill_keys(array_keys($this->cachedPersonsRequestCache), []);

        try {
            $studies = [];
            $degreeProgrammeUids = [];

            $currentPersonIndex = 0;
            while ($currentPersonIndex < count($this->cachedPersonsRequestCache)) {
                $personUids = array_keys(
                    array_slice(
                        $this->cachedPersonsRequestCache,
                        $currentPersonIndex,
                        self::MAX_NUM_PERSON_UIDS_PER_REQUEST,
                        true
                    )
                );

                /** @var StudiesResource $studyResource */
                foreach ($this->getStudiesApi()->getStudiesByPersonUids($personUids) as $studyResource) {
                    $studies[] = $studyResource;

                    if ($degreeProgrammeUid = $studyResource->getDegreeProgrammeUid()) {
                        $degreeProgrammeUids[$degreeProgrammeUid] = null;
                    }
                }

                $currentPersonIndex += self::MAX_NUM_PERSON_UIDS_PER_REQUEST;
            }

            $this->requestCacheDegreeProgrammes(array_keys($degreeProgrammeUids));

            foreach ($studies as $studyResource) {
                $personUid = $studyResource->getPersonUid();

                if ($personUid === null) {
                    continue;
                }

                $this->studiesRequestCache[$personUid][] = $studyResource;
            }
        } catch (\Throwable $throwable) {
            throw $this->dispatchException($throwable, 'failed to get studies from CO Study API');
        }
    }

    /**
     * @param string[] $degreeProgrammeUids
     */
    private function requestCacheDegreeProgrammes(array $degreeProgrammeUids): void
    {
        $degreeProgrammeUids = array_values(array_unique(array_filter($degreeProgrammeUids)));

        if ($degreeProgrammeUids === []) {
            return;
        }

        $missingDegreeProgrammeUids = array_values(array_filter(
            $degreeProgrammeUids,
            fn (string $degreeProgrammeUid): bool => false === array_key_exists(
                $degreeProgrammeUid,
                $this->degreeProgrammeResourcesRequestCache
            )
        ));

        if ($missingDegreeProgrammeUids === []) {
            return;
        }

        /** @var DegreeProgrammeResource $degreeProgrammeResource */
        foreach ($this->getDegreeProgrammeApi()->getDegreeProgrammesByDegreeProgrammeUids($missingDegreeProgrammeUids) as $degreeProgrammeResource) {
            if ($degreeProgrammeUid = $degreeProgrammeResource->getUid()) {
                $this->degreeProgrammeResourcesRequestCache[$degreeProgrammeUid] = $degreeProgrammeResource;
            }
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function getEmployeeAddress(string $personIdentifier, string $employeeAddressTypeAbbreviation): ?array
    {
        $address = null;
        $personClaims = $this->getPersonClaimsResourceFromApiCached($personIdentifier);
        if ($personClaims !== null) {
            for ($addressIndex = 0; $addressIndex < $personClaims->getNumAddresses(); ++$addressIndex) {
                if ($personClaims->getEmployeeAddressTypeAbbreviation($addressIndex) === $employeeAddressTypeAbbreviation) {
                    $address = Tools::createAddressArray(
                        street: $personClaims->getAddressStreet($addressIndex),
                        postalCode: $personClaims->getAddressPostalCode($addressIndex),
                        city: $personClaims->getAddressCity($addressIndex),
                        country: $personClaims->getAddressCountry($addressIndex),
                        additionalInformation: $personClaims->getAdditionalAddressInfo($addressIndex)
                    );
                    if ($addressTypeKey = $personClaims->getEmployeeAddressTypeAbbreviation($addressIndex)) {
                        $address['addressTypeKey'] = $addressTypeKey;
                    }
                    if ($roomIdentifier = $personClaims->getRoomUid($addressIndex)) {
                        $address['roomIdentifier'] = (string) $roomIdentifier;
                    }
                    if ($contactOrganizationIdentifier = $personClaims->getContactOrgUid($addressIndex)) {
                        $address['contactOrganizationIdentifier'] = (string) $contactOrganizationIdentifier;
                    }

                    break;
                }
            }
        }

        return $address;
    }

    private function getCurrentCachedPerson(): ?CachedPerson
    {
        $currentCachedPerson = null;
        if ($currentPersonIdentifier = $this->getCurrentPersonIdentifierInternal()) {
            if (false === array_key_exists($currentPersonIdentifier, $this->cachedPersonsRequestCache)) {
                $this->cachedPersonsRequestCache[$currentPersonIdentifier] =
                    $this->entityManager->getRepository(CachedPerson::class)
                        ->find($currentPersonIdentifier);
            }
            $currentCachedPerson = $this->cachedPersonsRequestCache[$currentPersonIdentifier];
        }

        return $currentCachedPerson;
    }

    private function getPersonClaimsApi(): PersonClaimsApi
    {
        if ($this->personClaimsApi === null) {
            $this->personClaimsApi = new PersonClaimsApi($this->getConnection());
        }

        return $this->personClaimsApi;
    }

    private function getUserApi(): UserApi
    {
        if ($this->userApi === null) {
            $this->userApi = new UserApi($this->getConnection());
        }

        return $this->userApi;
    }

    private function getStudiesApi(): StudiesApi
    {
        if ($this->studiesApi === null) {
            $this->studiesApi = new StudiesApi($this->getConnection());
        }

        return $this->studiesApi;
    }

    private function getDegreeProgrammeApi(): DegreeProgrammeApi
    {
        if ($this->degreeProgrammeApi === null) {
            $this->degreeProgrammeApi = new DegreeProgrammeApi($this->getConnection());
        }

        return $this->degreeProgrammeApi;
    }

    private function getConnection(): Connection
    {
        if ($this->connection === null) {
            $this->connection = new Connection(
                $this->config['base_url'],
                $this->config['client_id'],
                $this->config['client_secret']
            );
            $this->connection->setCache($this->campusonlineApiCache);
            $this->connection->setLogger($this->logger);
        }

        return $this->connection;
    }

    /**
     * @return PersonAndExtraData[]
     */
    private function getPersonsFromCache(int $firstItemIndex, int $maxNumItems, ?Filter $filter, array $options = []): array
    {
        $CACHED_PERSON_ENTITY_ALIAS = 'p';

        try {
            $combinedFilter = FilterTreeBuilder::create()->createFilter();
            if ($filter) {
                $combinedFilter->combineWith($filter);
            }
            if ($filterFromOptions = Options::getFilter($options)) {
                $combinedFilter->combineWith($filterFromOptions);
            }

            $queryBuilder = $this->entityManager->createQueryBuilder();
            $queryBuilder
                ->select($CACHED_PERSON_ENTITY_ALIAS)
                ->from(CachedPerson::class, $CACHED_PERSON_ENTITY_ALIAS);

            // NOTE: local data attribute path mapping is done by the PersonEventSubscriber
            $pathMapping = CachedPerson::BASE_ENTITY_ATTRIBUTE_MAPPING;

            if (false === $combinedFilter->isEmpty()) {
                FilterTools::mapConditionPaths($combinedFilter, $pathMapping);
                QueryHelper::addFilter($queryBuilder, $combinedFilter, $CACHED_PERSON_ENTITY_ALIAS);
            }

            if (null !== ($sort = Options::getSort($options))) {
                SortTools::mapSortPaths($sort, $pathMapping);
                QueryHelper::addSort($queryBuilder, $sort, $CACHED_PERSON_ENTITY_ALIAS);
            }

            $paginator = new Paginator($queryBuilder->getQuery());
            $paginator->getQuery()
                ->setFirstResult($firstItemIndex)
                ->setMaxResults($maxNumItems);

            $personAndExtraDataPage = [];
            /** @var CachedPerson $cachedPerson */
            foreach ($paginator as $cachedPerson) {
                $this->cachedPersonsRequestCache[$cachedPerson->getUid()] = $cachedPerson;
                $personAndExtraDataPage[] = self::createPersonAndExtraDataFromCachedPerson($cachedPerson, $options);
            }

            return $personAndExtraDataPage;
        } catch (\Throwable $throwable) {
            throw $this->dispatchException($throwable, 'failed to get persons from cache');
        }
    }

    /**
     * @return Person[]
     */
    private function getPersonsInternal(int $currentPageNumber, int $maxNumItemsPerPage, ?Filter $filter, array $options): array
    {
        $personAndExtraDataPage = $this->getPersonsFromCache(
            Pagination::getFirstItemIndex($currentPageNumber, $maxNumItemsPerPage),
            $maxNumItemsPerPage,
            $filter,
            $options
        );

        // NOTE: post-processing is done after all persons of been collected, so that API requests for additional data (e.g. person claims)
        // can be optimized by caching results for the whole page instead of doing individual requests for each person during post-processing
        return array_map(
            fn (PersonAndExtraData $personAndExtraData) => $this->postProcessPerson($personAndExtraData, $options),
            $personAndExtraDataPage
        );
    }

    private function postProcessPerson(PersonAndExtraData $personAndExtraData, array $options): Person
    {
        $postEvent = new PersonPostEvent(
            $personAndExtraData->getPerson(),
            $personAndExtraData->getExtraData(),
            $this,
            $options
        );
        $this->eventDispatcher->dispatch($postEvent);

        return $personAndExtraData->getPerson();
    }

    /**
     * @throws ApiError
     */
    private function getCurrentPersonIdentifierInternal(): ?string
    {
        if (false === $this->wasCurrentPersonIdentifierRetrieved) {
            try {
                $this->currentPersonIdentifier = $this->getAttribute(self::CURRENT_PERSON_IDENTIFIER_AUTHORIZATION_ATTRIBUTE);
                $this->wasCurrentPersonIdentifierRetrieved = true;
            } catch (\Exception $exception) {
                $this->logger->error('failed to get current person identifier: '.$exception->getMessage());
                throw ApiError::withDetails(
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    'failed to get current person identifier'
                );
            }
        }

        return $this->currentPersonIdentifier;
    }

    private static function createPersonAndExtraDataFromCachedPerson(
        CachedPerson $cachedPerson,
        array $options
    ): PersonAndExtraData {
        $person = new Person();
        $person->setIdentifier($cachedPerson->getUid());
        $person->setGivenName($cachedPerson->getGivenName());
        $person->setFamilyName($cachedPerson->getSurname());

        return new PersonAndExtraData($person, $cachedPerson->getLocalDataSourceAttributeValues());
    }

    private static function createCachedPersonStagingFromPersonClaimsResource(
        PersonClaimsResource $personClaimsResource
    ): CachedPersonStaging {
        $cachedPerson = new CachedPersonStaging();
        $cachedPerson->setUid($personClaimsResource->getUid());
        $cachedPerson->setGivenName($personClaimsResource->getGivenName());
        $cachedPerson->setSurname($personClaimsResource->getSurname());
        $cachedPerson->setDateOfBirth($personClaimsResource->getDateOfBirth());
        $cachedPerson->setEmail(self::getMainEmail($personClaimsResource));
        $cachedPerson->setMatriculationNumber($personClaimsResource->getMatriculationNumber());
        $cachedPerson->setGenderKey($personClaimsResource->getGenderKey());
        $cachedPerson->setPersonTypeKey($personClaimsResource->getPersonTypeKey());
        $cachedPerson->setTitlePrefix($personClaimsResource->getTitlePrefix());
        $cachedPerson->setTitleSuffix($personClaimsResource->getTitleSuffix());
        // NOTE: account types are set depending on user account info

        return $cachedPerson;
    }

    private static function getMainEmail(PersonClaimsResource $personClaimsResource): ?string
    {
        if ($email = $personClaimsResource->getEmail()) {
            return $email;
        }
        if ($email = $personClaimsResource->getEmailEmployee()) {
            return $email;
        }
        if ($email = $personClaimsResource->getEmailStudent()) {
            return $email;
        }
        if ($email = $personClaimsResource->getEmailExtpers()) {
            return $email;
        }

        return null;
    }

    /**
     * @return array{key: string|null, name: string|null}
     */
    private function createStudyArray(StudiesResource $studyResource, array $options = []): array
    {
        $degreeProgrammeResource = null;

        if ($degreeProgrammeUid = $studyResource->getDegreeProgrammeUid()) {
            $degreeProgrammeResource = $this->degreeProgrammeResourcesRequestCache[$degreeProgrammeUid] ?? null;
        }

        return [
            'key' => $degreeProgrammeResource?->getIdentifier(),
            'name' => $degreeProgrammeResource !== null ? $this->buildDegreeProgrammeName($degreeProgrammeResource, $options) : null,
        ];
    }

    private function buildDegreeProgrammeName(DegreeProgrammeResource $degreeProgrammeResource, array $options = []): ?string
    {
        $language = Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG;
        $partialDegreeProgrammes = $degreeProgrammeResource->getPartialDegreeProgrammes();

        if ($partialDegreeProgrammes === []) {
            return self::getLocalizedName($degreeProgrammeResource->getSubjectName(), $language);
        }

        $identifierCodes = self::extractCodesFromDegreeProgrammeIdentifier($degreeProgrammeResource->getIdentifier());

        if ($identifierCodes === []) {
            return self::getLocalizedName($degreeProgrammeResource->getSubjectName(), $language);
        }

        $partialDegreeProgrammesBySubjectCode = [];

        foreach ($partialDegreeProgrammes as $partialDegreeProgramme) {
            $subjectCode = $partialDegreeProgramme->getSubjectCode();

            if ($subjectCode === null) {
                continue;
            }

            $partialDegreeProgrammesBySubjectCode[$subjectCode] = $partialDegreeProgramme;
        }

        $names = [];

        foreach ($identifierCodes as $identifierCode) {
            $partialDegreeProgramme = $partialDegreeProgrammesBySubjectCode[$identifierCode] ?? null;

            if ($partialDegreeProgramme === null) {
                continue;
            }

            $name = self::getLocalizedName($partialDegreeProgramme->getSubjectName(), $language);

            if ($name === null) {
                continue;
            }

            $names[] = $name;
        }

        if ($names === []) {
            return self::getLocalizedName($degreeProgrammeResource->getSubjectName(), $language);
        }

        return implode('; ', $names);
    }

    /**
     * @param array<string, string>|null $namesByLanguage
     */
    private static function getLocalizedName(?array $namesByLanguage, string $language): ?string
    {
        return $namesByLanguage[$language]
            ?? $namesByLanguage[self::DEFAULT_LANGUAGE_TAG]
            ?? null;
    }

    /**
     * @return string[]
     */
    private static function extractCodesFromDegreeProgrammeIdentifier(?string $identifier): array
    {
        if ($identifier === null) {
            return [];
        }

        $parts = preg_split('/\s+/', trim($identifier));

        if ($parts === false) {
            return [];
        }

        return array_values(array_filter(
            $parts,
            static fn (string $part): bool => preg_match('/^\d+$/', $part) === 1
        ));
    }

    /**
     * NOTE: Campusonline returns '401 unauthorized' for some resources that are not found. So we can't
     * safely return '404' in all cases because '401' is also returned by CO if e.g. the token is not valid.
     *
     * @throws ApiError
     */
    private function dispatchException(\Throwable $throwable, ?string $logMessage = null): ApiError
    {
        $apiError = null;
        if ($throwable instanceof ApiException) {
            if ($throwable->isHttpResponseCode()) {
                if ($throwable->getCode() === Response::HTTP_NOT_FOUND) {
                    $apiError = new ApiError(Response::HTTP_NOT_FOUND, sprintf('person could not be found'));
                    $logMessage = null; // don't log 404s
                } elseif ($throwable->getCode() >= 500) {
                    $apiError = new ApiError(Response::HTTP_BAD_GATEWAY, 'failed to get persons or users from Campusonline');
                }
            }
        }
        if (null !== $logMessage) {
            $this->logger->error($logMessage.': '.$throwable->getMessage(), [$throwable]);
        }

        return $apiError ?? new ApiError(Response::HTTP_INTERNAL_SERVER_ERROR, 'failed to get person(s)');
    }

    private function cachePersonsWithAccount(): void
    {
        $nextUsersCursor = null;
        do {
            $userResourcePage = $this->getUsersFromCOApi([], $nextUsersCursor);
            $personAccountTypes = [];
            /** @var UserResource $userResource */
            foreach ($userResourcePage->getResources() as $userResource) {
                $accountTypes = 0;
                for ($accountIndex = 0; $accountIndex < $userResource->getNumAccounts(); ++$accountIndex) {
                    if ($userResource->getAccountStatusKey($accountIndex) === AccountsCommon::OK_ACCOUNT_STATUS_KEY) {
                        $accountTypes |= match ($userResource->getAccountTypeKey($accountIndex)) {
                            self::STAFF_ACCOUNT_TYPE_KEY => self::EMPLOYEE_MASK,
                            self::STUDENT_ACCOUNT_TYPE_KEY => self::STUDENT_MASK,
                            self::ALUMNI_ACCOUNT_TYPE_KEY => self::ALUMNI_MASK,
                            self::BASEACCOUNT_ACCOUNT_TYPE_KEY => self::EXTERNAL_MASK,
                            default => 0,
                        };
                    }
                }
                if ($accountTypes !== 0) {
                    $personAccountTypes[$userResource->getPersonUid()] = $accountTypes;
                }
            }

            $this->addPersonsToStagingTableInternal($personAccountTypes, true);
        } while (($nextUsersCursor = $userResourcePage->getNextCursor()) !== null);
    }

    /**
     * @param array<string, int> $personIdToPersonGroupsMaskMap A mapping from person uid to the person's account types
     */
    private function addPersonsToStagingTableInternal(array $personIdToPersonGroupsMaskMap, bool $haveAccounts): void
    {
        $currentPersonIndex = 0;
        while ($currentPersonIndex < count($personIdToPersonGroupsMaskMap)) {
            $personClaimsQueryParameters = [
                PersonClaimsApi::PERSON_UID_QUERY_PARAMETER_NAME => array_keys(
                    array_slice(
                        $personIdToPersonGroupsMaskMap,
                        $currentPersonIndex,
                        self::MAX_NUM_PERSON_UIDS_PER_REQUEST,
                        true
                    )
                ),
            ];

            /** @var PersonClaimsResource $personClaimsResource */
            foreach ($this->getPersonClaimsApi()->getPersonClaimsOffsetBased(
                queryParameters: $personClaimsQueryParameters,
                claims: self::PERSON_CLAIMS_REQUIRED_FOR_CACHE,
                maxNumItems: self::MAX_NUM_PERSON_UIDS_PER_REQUEST
            ) as $personClaimsResource) {
                $cachedPersonStaging = null;
                // if persons are injected from outside, we check, if they have already been added (because they have accounts)
                if ($haveAccounts === false) {
                    $cachedPersonStaging =
                        $this->entityManager->getRepository(CachedPersonStaging::class)->find($personClaimsResource->getUid());
                }
                $cachedPersonStaging ??= self::createCachedPersonStagingFromPersonClaimsResource($personClaimsResource);
                $personGroups = $personIdToPersonGroupsMaskMap[$personClaimsResource->getUid()];
                // set or add person groups (and don't remove when persons has been added before)
                if ($personGroups & self::EMPLOYEE_MASK
                    && $cachedPersonStaging->getIsStaff() !== CachedPersonStaging::YES_WITH_ACCOUNT) {
                    $cachedPersonStaging->setIsStaff($haveAccounts ?
                        CachedPersonStaging::YES_WITH_ACCOUNT : CachedPersonStaging::YES_WITHOUT_ACCOUNT);
                }
                if ($personGroups & self::STUDENT_MASK
                    && $cachedPersonStaging->getIsStudent() !== CachedPersonStaging::YES_WITH_ACCOUNT) {
                    $cachedPersonStaging->setIsStudent($haveAccounts ?
                        CachedPersonStaging::YES_WITH_ACCOUNT : CachedPersonStaging::YES_WITHOUT_ACCOUNT);
                }
                if ($personGroups & self::ALUMNI_MASK
                    && $cachedPersonStaging->getIsAlumni() !== CachedPersonStaging::YES_WITH_ACCOUNT) {
                    $cachedPersonStaging->setIsAlumni($haveAccounts ?
                        CachedPersonStaging::YES_WITH_ACCOUNT : CachedPersonStaging::YES_WITHOUT_ACCOUNT);
                }
                if ($personGroups & self::EXTERNAL_MASK
                    && $cachedPersonStaging->getIsExternal() !== CachedPersonStaging::YES_WITH_ACCOUNT) {
                    $cachedPersonStaging->setIsExternal($haveAccounts ?
                        CachedPersonStaging::YES_WITH_ACCOUNT : CachedPersonStaging::YES_WITHOUT_ACCOUNT);
                }
                $this->entityManager->persist($cachedPersonStaging);
            }

            $currentPersonIndex += self::MAX_NUM_PERSON_UIDS_PER_REQUEST;
            $this->entityManager->flush();
            $this->entityManager->clear();
        }
    }

    private function getUsersFromCOApi(array $queryParameters, ?string $nextCursor = null): CursorBasedResourcePage
    {
        $queryParameters[UserApi::ACCOUNT_STATUS_KEY_QUERY_PARAMETER_NAME] ??= self::ACCOUNT_STATUS_KEYS_TO_FETCH;
        $queryParameters[UserApi::ACCOUNT_TYPE_KEY_QUERY_PARAMETER_NAME] ??= self::ACCOUNT_TYPE_KEYS_TO_FETCH;

        return $this->getUserApi()->getUsersCursorBased(
            queryParameters: $queryParameters,
            cursor: $nextCursor,
            maxNumItems: 1000
        );
    }
}
