<?php

namespace MediaWiki\Extension\ReadingLists\Tests;

use MediaWiki\Extension\ReadingLists\ReadingListRepositoryFactory;
use MediaWiki\Extension\ReadingLists\ReverseInterwikiLookupInterface;
use MediaWiki\Extension\ReadingLists\Service\UserPreferenceBatchUpdater;
use MediaWiki\Extension\ReadingLists\Validator\ReadingListPreferenceEligibilityValidator;
use MediaWiki\MediaWikiServices;

/**
 * @coversNothing
 */
class ServiceWiringTest extends \PHPUnit\Framework\TestCase {

	public function testReadingListEligibilityValidator() {
		$service = MediaWikiServices::getInstance()->getService( 'ReadingLists.ReadingListEligibilityValidator' );
		$this->assertInstanceOf( ReadingListPreferenceEligibilityValidator::class, $service );
	}

	public function testReadingListRepositoryFactory() {
		$service = MediaWikiServices::getInstance()->getService( 'ReadingLists.ReadingListRepositoryFactory' );
		$this->assertInstanceOf( ReadingListRepositoryFactory::class, $service );
	}

	public function testReverseInterwikiLookup() {
		$service = MediaWikiServices::getInstance()->getService( 'ReverseInterwikiLookup' );
		$this->assertInstanceOf( ReverseInterwikiLookupInterface::class, $service );
	}

	public function testUserPreferenceBatchUpdater() {
		$service = MediaWikiServices::getInstance()->getService( 'UserPreferenceBatchUpdater' );
		$this->assertInstanceOf( UserPreferenceBatchUpdater::class, $service );
	}

}
