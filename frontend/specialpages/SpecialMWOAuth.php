<?php
/*
 (c) Chris Steipp, Aaron Schulz 2013, GPL

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
 http://www.gnu.org/copyleft/gpl.html
*/

/**
 * Page that handles OAuth consumer authorization and token exchange
 */
class SpecialMWOAuth extends UnlistedSpecialPage {
	function __construct() {
		parent::__construct( 'MWOAuth' );
	}

	public function execute( $subpage ) {
		$this->setHeaders();

		$user = $this->getUser();
		$request = $this->getRequest();
		$format = $request->getVal( 'format', 'raw' );
		if ( !in_array( $subpage, array( 'initiate', 'authorize', 'verified', 'token' ) ) ) {
			$this->showError( 'oauth-client-invalidrequest', $format );
		}

		try {
			switch ( $subpage ) {
				case 'initiate':
					$oauthServer = MWOAuthUtils::newMWOAuthServer();
					$oauthRequest = MWOAuthRequest::fromRequest( $request );
					wfDebugLog( 'OAuth', __METHOD__ . ": Consumer " .
						"'{$oauthRequest->getConsumerKey()}' getting temporary credentials" );
					// fetch_request_token does the version, freshness, and sig checks
					$token = $oauthServer->fetch_request_token( $oauthRequest );
					$this->returnToken( $token, $format );
					break;
				case 'authorize':
					$format = $request->getVal( 'format', 'html' ); // for exceptions
					// Hack: prefix needed for HTMLForm
					$requestToken = $request->getVal( 'wprequestToken',
						$request->getVal( 'oauth_token' ) );
					$consumerKey = $request->getVal( 'wpconsumerKey',
						$request->getVal( 'oauth_consumer_key' ) );
					wfDebugLog( 'OAuth', __METHOD__ . ": doing 'authorize' with " .
						"'$requestToken' '$consumerKey' for '{$user->getName()}'" );
					// TODO? Test that $requestToken exists in memcache
					if ( $user->isAnon() ) {
						// Redirect to login page
						$query['returnto'] = $this->getTitle( 'authorize' )->getPrefixedText();
						$query['returntoquery'] = wfArrayToCgi( array(
							'oauth_token'        => $requestToken,
							'oauth_consumer_key' => $consumerKey
						) );
						$loginPage = SpecialPage::getTitleFor( 'UserLogin' );
						$url = $loginPage->getLocalURL( $query );
						$this->getOutput()->redirect( $url );
					} else {
						// Show form and redirect on submission for authorization
						$this->handleAuthorizationForm( $requestToken, $consumerKey );
					}
					break;
				case 'token':
					$oauthServer = MWOAuthUtils::newMWOAuthServer();
					$oauthRequest = MWOAuthRequest::fromRequest( $request );
					$consumerKey = $oauthRequest->get_parameter( 'oauth_consumer_key' );
					wfDebugLog( 'OAuth', "/token: '{$consumerKey}' getting temporary credentials" );
					$token = $oauthServer->fetch_access_token( $oauthRequest );
					$this->returnToken( $token, $format );
					break;
				case 'verified':
					$format = $request->getVal( 'format', 'html' );
					$verifier = $request->getVal( 'oauth_verifier', false );
					$requestToken = $request->getVal( 'oauth_token', false );
					if ( !$verifier || !$requestToken ) {
						throw new MWOAuthException( 'mwoauth-bad-request' );
					}
					$this->getOutput()->addSubtitle( $this->msg( 'mwoauth-desc' )->escaped() );
					$this->showResponse(
						$this->msg( 'mwoauth-verified',
							wfEscapeWikiText( $verifier ),
							wfEscapeWikiText( $requestToken )
						)->parse(),
						$format
					);
					break;
				default:
					throw new OAuthException( 'mwoauth-invalid-method' );
			}
		} catch ( MWOAuthException $exception ) {
			wfDebugLog( 'OAuth', __METHOD__ . ": Exception " . $exception->getMessage() );
			$this->showError( $exception->getMessage(), $format );
		} catch ( OAuthException $exception ) {
			wfDebugLog( 'OAuth', __METHOD__ . ": Exception " . $exception->getMessage() );
			$this->showError( $exception->getMessage(), $format );
		}
	}

