<?php

namespace MediaWiki\Extensions\ReadingLists;

/**
 * Service interface for turning a domain name into an interwiki prefix so the API can
 * use it in generators.
 */
interface ReverseInterwikiLookupInterface {
	/**
	 * Look up the interwiki prefix belonging to a domain.
	 * There are four possible cases:
	 * - the domain is local: return an empty string
	 * - the domain is a known interwiki: return the interwiki prefix
	 * - the domain is unknown: return null
	 * - the domain can be reached through a sequence of interwikis: return the prefixes in an array
	 *   (where the second prefix is a valid interwiki prefix on the project referred to by the
	 *   first interwiki prefix, and so on),
	 *   So e.g. [ 'b', 'de' ] on English Wikipedia would point to German Wikibooks,
	 *   much like MediaWiki would handle the title 'b:de:Foo'.
	 * @param string $domain Wiki domain (could be a full URL, extra parts will be stripped)
	 * @return string|array|null Interwiki prefix, or an array of interwiki prefixes,
	 *   or null if the lookup failed.
	 */
	public function lookup( $domain );
}
