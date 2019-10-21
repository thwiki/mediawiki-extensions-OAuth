<?php

namespace MediaWiki\Extensions\OAuth\AuthorizationProvider\Grant;

use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\GrantTypeInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use DateInterval;
use Exception;
use MediaWiki\Extensions\OAuth\AuthorizationProvider\AuthorizationProvider;
use MediaWiki\Extensions\OAuth\Entity\UserEntity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AuthorizationCodeAuthorization extends AuthorizationProvider {

	/**
	 * @inheritDoc
	 */
	public function needsUserApproval() {
		return true;
	}

	/**
	 * @return GrantTypeInterface
	 * @throws Exception
	 */
	protected function getGrant(): GrantTypeInterface {
		$authCodeRepo = $this->getAuthCodeRepo();
		$refreshTokenRepo = $this->getRefreshTokenRepo();
		$grant = new AuthCodeGrant(
			$authCodeRepo, $refreshTokenRepo, new DateInterval( 'PT10M' )
		);
		if ( !$this->config->get( 'OAuth2RequireCodeChallengeForPublicClients' ) ) {
			$grant->disableRequireCodeChallengeForPublicClients();
		}

		return $grant;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @return AuthorizationRequest
	 * @throws OAuthServerException
	 */
	public function init( ServerRequestInterface $request ): AuthorizationRequest {
		$authRequest = $this->server->validateAuthorizationRequest( $request );

		$userEntity = UserEntity::newFromMWUser( $this->user );
		$authRequest->setUser( $userEntity );
		$this->logAuthorizationRequest( __METHOD__, $authRequest );

		$this->logger->info(
			"OAuth2: Starting authorization request for client {client} and user (id) {user} ", [
				'client' => $authRequest->getClient()->getIdentifier(),
				'user' => $authRequest->getUser()->getIdentifier()
			]
		);

		return $authRequest;
	}

	/**
	 * @param AuthorizationRequest $authRequest
	 * @param ResponseInterface $response
	 * @return ResponseInterface
	 */
	public function authorize(
		AuthorizationRequest $authRequest, ResponseInterface $response
	): ResponseInterface {
		$this->logAuthorizationRequest( __METHOD__, $authRequest );
		return $this->server->completeAuthorizationRequest( $authRequest, $response );
	}

	/**
	 * @param string $method
	 * @param AuthorizationRequest $authRequest
	 */
	protected function logAuthorizationRequest( $method, AuthorizationRequest $authRequest ) {
		$this->logger->info(
			"OAuth2: Authorization request, func {func}, for client {client} " .
			"and user (id) {user} using grant \"{grant}\"", [
			'func' => $method,
			'client' => $authRequest->getClient()->getIdentifier(),
			'user' => $authRequest->getUser()->getIdentifier(),
			'grant' => $authRequest->getGrantTypeId()
		] );
	}
}
