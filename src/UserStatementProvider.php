<?php

namespace MediaWiki\Extension\OAuth;

use Config;
use MediaWiki\Extension\OAuth\Backend\Consumer;
use MediaWiki\Extension\OAuth\Backend\Utils;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\GrantsInfo;
use MediaWiki\User\UserGroupManager;
use MWException;
use User;

class UserStatementProvider {
	/** @var Config */
	protected $config;
	/** @var User */
	protected $user;
	/** @var Consumer */
	protected $consumer;
	/** @var array */
	protected $grants;
	/** @var UserGroupManager */
	private $userGroupManager;
	/** @var GrantsInfo */
	private $grantsInfo;

	/**
	 * @param User $user
	 * @param Consumer $consumer
	 * @param array $grants
	 * @return static
	 */
	public static function factory( User $user, Consumer $consumer, $grants = [] ) {
		$services = MediaWikiServices::getInstance();
		$mainConfig = $services->getMainConfig();
		$userGroupManager = $services->getUserGroupManager();
		$grantsInfo = $services->getGrantsInfo();
		return new static(
			$mainConfig,
			$user,
			$consumer,
			$grants,
			$userGroupManager,
			$grantsInfo
		);
	}

	/**
	 * UserStatementProvider constructor.
	 * @param Config $config
	 * @param User $user
	 * @param Consumer $consumer
	 * @param array $grants
	 * @param UserGroupManager $userGroupManager
	 * @param GrantsInfo $grantsInfo
	 */
	protected function __construct(
		$config,
		$user,
		$consumer,
		$grants,
		$userGroupManager,
		$grantsInfo
	) {
		$this->config = $config;
		$this->user = $user;
		$this->consumer = $consumer;
		$this->grants = $grants;
		$this->userGroupManager = $userGroupManager;
		$this->grantsInfo = $grantsInfo;
	}

	/**
	 * Retrieve user statement suitable for JWT encoding
	 *
	 * @return array
	 * @throws MWException
	 */
	public function getUserStatement() {
		$statement = [];

		// Include some of the OpenID Connect attributes
		// http://openid.net/specs/openid-connect-core-1_0.html (draft 14)
		// Issuer Identifier for the Issuer of the response.
		$statement['iss'] = $this->config->get( 'CanonicalServer' );
		// Subject identifier. A locally unique and never reassigned identifier.
		// T264560: sub added via $this->getUserProfile()
		// Audience(s) that this ID Token is intended for.
		$statement['aud'] = $this->consumer->getConsumerKey();
		// Expiration time on or after which the ID Token MUST NOT be accepted for processing.
		$statement['exp'] = (int)wfTimestamp() + 100;
		// Time at which the JWT was issued.
		$statement['iat'] = (int)wfTimestamp();
		// TODO: Add auth_time, if we start tracking last login timestamp

		$statement += $this->getUserProfile();

		return $statement;
	}

	/**
	 * Retrieve user profile information
	 *
	 * @return array
	 */
	public function getUserProfile() {
		$profile = [
			'sub' => (string)Utils::getCentralIdFromLocalUser( $this->user ),
		];
		// Include some MediaWiki info about the user
		if ( !$this->user->isHidden() ) {
			$profile['username'] = $this->user->getName();
			$profile['editcount'] = intval( $this->user->getEditCount() );
			$profile['confirmed_email'] = $this->user->isEmailConfirmed();
			$profile['blocked'] = $this->user->getBlock() !== null;
			$profile['registered'] = $this->user->getRegistration();
			$profile['groups'] = $this->userGroupManager->getUserEffectiveGroups( $this->user );
			$profile['rights'] = array_values( array_unique(
				MediaWikiServices::getInstance()->getPermissionManager()->getUserPermissions( $this->user )
			) );
			$profile['grants'] = $this->grants;

			if ( class_exists( '\wAvatar' ) ) {
				$avatar = new \wAvatar( $this->user->getId(), 'l' );
				$profile['avatar'] = $avatar->getAvatarUrlPath();
			}

			if ( in_array( 'mwoauth-authonlyprivate', $this->grants ) ||
				in_array( 'viewmyprivateinfo', $this->grantsInfo->getGrantRights( $profile['grants'] ) )
			) {
				// Paranoia - avoid showing the real name if the wiki is not configured to use
				// it but it somehow exists (from past configuration, or some identity management
				// extension). This is important as the viewmyprivateinfo grant is presented
				// to the user differently when useRealNames() is false.
				// Don't omit the field completely to avoid a breaking change.
				$profile['realname'] = !in_array(
					'realname', $this->config->get( 'HiddenPrefs' ), true
				) ? $this->user->getRealName() : '';
				$profile['email'] = $this->user->getEmail();
			}
		} else {
			$profile['blocked'] = true;
		}

		return $profile;
	}
}