	// @TODO: cancel button
	protected function handleAuthorizationForm( $requestToken, $consumerKey ) {
		$this->getOutput()->addSubtitle( $this->msg( 'mwoauth-desc' )->escaped() );

		$user = $this->getUser();
		$lang = $this->getLanguage();

		$dbr = MWOAuthUtils::getCentralDB( DB_SLAVE ); // @TODO: lazy handle
		$oauthServer = MWOAuthUtils::newMWOAuthServer();

		$cmr = MWOAuthDAOAccessControl::wrap(
			MWOAuthConsumer::newFromKey( $dbr, $consumerKey ),
			$this->getContext()
		);
		if ( !$cmr ) {
			throw new MWOAuthException( 'mwoauth-bad-request' );
		} elseif ( $cmr->get( 'stage' ) !== MWOAuthConsumer::STAGE_APPROVED
			&& !$cmr->getDAO()->isPendingAndOwnedBy( $user )
		) {
			throw new MWOAuthException( 'mwoauth-invalid-authorization-not-approved' );
		}

		// Check if this user has authorized grants for this consumer previously
		$existing = $oauthServer->getCurrentAuthorization( $user, $cmr->getDAO() );

		$control = new MWOAuthConsumerAcceptanceSubmitControl( $this->getContext(), array(), $dbr );
		$form = new HTMLForm(
			$control->registerValidators( array(
				'name' => array(
					'type' => 'info',
					'label-message' => 'mwoauth-consumer-name',
					'default' => $cmr->get( 'name' ),
					'size' => '45'
				),
				'user' => array(
					'type' => 'info',
					'label-message' => 'mwoauth-consumer-user',
					'default' => $cmr->get( 'userId', 'MWOAuthUtils::getCentralUserNameFromId' )
				),
				'version' => array(
					'type' => 'info',
					'label-message' => 'mwoauth-consumer-version',
					'default' => $cmr->get( 'version' ),
				),
				'description' => array(
					'type' => 'info',
					'label-message' => 'mwoauth-consumer-description',
					'default' => $cmr->get( 'description' ),
					'rows' => 5
				),
				'wiki' => array(
					'type' => 'info',
					'label-message' => 'mwoauth-consumer-wiki',
					'default' => $cmr->get( 'wiki' ),
				),
				'grants'  => array(
					'type' => 'info',
					'label-message' => 'mwoauth-grants-heading',
					'default' => $this->getGrantsHtml( $cmr->get( 'grants' ) ),
					'raw' => true
				),
				'action' => array(
					'type'    => 'hidden',
					'default' => 'accept',
					'validation-callback' => null // different format
				),
				'confirmUpdate' => array(
					'type'    => 'hidden',
					'default' => $existing ? 1 : 0,
					'validation-callback' => null // different format
				),
				'consumerKey' => array(
					'type'    => 'hidden',
					'default' => $consumerKey,
					'validation-callback' => null // different format
				),
				'requestToken' => array(
					'type'    => 'hidden',
					'default' => $requestToken,
					'validation-callback' => null // different format
				)
			) ),
			$this->getContext()
		);
		$form->setSubmitCallback(
			function( array $data, IContextSource $context ) use ( $control ) {
				$data['grants'] = FormatJSON::encode( // adapt form to controller
					preg_replace( '/^grant-/', '', $data['grants'] ) );

				$control->setInputParameters( $data );
				return $control->submit();
			}
		);

		if ( $existing ) {
			// User has already authorized this consumer
			$grants = $existing->get( 'grants');
			$grantList = is_null( $grants )
				? $this->msg( 'mwoauth-grants-nogrants' )->text()
				: $lang->semicolonList( MWOAuthUtils::grantNames( $grants ) );
			$form->addPreText( $this->msg( 'mwoauth-form-existing',
				$grantList,
				$existing->get( 'wiki' ),
				$lang->timeAndDate( $existing->get( 'accepted' ), true )
			)->parseAsBlock() );
		} else {
			$form->addPreText( $this->msg( 'mwoauth-form-description' )->parseAsBlock() );
		}
		$form->addPreText( $this->msg( 'mwoauth-form-legal' )->text() );

		$form->setWrapperLegendMsg( 'mwoauth-desc' );
		$form->setSubmitTextMsg( 'mwoauth-form-button-approve' );

		$status = $form->show();
		if ( $status instanceof Status && $status->isOk() ) {
			// Redirect to callback url
			$this->getOutput()->redirect( $status->value['result']['callbackUrl'] );
		}
	}

