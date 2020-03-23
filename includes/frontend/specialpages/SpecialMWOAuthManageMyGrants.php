<?php

namespace MediaWiki\Extensions\OAuth;

use Html;
use SpecialPage;
use Wikimedia\Rdbms\DBConnRef;

/**
 * (c) Aaron Schulz 2013, GPL
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

/**
 * Special page for listing consumers this user granted access to and
 * for manage the specific grants given or revoking access for the consumer
 */
class SpecialMWOAuthManageMyGrants extends SpecialPage {
	private static $irrevocableGrants = null;

	public function __construct() {
		parent::__construct( 'OAuthManageMyGrants', 'mwoauthmanagemygrants' );
	}

	public function doesWrites() {
		return true;
	}

	public function execute( $par ) {
		global $wgMWOAuthReadOnly;

		$user = $this->getUser();

		$this->setHeaders();
		$this->getOutput()->disallowUserJs();
		$this->addHelpLink( 'Help:OAuth' );

		if ( !$this->getUser()->isLoggedIn() ) {
			throw new \UserNotLoggedIn();
		}
		if ( !$user->isAllowed( 'mwoauthmanagemygrants' ) ) {
			throw new \PermissionsError( 'mwoauthmanagemygrants' );
		}

		// Format is Special:OAuthManageMyGrants[/list|/manage/<accesstoken>]
		$navigation = explode( '/', $par );
		$typeKey = $navigation[0] ?? null;
		$acceptanceId = $navigation[1] ?? null;

		if ( $wgMWOAuthReadOnly && in_array( $typeKey, [ 'update', 'revoke' ] ) ) {
			throw new \ErrorPageError( 'mwoauth-error', 'mwoauth-db-readonly' );
		}

		switch ( $typeKey ) {
		case 'update':
		case 'revoke':
			$this->handleConsumerForm( $acceptanceId, $typeKey );
			break;
		default:
			$this->showConsumerList();
			break;
		}

		$this->addSubtitleLinks( $acceptanceId );

		$this->getOutput()->addModuleStyles( 'ext.MWOAuth.styles' );
	}

	/**
	 * Show navigation links
	 *
	 * @param string|null $acceptanceId
	 * @return void
	 */
	protected function addSubtitleLinks( $acceptanceId ) {
		$listLinks = [];

		if ( $acceptanceId ) {
			$dbr = MWOAuthUtils::getCentralDB( DB_REPLICA );
			$cmraAc = MWOAuthConsumerAcceptance::newFromId( $dbr, $acceptanceId );
			$listLinks[] = \Linker::linkKnown(
				$this->getPageTitle(),
				$this->msg( 'mwoauthmanagemygrants-showlist' )->escaped() );

			if ( $cmraAc ) {
				$cmrAc = MWOAuthConsumer::newFromId( $dbr, $cmraAc->getConsumerId() );
				$consumerKey = $cmrAc->getConsumerKey();
				$listLinks[] = \Linker::linkKnown(
					\SpecialPage::getTitleFor( 'OAuthListConsumers', "view/$consumerKey" ),
					$this->msg( 'mwoauthconsumer-application-view' )->escaped() );
			}
		} else {
			$listLinks[] = $this->msg( 'mwoauthmanagemygrants-showlist' )->escaped();
		}

		$linkHtml = $this->getLanguage()->pipeList( $listLinks );

		$this->getOutput()->setSubtitle(
			"<strong>" . $this->msg( 'mwoauthmanagemygrants-navigation' )->escaped() .
			"</strong> [{$linkHtml}]" );
	}

