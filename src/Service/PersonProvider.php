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

class PersonProvider extends AbstractAuthorizationService implements PersonProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const CURRENT_PERSON_IDENTIFIER_AUTHORIZATION_ATTRIBUTE = 'cpi';

    private const ACCOUNT_STATUS_KEYS_TO_FETCH = [
        AccountsCommon::OK_ACCOUNT_STATUS_KEY,
    ];

    private const STAFF_ACCOUNT_TYPE_KEY = 'STAFF';
    private const STUDENT_ACCOUNT_TYPE_KEY = 'STUDENT';
    private const ALUMNI_ACCOUNT_TYPE_KEY = 'A';

    // TODO: make non-const account type keys configurable via config instead of hardcoding them
    private const ACCOUNT_TYPE_KEYS_TO_FETCH = [
        self::STAFF_ACCOUNT_TYPE_KEY,
        self::STUDENT_ACCOUNT_TYPE_KEY,
        self::ALUMNI_ACCOUNT_TYPE_KEY,
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
        Common::PERSON_GROUPS_CLAIM,
    ];
    private const ALL_CLAIMS = [
        Common::ALL_CLAIM,
    ];

    private const MAX_NUM_PERSON_UIDS_PER_REQUEST = 50;

    private ?Connection $connection = null;
    private ?PersonClaimsApi $personClaimsApi = null;
    private ?UserApi $userApi = null;
    private LocalDataEventDispatcher $eventDispatcher;
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
     * @var array<string, PersonClaimsResource>|null
     */
    private ?array $personClaimsRequestCache = null;

    /**
     * @var array<string, UserResource>|null
     */
    private ?array $usersRequestCache = null;

    /**
     * @var array<string, CachedPerson>
     */
    private array $currentResultCachedPersons = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheItemPoolInterface $campusonlineApiCache,
        EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct();

        if ($this->campusonlineApiCache instanceof NamespacedPoolInterface) {
            $this->campusonlineApiCache->withSubNamespace('DbpCampusonlineApi');
        }

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

    public function setClientHandler(object $stack): void
    {
        $this->getConnection()->setClientHandler($stack);
    }

    public function checkPersonClaimsApi(): void
    {
        $this->getPersonClaimsApi()->getPersonClaimsPageCursorBased(
            claims: self::ALL_CLAIMS,
            maxNumItems: 1);
    }

    public function checkUsersApi(): void
    {
        $this->getUserApi()->getUsersCursorBased(
            queryParameters: [
                UserApi::ACCOUNT_STATUS_KEY_QUERY_PARAMETER_NAME => self::ACCOUNT_STATUS_KEYS_TO_FETCH,
                UserApi::ACCOUNT_TYPE_KEY_QUERY_PARAMETER_NAME => self::ACCOUNT_TYPE_KEYS_TO_FETCH,
            ],
            maxNumItems: 1);
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
        $this->currentResultCachedPersons = [];
        $this->personClaimsRequestCache = null;
        $this->usersRequestCache = null;
    }

    /**
     * @throws \Throwable
     */
    public function recreatePersonsCache(): void
    {
        $connection = $this->entityManager->getConnection();
        try {
            $this->currentlyRecreatingPersonsCache = true;
            $this->cachePersonsWithAccountOnly();

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
                STMT);
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
                    $this->currentPerson, Options::getLocalDataAttributes($options)))) {
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

    public function _getCurrentPerson(array $options = []): ?Person
    {
        if ($this->currentPerson === false
            || ($this->currentPerson !== null
                && false === LocalDataEventDispatcher::areRequestedLocalDataAttributesIdentical(
                    $this->currentPerson, Options::getLocalDataAttributes($options)))) {
            // currentPerson not initialized yet (=== false) or requested local data attributes have changed
            if (null === ($currentPersonIdentifier = $this->getCurrentPersonIdentifierInternal())) {
                // no user identifier available, so we can't fetch a person
                $this->currentPerson = null;
            } else {
                if ($currentCachedPerson = $this->currentResultCachedPersons[$currentPersonIdentifier] ?? null) {
                    dump('creating current person from cached person of current result set');
                    // the current person is part of the current result set (most likely because getCurrentPerson was called before)
                    // -> create person with requested local data attributes from it
                    $this->eventDispatcher->onNewOperation($options);
                    $this->currentPerson = $this->postProcessPerson(
                        self::createPersonAndExtraDataFromCachedPerson($currentCachedPerson, $options),
                        $options
                    );
                } else {
                    try {
                        dump('creating new current person from DB');
                        // probably the first request for current person
                        // -> create person with requested local data attributes from DB cached person
                        $this->currentPerson = $this->getPerson($currentPersonIdentifier, $options);
                    } catch (ApiError $apiError) {
                        if ($apiError->getStatusCode() === Response::HTTP_NOT_FOUND) {
                            $this->currentPerson = null;
                        } else {
                            throw $apiError;
                        }
                    }
                }
            }
        } else {
            dump('re-using current person');
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
     * May only be called during person cache re-creation, i.e. on @see RecreatePersonCachePostEvent.
     *
     * @param int $personGroupMask the person group mask indicating the person group(s) of the persons to add (default: employees))
     *
     * @throws \Throwable
     */
    public function addPersonsToStagingTable(array $personIdentifiers, int $personGroupMask = CachedPerson::EMPLOYEE_PERSON_GROUP_MASK): void
    {
        if (false === $this->currentlyRecreatingPersonsCache) {
            throw new \LogicException('adding employees to staging table is only allowed during person cache recreation');
        }
        try {
            $this->addPersonsToStagingTableInternal(array_fill_keys($personIdentifiers, $personGroupMask), true);
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
        return array_keys($this->currentResultCachedPersons);
    }

    /**
     * Gets all persons of the current result set from the API and caches them locally, so that not every person
     * has to be requested individually on setting local data attributes.
     */
    public function requestCacheCurrentResultPersons(): void
    {
        if ($this->personClaimsRequestCache === null) {
            $this->personClaimsRequestCache = [];

            try {
                $currentPersonIndex = 0;
                while ($currentPersonIndex < count($this->currentResultCachedPersons)) {
                    $resourcePage = $this->getPersonClaimsApi()->getPersonClaimsPageCursorBased(
                        queryParameters: [
                            PersonClaimsApi::PERSON_UID_QUERY_PARAMETER_NAME => array_keys(
                                array_slice(
                                    $this->currentResultCachedPersons,
                                    $currentPersonIndex,
                                    self::MAX_NUM_PERSON_UIDS_PER_REQUEST,
                                    true)
                            ),
                        ],
                        claims: self::ALL_CLAIMS,
                        maxNumItems: self::MAX_NUM_PERSON_UIDS_PER_REQUEST);

                    /** @var PersonClaimsResource $personClaimsResource */
                    foreach ($resourcePage->getResources() as $personClaimsResource) {
                        $this->personClaimsRequestCache[$personClaimsResource->getUid()] = $personClaimsResource;
                    }
                    $currentPersonIndex += self::MAX_NUM_PERSON_UIDS_PER_REQUEST;
                }
            } catch (\Throwable $throwable) {
                throw $this->dispatchException($throwable, 'failed to get persons form CO person claims API');
            }
        }
    }

    public function requestCacheCurrentResultUsers(): void
    {
        if ($this->usersRequestCache === null) {
            $this->usersRequestCache = [];

            try {
                $currentPersonIndex = 0;
                while ($currentPersonIndex < count($this->currentResultCachedPersons)) {
                    foreach ($this->getUsersFromCOApi(
                        queryParameters: [
                            UserApi::PERSON_UID_QUERY_PARAMETER_NAME => array_keys(
                                array_slice(
                                    $this->currentResultCachedPersons,
                                    $currentPersonIndex,
                                    self::MAX_NUM_PERSON_UIDS_PER_REQUEST,
                                    true)
                            ),
                        ])->getResources() as $userResource) {
                        $this->usersRequestCache[$userResource->getPersonUid()] = $userResource;
                    }
                    $currentPersonIndex += self::MAX_NUM_PERSON_UIDS_PER_REQUEST;
                }
            } catch (\Throwable $throwable) {
                throw $this->dispatchException($throwable, 'failed to get users form CO Users API');
            }
        }
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

    /**
     * @return array<string, string>|null
     */
    public function getEmployeeAddress(string $personIdentifier, string $employeeAddressTypeAbbreviation): ?array
    {
        $address = null;
        $personClaims = $this->getPersonFromApiCached($personIdentifier);
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

        return $address;
    }

    public function getPersonFromApiCached(string $personIdentifier): PersonClaimsResource
    {
        if (($personClaimsCached = $this->personClaimsRequestCache[$personIdentifier] ?? null) === null) {
            try {
                $personClaimsCached = $this->getPersonClaimsApi()->getPersonClaimsByPersonUid(
                    $personIdentifier, self::ALL_CLAIMS);
            } catch (\Throwable $throwable) {
                throw $this->dispatchException($throwable, 'failed to get person from CO person claims API');
            }
            $this->personClaimsRequestCache[$personIdentifier] = $personClaimsCached;
        }

        return $personClaimsCached;
    }

    public function getUserFromApiCached(string $personIdentifier): UserResource
    {
        if (($userCached = $this->usersRequestCache[$personIdentifier] ?? null) === null) {
            try {
                $userCached = $this->getUserApi()->getUserByPersonUid($personIdentifier);
                $this->usersRequestCache[$personIdentifier] = $userCached;
            } catch (\Throwable $throwable) {
                throw $this->dispatchException($throwable, 'failed to get user from CO Users API');
            }
        }

        return $userCached;
    }

    public function isCurrentUserAnEmployee(): ?bool
    {
        return ($personGroups = $this->getCurrentCachedPerson()?->getPersonGroups()) ?
            CachedPerson::testIsEmployee($personGroups) : null;
    }

    public function isCurrentUserAStudent(): ?bool
    {
        return ($personGroups = $this->getCurrentCachedPerson()?->getPersonGroups()) ?
            CachedPerson::testIsStudent($personGroups) : null;
    }

    public function isCurrentUserAnAlumni(): ?bool
    {
        return ($personGroups = $this->getCurrentCachedPerson()?->getPersonGroups()) ?
            CachedPerson::testIsAlumni($personGroups) : null;
    }

    private function getCurrentCachedPerson(): ?CachedPerson
    {
        $currentCachedPerson = null;
        if ($currentPersonIdentifier = $this->getCurrentPersonIdentifierInternal()) {
            if (null === ($currentCachedPerson = $this->currentResultCachedPersons[$currentPersonIdentifier] ?? null)) {
                $currentCachedPerson = $this->entityManager->getRepository(CachedPerson::class)
                    ->find($currentPersonIdentifier);
            }
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

    private function getConnection(): Connection
    {
        if ($this->connection === null) {
            $this->connection = new Connection(
                $this->config['base_url'],
                $this->config['client_id'],
                $this->config['client_secret']
            );
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
            $this->currentResultCachedPersons = [];
            /** @var CachedPerson $cachedPerson */
            foreach ($paginator as $cachedPerson) {
                $this->currentResultCachedPersons[$cachedPerson->getUid()] = $cachedPerson;
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
            $maxNumItemsPerPage, $filter, $options);

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
            $personAndExtraData->getPerson(), $personAndExtraData->getExtraData(), $this, $options);
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
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR,
                    'failed to get current person identifier');
            }
        }

        return $this->currentPersonIdentifier;
    }

    private static function createPersonAndExtraDataFromCachedPerson(
        CachedPerson $cachedPerson, array $options): PersonAndExtraData
    {
        $person = new Person();
        $person->setIdentifier($cachedPerson->getUid());
        $person->setGivenName($cachedPerson->getGivenName());
        $person->setFamilyName($cachedPerson->getSurname());

        return new PersonAndExtraData($person, $cachedPerson->getLocalDataSourceAttributeValues());
    }

    private static function createCachedPersonStagingFromPersonClaimsResource(
        PersonClaimsResource $personClaimsResource): CachedPersonStaging
    {
        $cachedPerson = new CachedPersonStaging();
        $cachedPerson->setUid($personClaimsResource->getUid());
        $cachedPerson->setGivenName($personClaimsResource->getGivenName());
        $cachedPerson->setSurname($personClaimsResource->getSurname());
        $cachedPerson->setDateOfBirth($personClaimsResource->getDateOfBirth());
        $cachedPerson->setEmail(self::getMainEmail($personClaimsResource));
        $cachedPerson->setMatriculationNumber($personClaimsResource->getMatriculationNumber());
        $cachedPerson->setGenderKey($personClaimsResource->getGenderKey());
        $cachedPerson->setTitlePrefix($personClaimsResource->getTitlePrefix());
        $cachedPerson->setTitleSuffix($personClaimsResource->getTitleSuffix());
        // NOTE: person groups are set depending on user account info

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

    private function cachePersonsWithAccountOnly(): void
    {
        $nextUsersCursor = null;
        do {
            $userResourcePage = $this->getUsersFromCOApi([], $nextUsersCursor);
            $personsToFetch = [];
            /** @var UserResource $userResource */
            foreach ($userResourcePage->getResources() as $userResource) {
                $personGroupMask = 0;
                for ($accountIndex = 0; $accountIndex < $userResource->getNumAccounts(); ++$accountIndex) {
                    if ($userResource->getAccountStatusKey($accountIndex) === AccountsCommon::OK_ACCOUNT_STATUS_KEY) {
                        $personGroupMask |= match ($userResource->getAccountTypeKey($accountIndex)) {
                            self::STAFF_ACCOUNT_TYPE_KEY => CachedPerson::EMPLOYEE_PERSON_GROUP_MASK,
                            self::STUDENT_ACCOUNT_TYPE_KEY => CachedPerson::STUDENT_PERSON_GROUP_MASK,
                            self::ALUMNI_ACCOUNT_TYPE_KEY => CachedPerson::ALUMNI_PERSON_GROUP_MASK,
                            default => 0,
                        };
                    }
                }
                if ($personGroupMask !== 0) {
                    $personsToFetch[$userResource->getPersonUid()] = $personGroupMask;
                }
            }

            $this->addPersonsToStagingTableInternal($personsToFetch);
        } while (($nextUsersCursor = $userResourcePage->getNextCursor()) !== null);
    }

    /**
     * @param array<string, int> $personsIdentifiersToPersonGroups A mapping from person uid to person group mask
     */
    private function addPersonsToStagingTableInternal(array $personsIdentifiersToPersonGroups, bool $checkIfAlreadyAdded = false): void
    {
        $currentPersonIndex = 0;
        while ($currentPersonIndex < count($personsIdentifiersToPersonGroups)) {
            $personClaimsQueryParameters = [
                PersonClaimsApi::PERSON_UID_QUERY_PARAMETER_NAME => array_keys(
                    array_slice($personsIdentifiersToPersonGroups,
                        $currentPersonIndex, self::MAX_NUM_PERSON_UIDS_PER_REQUEST, true)
                ),
            ];

            /** @var PersonClaimsResource $personClaimsResource */
            foreach ($this->getPersonClaimsApi()->getPersonClaimsPageOffsetBased(
                queryParameters: $personClaimsQueryParameters,
                claims: self::PERSON_CLAIMS_REQUIRED_FOR_CACHE,
                maxNumItems: self::MAX_NUM_PERSON_UIDS_PER_REQUEST) as $personClaimsResource) {
                $cachedPersonStaging = null;
                $personGroupsMask = 0;
                if ($checkIfAlreadyAdded
                    && ($cachedPersonStaging =
                        $this->entityManager->getRepository(CachedPersonStaging::class)->find($personClaimsResource->getUid()))) {
                    $personGroupsMask = $cachedPersonStaging->getPersonGroups();
                }
                $cachedPersonStaging ??= self::createCachedPersonStagingFromPersonClaimsResource($personClaimsResource);
                $personGroupsMask |= $personsIdentifiersToPersonGroups[$personClaimsResource->getUid()]; // set or add groups
                $cachedPersonStaging->setPersonGroups($personGroupsMask);
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
            maxNumItems: 1000);
    }
}