	/**
	 * @param Array $grants list of grants (null is also allowed for no permissions)
	 * @return string
	 */
	private function getGrantsHtml( $grants ) {
		// TODO: dom / styling
		$html = '';
		if ( $grants === array() || is_null( $grants ) ) {
			$html .= Html::rawElement(
				'ul',
				array(),
				Html::element(
					'li',
					array(),
					$this->msg( 'mwoauth-grants-nogrants' )->text()
				)
			);
		} else {
			$list = '';
			foreach ( $grants as $grant ) {
				$list .= Html::element(
					'li',
					array(),
					MWOAuthUtils::grantName( $grant )
				);
			}
			$html .= Html::rawElement( 'ul', array(), $list );
		}
		return $html;
	}

	/**
	 * @param string $message message key to return to the user
	 * @param string $format the format of the response: json, xml, or html
	 */
	private function showError( $message, $format ) {
		if ( $format == 'html' ) {
			$this->getOutput()->showErrorPage( 'mwoauth-error', $message );
		} elseif ( $format == 'raw' ) {
			$this->showResponse( 'Error: ' . wfMessage( $message )->escaped(), 'raw' );
		} elseif ( $format == 'json' ) {
			$error = json_encode( array( 'error' => wfMessage( $message )->escaped() ) );
			$this->showResponse( $error, 'raw' );
		}
	}

	/**
	 * @param OAuthToken $token
	 * @param string $format the format of the response: json, xml, or html
	 */
	private function returnToken( OAuthToken $token, $format  ) {
		if ( $format == 'raw' ) {
			$return = 'oauth_token=' . OAuthUtil::urlencode_rfc3986( $token->key );
			$return .= '&oauth_token_secret=' . OAuthUtil::urlencode_rfc3986( $token->secret );
			$return .= '&oauth_callback_confirmed=true';
			$this->showResponse( $return, 'raw' );
		} elseif ( $format == 'json' ) {
			$this->showResponse( FormatJSON::encode( $token ), 'raw' );
		} elseif ( $format == 'html' ) {
			$html = Html::element(
				'li',
				array(),
				'oauth_token = ' . OAuthUtil::urlencode_rfc3986( $token->key )
			);
			$html .= Html::element(
				'li',
				array(),
				'oauth_token_secret = ' . OAuthUtil::urlencode_rfc3986( $token->secret )
			);
			$html .= Html::element(
				'li',
				array(),
				'oauth_callback_confirmed = true'
			);
			$html = Html::rawElement( 'ul', array(), $html );
			$this->showResponse( $html, 'html' );
		}
	}


	/**
	 * @param string $response html or string to pass back to the user. Already escaped.
	 * @param string $format the format of the response: raw, or otherwise
	 */
	private function showResponse( $response, $format  ) {
		$out = $this->getOutput();
		if ( $format == 'raw' ) {
			// FIXME: breaks with text/trace profiler
			$out->setArticleBodyOnly( true );
			$out->enableClientCache( false );
			$out->preventClickjacking();
			$out->clearHTML();
			$out->addHTML( $response );
		} else {
			$out->addHtml( $response );
		}
	}
}
