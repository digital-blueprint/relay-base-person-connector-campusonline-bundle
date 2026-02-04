<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\EventSubscriber;

use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Event\PersonPostEvent;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Event\PersonPreEvent;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service\PersonProvider;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataEventSubscriber;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPostEvent;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPreEvent;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\ConditionNode;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\OperatorType;

class PersonEventSubscriber extends AbstractLocalDataEventSubscriber
{
    public const STAFF_AT_LOCAL_DATA_SOURCE_ATTRIBUTE = 'staffAt';
    public const EMPLOYEE_POSTAL_ADDRESS_SOURCE_ATTRIBUTE = 'employeePostalAddress';
    public const EMPLOYEE_WORK_ADDRESS_SOURCE_ATTRIBUTE = 'employeeWorkAddress';
    public const BUSINESS_CARD_URL_EMPLOYEE_SOURCE_ATTRIBUTE = 'businessCardUrlEmployee';
    public const MOBILE_PHONE_NUMBER_EMPLOYEE_SOURCE_ATTRIBUTE = 'mobilePhoneNumberEmployee';
    public const EXTERNAL_PHONE_NUMBER_EMPLOYEE_SOURCE_ATTRIBUTE = 'externalPhoneNumberEmployee';
    public const INTERNAL_PHONE_NUMBERS_EMPLOYEE_SOURCE_ATTRIBUTE = 'internalPhoneNumbersEmployee';
    public const WWW_HOMEPAGE_EMPLOYEE_SOURCE_ATTRIBUTE = 'wwwHomepageEmployee';

    public const LOCAL_DATA_SOURCE_ATTRIBUTES = [
        self::STAFF_AT_LOCAL_DATA_SOURCE_ATTRIBUTE,
        self::EMPLOYEE_POSTAL_ADDRESS_SOURCE_ATTRIBUTE,
        self::EMPLOYEE_WORK_ADDRESS_SOURCE_ATTRIBUTE,
        self::BUSINESS_CARD_URL_EMPLOYEE_SOURCE_ATTRIBUTE,
        self::MOBILE_PHONE_NUMBER_EMPLOYEE_SOURCE_ATTRIBUTE,
        self::EXTERNAL_PHONE_NUMBER_EMPLOYEE_SOURCE_ATTRIBUTE,
        self::INTERNAL_PHONE_NUMBERS_EMPLOYEE_SOURCE_ATTRIBUTE,
        self::WWW_HOMEPAGE_EMPLOYEE_SOURCE_ATTRIBUTE,
    ];

    protected static function getSubscribedEventNames(): array
    {
        return [
            PersonPreEvent::class,
            PersonPostEvent::class,
        ];
    }

    public function __construct(private readonly PersonProvider $personProvider)
    {
        parent::__construct('BasePerson');
    }

    protected function onPreEvent(LocalDataPreEvent $preEvent): void
    {
        $personIdentifiersToGet = null;
        $options = $preEvent->getOptions();
        if ($filter = Options::getFilter($options)) {
            $filter->mapConditionNodes(
                function (ConditionNode $conditionNode) use (&$personIdentifiersToGet) {
                    if ($conditionNode->getPath() === self::STAFF_AT_LOCAL_DATA_SOURCE_ATTRIBUTE) {
                        if ($conditionNode->getOperator() !== OperatorType::HAS_OPERATOR) {
                            throw new \RuntimeException(sprintf('Only the %s operator is supported for the "%s" source attribute',
                                OperatorType::HAS_OPERATOR, self::STAFF_AT_LOCAL_DATA_SOURCE_ATTRIBUTE));
                        }
                        if ($personIdentifiersToGet === null) {
                            $personIdentifiersToGet = $this->personProvider->getPersonIdentifiersByOrganization(
                                $conditionNode->getValue());
                        }
                        $conditionNode = new ConditionNode(
                            'identifier',
                            OperatorType::IN_ARRAY_OPERATOR,
                            $personIdentifiersToGet
                        );
                    }

                    return $conditionNode;
                });
        }
    }

    protected function getAttributeValue(LocalDataPostEvent $postEvent, array $attributeMapEntry): mixed
    {
        $person = $postEvent->getEntity();
        assert($person instanceof Person);
        $personIdentifier = $person->getIdentifier();

        switch ($attributeMapEntry[self::SOURCE_ATTRIBUTE_KEY]) {
            case self::STAFF_AT_LOCAL_DATA_SOURCE_ATTRIBUTE:
                $this->personProvider->getAndCacheCurrentResultPersonsFromApi();

                return $this->personProvider->getOrganizationIdentifiersByPerson($personIdentifier);
            case self::EMPLOYEE_POSTAL_ADDRESS_SOURCE_ATTRIBUTE:
                $this->personProvider->getAndCacheCurrentResultPersonsFromApi();

                return $this->personProvider->getEmployeePostalAddress($personIdentifier);
            case self::EMPLOYEE_WORK_ADDRESS_SOURCE_ATTRIBUTE:
                $this->personProvider->getAndCacheCurrentResultPersonsFromApi();

                return $this->personProvider->getEmployeeWorkAddress($personIdentifier);
            case self::BUSINESS_CARD_URL_EMPLOYEE_SOURCE_ATTRIBUTE:
                $this->personProvider->getAndCacheCurrentResultPersonsFromApi();

                return $this->personProvider->getPersonFromApiCached($personIdentifier)->getBusinessCardUrlEmployee();
            case self::MOBILE_PHONE_NUMBER_EMPLOYEE_SOURCE_ATTRIBUTE:
                $this->personProvider->getAndCacheCurrentResultPersonsFromApi();

                return $this->personProvider->getPersonFromApiCached($personIdentifier)->getMobilePhoneNumberEmployee();
            case self::EXTERNAL_PHONE_NUMBER_EMPLOYEE_SOURCE_ATTRIBUTE:
                $this->personProvider->getAndCacheCurrentResultPersonsFromApi();

                return $this->personProvider->getPersonFromApiCached($personIdentifier)->getExternalPhoneNumberEmployee();
            case self::INTERNAL_PHONE_NUMBERS_EMPLOYEE_SOURCE_ATTRIBUTE:
                $this->personProvider->getAndCacheCurrentResultPersonsFromApi();

                return $this->personProvider->getPersonFromApiCached($personIdentifier)->getInternalPhoneNumbersEmployee();
            case self::WWW_HOMEPAGE_EMPLOYEE_SOURCE_ATTRIBUTE:
                $this->personProvider->getAndCacheCurrentResultPersonsFromApi();

                return $this->personProvider->getPersonFromApiCached($personIdentifier)->getWwwHomepageEmployee();
        }

        return parent::getAttributeValue($postEvent, $attributeMapEntry); // TODO: Change the autogenerated stub
    }
}