	/**
	 * Show the form to approve/reject/disable/re-enable consumers
	 *
	 * @param string $acceptanceId
	 * @param string $type One of (update,revoke)
	 * @throws \PermissionsError
	 */
	protected function handleConsumerForm( $acceptanceId, $type ) {
		$user = $this->getUser();
		$lang = $this->getLanguage();
		$dbr = MWOAuthUtils::getCentralDB( DB_REPLICA );

		$centralUserId = MWOAuthUtils::getCentralIdFromLocalUser( $user );
		if ( !$centralUserId ) {
			$this->getOutput()->addWikiMsg( 'badaccess-group0' );
			return;
		}

		$cmraAc = MWOAuthConsumerAcceptanceAccessControl::wrap(
			MWOAuthConsumerAcceptance::newFromId( $dbr, $acceptanceId ), $this->getContext() );
		if ( !$cmraAc || $cmraAc->getUserId() !== $centralUserId ) {
			$this->getOutput()->addHTML( $this->msg( 'mwoauth-invalid-access-token' )->escaped() );
			return;
		}

		$cmrAc = MWOAuthConsumerAccessControl::wrap(
			MWOAuthConsumer::newFromId( $dbr, $cmraAc->getConsumerId() ), $this->getContext() );
		if ( $cmrAc->getDeleted() && !$user->isAllowed( 'mwoauthviewsuppressed' ) ) {
			throw new \PermissionsError( 'mwoauthviewsuppressed' );
		}

		$this->getOutput()->addModuleStyles( 'mediawiki.ui.button' );

		$action = '';
		if ( $this->getRequest()->getCheck( 'renounce' ) ) {
			$action = 'renounce';
		} elseif ( $this->getRequest()->getCheck( 'update' ) ) {
			$action = 'update';
		}

		$data = [ 'action' => $action ];
		$control = new MWOAuthConsumerAcceptanceSubmitControl(
			$this->getContext(), $data, $dbr, $cmraAc->getDAO()->getOAuthVersion()
		);
		$form = \HTMLForm::factory( 'ooui',
			$control->registerValidators( [
				'info' => [
					'type' => 'info',
					'raw' => true,
					'default' => MWOAuthUIUtils::generateInfoTable( [
						'mwoauth-consumer-name' => $cmrAc->getNameAndVersion(),
						'mwoauth-consumer-user' => $cmrAc->getUserName(),
						'mwoauth-consumer-description' => $cmrAc->getDescription(),
						'mwoauthmanagemygrants-wikiallowed' => $cmraAc->getWikiName(),
					], $this->getContext() ),
				],
				'grants'  => [
					'type' => 'checkmatrix',
					'label-message' => 'mwoauthmanagemygrants-applicablegrantsallowed',
					'columns' => [
						$this->msg( 'mwoauthmanagemygrants-grantaccept' )->escaped() => 'grant'
					],
					'rows' => array_combine(
						array_map( 'MWGrants::getGrantsLink', $cmrAc->getGrants() ),
						$cmrAc->getGrants()
					),
					'default' => array_map(
						function ( $g ) {
							return "grant-$g";
						},
						$cmraAc->getGrants()
					),
					'tooltips' => [
						\MWGrants::getGrantsLink( 'basic' ) =>
							$this->msg( 'mwoauthmanagemygrants-basic-tooltip' )->text(),
						\MWGrants::getGrantsLink( 'mwoauth-authonly' ) =>
							$this->msg( 'mwoauthmanagemygrants-authonly-tooltip' )->text(),
						\MWGrants::getGrantsLink( 'mwoauth-authonlyprivate' ) =>
							$this->msg( 'mwoauthmanagemygrants-authonly-tooltip' )->text(),
					],
					'force-options-on' => array_map(
						function ( $g ) {
							return "grant-$g";
						},
						( $type === 'revoke' )
							? array_merge( \MWGrants::getValidGrants(), self::irrevocableGrants() )
							: self::irrevocableGrants()
					),
					'validation-callback' => null // different format
				],
				'acceptanceId' => [
					'type' => 'hidden',
					'default' => $cmraAc->getId(),
				]
			] ),
			$this->getContext()
		);
		$form->setSubmitCallback(
			function ( array $data, \IContextSource $context ) use ( $action, $cmraAc ) {
				$data['action'] = $action;
				$data['grants'] = \FormatJson::encode( // adapt form to controller
					preg_replace( '/^grant-/', '', $data['grants'] ) );

				$dbw = MWOAuthUtils::getCentralDB( DB_MASTER );
				$control = new MWOAuthConsumerAcceptanceSubmitControl(
					$context, $data, $dbw, $cmraAc->getDAO()->getOAuthVersion()
				);
				return $control->submit();
			}
		);

		$form->setWrapperLegendMsg( 'mwoauthmanagemygrants-confirm-legend' );
		$form->suppressDefaultSubmit();
		if ( $type === 'revoke' ) {
			$form->addButton( [
				'name' => 'renounce',
				'value' => $this->msg( 'mwoauthmanagemygrants-renounce' )->text(),
				'flags' => [ 'primary', 'destructive' ],
			] );
		} else {
			$form->addButton( [
				'name' => 'update',
				'value' => $this->msg( 'mwoauthmanagemygrants-update' )->text(),
				'flags' => [ 'primary', 'progressive' ],
			] );
		}
		$form->addPreText(
			$this->msg( "mwoauthmanagemygrants-$type-text" )->parseAsBlock() );

		$status = $form->show();
		if ( $status instanceof \Status && $status->isOK() ) {
			// Messages: mwoauthmanagemygrants-success-update, mwoauthmanagemygrants-success-renounce
			$this->getOutput()->addWikiMsg( "mwoauthmanagemygrants-success-$action" );
		}
	}

