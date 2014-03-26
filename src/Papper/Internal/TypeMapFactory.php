<?php

namespace Papper\Internal;

use Papper\Internal\Type\Type;
use Papper\MappingOptionsInterface;
use Papper\NotSupportedException;
use Papper\PropertyMap;
use Papper\TypeMap;
use Papper\ClassNotFoundException;

class TypeMapFactory
{
	/**
	 * @var \ReflectionClass[]
	 */
	private $reflectorsCache = array();
	/**
	 * @var MemberAccessFactory
	 */
	private $memberAccessFactory;

	public function __construct()
	{
		$this->memberAccessFactory = new MemberAccessFactory();
	}

	public function createTypeMap($sourceType, $destinationType, MappingOptionsInterface $mappingOptions)
	{
		$sourceReflector = $this->findReflector($sourceType);
		$destReflector = $this->findReflector($destinationType);

		$typeMap = new TypeMap($sourceType, $destinationType, new SimpleObjectCreator($destReflector));

		/** @var $destMembers \ReflectionProperty[]|\ReflectionMethod[] */
		$destMembers = array_merge(
			$destReflector->getProperties(\ReflectionProperty::IS_PUBLIC),
			ReflectionHelper::getPublicMethods($destReflector, 1)
		);

		foreach ($destMembers as $destMember) {
			$sourceMembers = array();

			$getter = $this->memberAccessFactory->createMemberSetter($destMember, $mappingOptions);
			$setter = $this->mapDestinationMemberToSource($sourceMembers, $sourceReflector, $destMember->getName(), $mappingOptions)
				? $this->memberAccessFactory->createMemberGetter($sourceMembers, $mappingOptions)
				: null;

			$typeMap->addPropertyMap(new PropertyMap($getter, $setter));
		}

		return $typeMap;
	}

	/**
	 * @param \ReflectionMethod[]|\ReflectionProperty[] $sourceMembers
	 * @param \ReflectionClass $sourceReflector
	 * @param string $nameToSearch
	 * @param MappingOptionsInterface $mappingOptions
	 * @return array
	 */
	public function mapDestinationMemberToSource(array &$sourceMembers, \ReflectionClass $sourceReflector, $nameToSearch,
		MappingOptionsInterface $mappingOptions)
	{
		$sourceProperties = $sourceReflector->getProperties(\ReflectionProperty::IS_PUBLIC);
		$sourceNoArgMethods = ReflectionHelper::getPublicMethods($sourceReflector, 0);

		$member = $this->findTypeMember($sourceProperties, $sourceNoArgMethods, $nameToSearch, $mappingOptions);

		$foundMatch = $member !== null;

		if ($foundMatch) {
			$sourceMembers[] = $member;
		} else {
			$matches = $this->splitDestinationMemberName($nameToSearch, $mappingOptions);

			for ($i = 0; ($i < count($matches)) && !$foundMatch; $i++) {
				$snippet = $this->createNameSnippet($matches, $i, $mappingOptions);

				$member = $this->findTypeMember($sourceProperties, $sourceNoArgMethods, $snippet['first'], $mappingOptions);
				if ($member !== null) {
					$sourceMembers[] = $member;

					$foundMatch = $this->mapDestinationMemberToSource(
						$sourceMembers,
						$this->parseTypeFromAnnotation($member),
						$snippet['second'],
						$mappingOptions
					);

					if (!$foundMatch) {
						array_pop($sourceMembers);
					}
				}
			}
		}
		return $foundMatch;
	}

	private function findTypeMember(array $properties, array $getMethods, $nameToSearch, MappingOptionsInterface $mappingOptions)
	{
		/** @var $member \ReflectionProperty|\ReflectionMethod */
		foreach (array_merge($getMethods, $properties) as $member) {
			if ($this->nameMatches($member->getName(), $nameToSearch, $mappingOptions)) {
				return $member;
			}
		}
		return null;
	}

	private function nameMatches($sourceMemberName, $destMemberName, MappingOptionsInterface $mappingOptions)
	{
		$possibleSourceNames = $this->possibleNames($sourceMemberName, $mappingOptions->getSourcePrefixes());
		$possibleDestNames = $this->possibleNames($destMemberName, $mappingOptions->getDestinationPrefixes());

		return count(array_uintersect($possibleSourceNames, $possibleDestNames, function($a, $b){
			return strcasecmp($a, $b);
		})) > 0;
	}

	private function possibleNames($memberName, array $prefixes)
	{
		if (empty($memberName)) {
			return array();
		}

		$possibleNames = array($memberName);
		foreach ($prefixes as $prefix) {
			if (stripos($memberName, $prefix) === 0) {
				$withoutPrefix = substr($memberName, strlen($prefix));
				$possibleNames[] = $withoutPrefix;
			}
		}
		return $possibleNames;
	}

	private function splitDestinationMemberName($nameToSearch, MappingOptionsInterface $mappingOptions)
	{
		preg_match_all($mappingOptions->getDestinationMemberNamingConvention()->getSplittingExpression(), $nameToSearch, $matches);
		return isset($matches[0]) ? $matches[0] : array();
	}

	private function createNameSnippet(array $matches, $i, MappingOptionsInterface $mappingOptions)
	{
		return array(
			'first' => implode(
				$mappingOptions->getSourceMemberNamingConvention()->getSeparatorCharacter(),
				array_slice($matches, 0, $i)
			),
			'second' => implode(
				$mappingOptions->getSourceMemberNamingConvention()->getSeparatorCharacter(),
				array_slice($matches, $i)
			),
		);
	}

	private function parseTypeFromAnnotation(\Reflector $reflector)
	{
		// @TODO: Implement TypeMapFactory::parseTypeFromAnnotation() method.
		throw new NotSupportedException("Method TypeMapFactory::parseTypeFromAnnotation not implemented yet");
	}

	private function findReflector($type)
	{
		if (!class_exists($type)) {
			throw new ClassNotFoundException(sprintf('Type <%s> must be class', $type));
		}

		return isset($this->reflectorsCache[$type])
			? $this->reflectorsCache[$type]
			: $this->reflectorsCache[$type] = new \ReflectionClass($type);
	}
}
