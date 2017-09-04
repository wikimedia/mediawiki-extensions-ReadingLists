<?php

namespace MediaWiki\Extensions\ReadingLists;

use Exception;
use ILocalizedException;
use Message;

/**
 * Used by ReadingListRepository methods when performing the method would violate some kind of
 * constraint (e.g. trying to add an entry to a list owned by a different user). Usually this is
 * a client error; in some cases it could happen for otherwise sane calls due to race conditions.
 */
class ReadingListRepositoryException extends Exception implements ILocalizedException {

	/** @var Message */
	private $messageObject;

	/**
	 * @param string $messageKey MediaWiki message key for describing the error.
	 * @param array $params Parameters for the message.
	 */
	public function __construct( $messageKey, array $params = [] ) {
		$this->messageObject = new Message( $messageKey, $params );
		$messageText = $this->messageObject->inLanguage( 'en' )->useDatabase( false )->plain();
		parent::__construct( "$messageText ($messageKey)" );
	}

	/**
	 * @return Message
	 */
	public function getMessageObject() {
		return $this->messageObject;
	}

}