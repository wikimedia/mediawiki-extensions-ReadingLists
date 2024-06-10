<?php

namespace MediaWiki\Extension\ReadingLists\Rest;

use MediaWiki\Message\Converter;
use MediaWiki\Message\Message;
use MediaWiki\Rest\LocalizedHttpException;
use Wikimedia\Message\ListParam;
use Wikimedia\Message\MessageValue;
use Wikimedia\Message\ParamType;

/**
 * Trait for collecting utility code that is a candidate for moving to the REST infrastructure
 * in MediaWiki core.
 *
 * Much of this code is related to parameter validation that cannot reasonably be expressed in the
 * usual validation functions, especially as related to combinations of parameters. One open
 * question: is validation of this sort actually RESTful? Would including these functions
 * encourage a type of endpoint design that we'd rather not have?
 */
trait RestUtilTrait {
	/**
	 * @param string|Message|MessageValue $msg
	 * @param array $params
	 * @param int $code
	 * @return never
	 * @throws LocalizedHttpException
	 */
	protected function die( $msg, array $params = [], int $code = 400 ) {
		if ( $msg instanceof Message ) {
			$c = new Converter();
			$mv = $c->convertMessage( $msg );
		} elseif ( $msg instanceof MessageValue ) {
			$mv = $msg;
		} else {
			$mv = MessageValue::new( $msg, $params );
		}

		throw new LocalizedHttpException( $mv, 400 );
	}

	/**
	 * @param bool $condition
	 * @param string|Message|MessageValue $msg
	 * @param array $params
	 * @param int $code
	 * @return void
	 * @throws LocalizedHttpException
	 */
	protected function dieIf( bool $condition, $msg, array $params = [], int $code = 400 ) {
		if ( $condition ) {
			$this->die( $msg, $params, $code );
		}
	}

	/**
	 * Dies if more than one parameter from a certain set of parameters are set and not false.
	 *
	 * @param array $params User provided parameters set, as from $this->getValidatedParams()
	 * @param string ...$required Parameter names that cannot have more than one set
	 */
	protected function requireMaxOneParameter( array $params, ...$required ) {
		$intersection = array_intersect( array_keys( array_filter( $params,
			[ $this, 'parameterNotEmpty' ] ) ), $required );

		if ( count( $intersection ) > 1 ) {
			$lp = new ListParam(
				ParamType::TEXT,
				array_map(
					static function ( $paramName ) {
						return '<var>' . $paramName . '</var>';
					},
					array_values( $intersection )
				)
			);

			$mv = MessageValue::new( 'apierror-invalidparammix' )
				->textListParams( [ $lp ] )
				->numParams( count( $intersection ) );
			throw new LocalizedHttpException( $mv, 400 );
		}
	}

	/**
	 * Die if 0 of a certain set of parameters is set and not false.
	 *
	 * @param array $params User provided parameters set, as from $this->extractRequestParams()
	 * @param string ...$required Names of parameters of which at least one must be set
	 */
	protected function requireAtLeastOneParameter( $params, ...$required ) {
		$intersection = array_intersect(
			array_keys( array_filter( $params, [ $this, 'parameterNotEmpty' ] ) ),
			$required
		);

		if ( count( $intersection ) == 0 ) {
			$lp = new ListParam(
				ParamType::TEXT,
				array_map(
					static function ( $paramName ) {
						return '<var>' . $paramName . '</var>';
					},
					array_values( $required )
				)
			);

			$mv = MessageValue::new( 'apierror-missingparam-at-least-one-of' )
				->textListParams( [ $lp ] )
				->numParams( count( $required ) );
			throw new LocalizedHttpException( $mv, 400 );
		}
	}

	/**
	 * Die if 0 or more than one of a certain set of parameters is set and not false.
	 *
	 * @param array $params User provided parameter set, as from $this->extractRequestParams()
	 * @param string ...$required Names of parameters of which exactly one must be set
	 * @throws LocalizedHttpException
	 */
	protected function requireOnlyOneParameter( $params, ...$required ) {
		$intersection = array_intersect( array_keys( array_filter( $params,
			[ $this, 'parameterNotEmpty' ] ) ), $required );

		if ( count( $intersection ) > 1 ) {
			$lp = new ListParam(
				ParamType::TEXT,
				array_map(
					static function ( $paramName ) {
						return '<var>' . $paramName . '</var>';
					},
					array_values( $intersection )
				)
			);

			$mv = MessageValue::new( 'apierror-invalidparammix' )
				->textListParams( [ $lp ] )
				->numParams( count( $intersection ) );
			throw new LocalizedHttpException( $mv, 400 );
		} elseif ( count( $intersection ) == 0 ) {
			$lp = new ListParam(
				ParamType::TEXT,
				array_map(
					static function ( $paramName ) {
						return '<var>' . $paramName . '</var>';
					},
					array_values( $required )
				)
			);

			$mv = MessageValue::new( 'apierror-missingparam-one-of' )
				->textListParams( [ $lp ] )
				->numParams( count( $required ) );
			throw new LocalizedHttpException( $mv, 400 );
		}
	}

	/**
	 * Callback function used in requireOnlyOneParameter to check whether required parameters are set.
	 *
	 * @param mixed $x Parameter to check is not null/false/empty string
	 * @return bool
	 */
	protected function parameterNotEmpty( $x ): bool {
		return $x !== null && $x !== false && $x !== '';
	}
}
