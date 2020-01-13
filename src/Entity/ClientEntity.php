<?php

namespace MediaWiki\Extensions\OAuth\Entity;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use MediaWiki\Extensions\OAuth\MWOAuthConsumer;
use MediaWiki\Extensions\OAuth\MWOAuthConsumerAcceptance;
use MediaWiki\Extensions\OAuth\MWOAuthUtils;
use MediaWiki\Extensions\OAuth\Repository\AccessTokenRepository;
use User;
use MWException;
use MediaWiki\Extensions\OAuth\MWOAuthException;
use Exception;

class ClientEntity extends MWOAuthConsumer implements ClientEntityInterface {

	/**
	 * Returns the registered redirect URI (as a string).
	 *
	 * Alternatively return an indexed array of redirect URIs.
	 *
	 * @return string|string[]
	 */
	public function getRedirectUri() {
		return $this->getCallbackUrl();
	}

	/**
	 * Returns true if the client is confidential.
	 *
	 * @return bool
	 */
	public function isConfidential() {
		return $this->oauth2IsConfidential;
	}

	/**
	 * @return mixed
	 */
	public function getIdentifier() {
		return $this->getConsumerKey();
	}

	/**
	 * @param mixed $identifier
	 */
	public function setIdentifier( $identifier ) {
		$this->consumerKey = $identifier;
	}

	/**
	 * Get the grant types this client is allowed to use
	 *
	 * @return array
	 */
	public function getAllowedGrants() {
		return $this->oauth2GrantTypes;
	}

	/**
	 * Convenience function, same as getGrants()
	 * it just returns array of ScopeEntity-es instead of strings
	 *
	 * @return ScopeEntityInterface[]
	 */
	public function getScopes() {
		$scopeEntities = [];
		foreach ( $this->getGrants() as $grant ) {
			$scopeEntities[] = new ScopeEntity( $grant );
		}

		return $scopeEntities;
	}

	/**
	 * @return bool|User
	 * @throws MWException
	 */
	public function getUser() {
		return MWOAuthUtils::getLocalUserFromCentralId( $this->getUserId() );
	}

	/**
	 * @param null|string $secret
	 * @param null|string $grantType
	 * @return bool
	 */
	public function validate( $secret, $grantType ) {
		if ( !$this->isSecretValid( $secret ) ) {
			return false;
		}

		if ( !$this->isGrantAllowed( $grantType ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @return int
	 */
	public function getOAuthVersion() {
		return static::OAUTH_VERSION_2;
	}

	private function isSecretValid( $secret ) {
		return hash_equals( $secret, MWOAuthUtils::hmacDBSecret( $this->secretKey ) );
	}

	/**
	 * @param string $grantType
	 * @return bool
	 */
	public function isGrantAllowed( $grantType ) {
		return in_array( $grantType, $this->getAllowedGrants() );
	}

	/**
	 * @param User $mwUser
	 * @param bool $update
	 * @param array $grants
	 * @param null $requestTokenKey
	 * @return bool
	 * @throws MWException
	 * @throws MWOAuthException
	 */
	public function authorize( User $mwUser, $update, $grants, $requestTokenKey = null ) {
		$this->conductAuthorizationChecks( $mwUser );

		$grants = $this->getVerifiedScopes( $grants );
		$this->saveAuthorization( $mwUser, $update, $grants );

		return true;
	}

	/**
	 * Get the access token to be used with a single user
	 * Should never be called outside of client registration/manage code
	 *
	 * @param MWOAuthConsumerAcceptance $approval
	 * @param bool $revokeExisting - Delete all existing tokens
	 *
	 * @return AccessTokenEntityInterface
	 * @throws MWOAuthException
	 * @throws OAuthServerException
	 * @throws Exception
	 */
	public function getOwnerOnlyAccessToken(
		MWOAuthConsumerAcceptance $approval, $revokeExisting = false
	) {
		if (
			count( $this->getAllowedGrants() ) !== 1 ||
			$this->getAllowedGrants()[0] !== 'client_credentials'
		) {
			// sanity - make sure client is allowed *only* client_credentials grant,
			// so that this AT cannot be used in other grant type requests
			throw new MWOAuthException( 'mwoauth-oauth2-error-owner-only-invalid-grant' );
		}
		$accessToken = null;
		$accessTokenRepo = new AccessTokenRepository();
		if ( $revokeExisting ) {
			$accessTokenRepo->deleteForApprovalId( $approval->getId() );
		}
		/** @var AccessTokenEntity $accessToken */
		$accessToken = $accessTokenRepo->getNewToken( $this, $this->getScopes(), $approval->getUserId() );
		'@phan-var AccessTokenEntity $accessToken';
		$accessToken->setExpiryDateTime( ( new \DateTimeImmutable() )->add(
			new \DateInterval( 'P292277000000Y' )
		) );
		$accessToken->setPrivateKeyFromConfig();
		$accessToken->setIdentifier( bin2hex( random_bytes( 40 ) ) );

		$accessTokenRepo->persistNewAccessToken( $accessToken );

		return $accessToken;
	}

	/**
	 * Filter out scopes that application cannot use
	 *
	 * @param array $requested
	 * @return array
	 */
	private function getVerifiedScopes( $requested ) {
		return array_intersect( $requested, $this->getGrants() );
	}
}