	/**
	 * Show a paged list of consumers with links to details
	 *
	 * @return void
	 */
	protected function showConsumerList() {
		$this->getOutput()->addWikiMsg( 'mwoauthmanagemygrants-text' );

		$centralUserId = MWOAuthUtils::getCentralIdFromLocalUser( $this->getUser() );
		$pager = new MWOAuthManageMyGrantsPager( $this, [], $centralUserId );
		if ( $pager->getNumRows() ) {
			$this->getOutput()->addHTML( $pager->getNavigationBar() );
			$this->getOutput()->addHTML( $pager->getBody() );
			$this->getOutput()->addHTML( $pager->getNavigationBar() );
		} else {
			$this->getOutput()->addWikiMsg( "mwoauthmanagemygrants-none" );
		}
	}

	/**
	 * @param DBConnRef $db
	 * @param \stdClass $row
	 * @return string
	 */
	public function formatRow( DBConnRef $db, $row ) {
		$cmrAc = MWOAuthConsumerAccessControl::wrap(
			MWOAuthConsumer::newFromRow( $db, $row ), $this->getContext() );
		$cmraAc = MWOAuthConsumerAcceptanceAccessControl::wrap(
			MWOAuthConsumerAcceptance::newFromRow( $db, $row ), $this->getContext() );

		$links = [];
		if ( array_diff( $cmrAc->getGrants(), self::irrevocableGrants() ) ) {
			$links[] = \Linker::linkKnown(
				$this->getPageTitle( 'update/' . $cmraAc->getId() ),
				$this->msg( 'mwoauthmanagemygrants-review' )->escaped()
			);
		}
		$links[] = \Linker::linkKnown(
			$this->getPageTitle( 'revoke/' . $cmraAc->getId() ),
			$this->msg( 'mwoauthmanagemygrants-revoke' )->escaped()
		);
		$reviewLinks = $this->getLanguage()->pipeList( $links );

		$encName = $cmrAc->escapeForHtml( $cmrAc->getNameAndVersion() );

		$r = '<li class="mw-mwoauthmanagemygrants-list-item">';
		$r .= "<strong dir='ltr'>{$encName}</strong> (<strong>$reviewLinks</strong>)";
		$data = [
			'mwoauthmanagemygrants-user' => $cmrAc->getUserName(),
			'mwoauthmanagemygrants-wikiallowed' => $cmraAc->getWikiName(),
		];

		foreach ( $data as $msg => $val ) {
			$r .= '<p>' . $this->msg( $msg )->escaped() . ' ' . $cmrAc->escapeForHtml( $val ) . '</p>';
		}

		$editsUrl = SpecialPage::getTitleFor( 'Contributions', $this->getUser()->getName() )
			->getFullURL( [ 'tagfilter' => MWOAuthUtils::getTagName( $cmrAc->getId() ) ] );
		$editsLink = Html::element( 'a', [ 'href' => $editsUrl ],
			$this->msg( 'mwoauthmanagemygrants-editslink', $this->getUser() )->text() );
		$r .= '<p>' . $editsLink . '</p>';
		$actionsUrl = SpecialPage::getTitleFor( 'Log' )->getFullURL( [
			'user' => $this->getUser()->getName(),
			'tagfilter' => MWOAuthUtils::getTagName( $cmrAc->getId() ),
		] );
		$actionsLink = Html::element( 'a', [ 'href' => $actionsUrl ],
			$this->msg( 'mwoauthmanagemygrants-actionslink', $this->getUser() )->text() );
		$r .= '<p>' . $actionsLink . '</p>';

		$r .= '</li>';

		return $r;
	}

	private static function irrevocableGrants() {
		if ( self::$irrevocableGrants === null ) {
			self::$irrevocableGrants = array_merge(
				\MWGrants::getHiddenGrants(),
				[ 'mwoauth-authonly', 'mwoauth-authonlyprivate' ]
			);
		}
		return self::$irrevocableGrants;
	}

	protected function getGroupName() {
		return 'users';
	}
}
