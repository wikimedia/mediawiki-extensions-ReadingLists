<?php

namespace MediaWiki\Extensions\ReadingLists;

use Language;
use MediaWiki\Interwiki\InterwikiLookup;

/**
 * Service for turning domain names into interwiki prefixes.
 * Assumes a domain name structure somewhat similar to Wikimedia.
 */
class ReverseInterwikiLookup implements ReverseInterwikiLookupInterface {

	/** @var InterwikiLookup */
	private $interwikiLookup;

	/** @var string */
	private $ownDomain;

	/** @var string[] domain name => iw prefix */
	private $prefixTable;

	/**
	 * @param InterwikiLookup $interwikiLookup
	 * @param string $ownDomain
	 */
	public function __construct( InterwikiLookup $interwikiLookup, $ownDomain ) {
		$this->interwikiLookup = $interwikiLookup;
		$this->ownDomain = $ownDomain;
	}

	/**
	 * @inheritDoc
	 */
	public function lookup( $domain ) {
		$prefixTable = $this->getPrefixTable();

		if ( $domain === $this->ownDomain ) {
			return '';
		}

		if ( array_key_exists( $domain, $prefixTable ) ) {
			return $prefixTable[$domain];
		}

		$domainParts = explode( '.', $domain );
		$targetLang = $domainParts[0];
		if ( !Language::isValidCode( $targetLang ) ) {
			return null;
		}
		$ownDomainParts = explode( '.', $this->ownDomain );
		$intermediateDomainParts = $domainParts;
		array_splice( $intermediateDomainParts, 0, 1, $ownDomainParts[0] );
		$intermediateDomain = implode( '.', $intermediateDomainParts );
		if ( array_key_exists( $intermediateDomain, $prefixTable ) ) {
			return [ $prefixTable[$intermediateDomain], $targetLang ];
		}

		return null;
	}

	/**
	 * Gets (and caches) interwiki data from the core database.
	 * @return string[] Domain name => interwiki prefix
	 */
	protected function getPrefixTable() {
		if ( $this->prefixTable === null ) {
			$iwData = $this->interwikiLookup->getAllPrefixes( true );
			foreach ( $iwData as $iwRow ) {
				$url = wfParseUrl( $iwRow['iw_url'] );
				if ( !$url || !$url['host'] ) {
					continue;
				}
				$this->prefixTable[$url['host']] = $iwRow['iw_prefix'];
			}
		}
		return $this->prefixTable;
	}

}
