<?php

namespace AdvancedSearch;

use ExtensionRegistry;
use MediaWiki\Hook\SpecialSearchResultsPrependHook;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\Request\WebRequest;
use MediaWiki\Specials\SpecialSearch;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;

/**
 * @license GPL-2.0-or-later
 */
class Hooks implements
	GetPreferencesHook,
	SpecialSearchResultsPrependHook
{

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SpecialSearchResultsPrepend
	 *
	 * @param SpecialSearch $specialSearch
	 * @param OutputPage $output
	 * @param string $term
	 */
	public function onSpecialSearchResultsPrepend( $specialSearch, $output, $term ) {
		$services = MediaWikiServices::getInstance();
		$user = $specialSearch->getUser();

		/**
		 * If the user is logged in and has explicitly requested to disable the extension, don't load.
		 * Ensure namespaces are always part of search URLs
		 */
		if ( $user->isNamed() &&
			$services->getUserOptionsLookup()->getBoolOption( $user, 'advancedsearch-disable' )
		) {
			return;
		}

		$output->addHTML(
			Html::rawElement(
				'div',
				[ 'class' => 'mw-search-spinner' ],
				Html::element( 'div', [ 'class' => 'mw-search-spinner-bounce' ] )
			)
		);

		$output->addModules( [
			'ext.advancedSearch.init',
			'ext.advancedSearch.searchtoken',
		] );

		$output->addModuleStyles( 'ext.advancedSearch.initialstyles' );

		$output->addJsConfigVars( $this->getJsConfigVars(
			$specialSearch,
			ExtensionRegistry::getInstance(),
			$services
		) );
	}

	/**
	 * @param SpecialSearch $specialSearch
	 * @param ExtensionRegistry $extensionRegistry
	 * @param MediaWikiServices $services
	 * @return array
	 */
	private function getJsConfigVars(
		SpecialSearch $specialSearch,
		ExtensionRegistry $extensionRegistry,
		MediaWikiServices $services
	): array {
		$config = $specialSearch->getConfig();
		$vars = [
			'advancedSearch.mimeTypes' =>
				( new MimeTypeConfigurator( $services->getMimeAnalyzer() ) )->getMimeTypes(
					$config->get( 'FileExtensions' )
				),
			'advancedSearch.tooltips' => ( new TooltipGenerator( $specialSearch->getContext() ) )->generateTooltips(),
			'advancedSearch.namespacePresets' => $config->get( 'AdvancedSearchNamespacePresets' ),
			'advancedSearch.deepcategoryEnabled' => $config->get( 'AdvancedSearchDeepcatEnabled' ),
			'advancedSearch.searchableNamespaces' =>
				SearchableNamespaceListBuilder::getCuratedNamespaces(
					$services->getSearchEngineConfig()->searchableNamespaces()
				),
			'advancedSearch.explicitNamespaceURL' => $this->getExplicitNamespaceURL( $specialSearch )
		];

		if ( $extensionRegistry->isLoaded( 'Translate' ) ) {
			$vars += [ 'advancedSearch.languages' =>
				$services->getLanguageNameUtils()->getLanguageNames()
			];
		}

		return $vars;
	}

	/**
	 * If the request does not contain any namespaces, return a URL that
	 * reflects the namespaces that were to construct the search results.
	 * This is used with history.pushState to make consistent, shareable
	 * search result URLs (T217445)
	 * @param SpecialSearch $specialSearch
	 * @return string|null the URL with explicit namespaces, or null if not needed
	 */
	private static function getExplicitNamespaceURL( SpecialSearch $specialSearch ): ?string {
		if ( !self::isNamespacedSearch( $specialSearch->getRequest() ) ) {
			$namespacedSearchUrl = $specialSearch->getRequest()->getFullRequestURL();
			$queryParts = [];
			foreach ( self::getDefaultNamespaces( $specialSearch->getUser() ) as $ns ) {
				$queryParts['ns' . $ns] = '1';
			}
			return wfAppendQuery( $namespacedSearchUrl, $queryParts );
		}
		return null;
	}

	/**
	 * Retrieves the default namespaces for the current user
	 *
	 * @param UserIdentity $user The user to lookup default namespaces for
	 * @return int[] List of namespaces to be searched by default
	 */
	public static function getDefaultNamespaces( UserIdentity $user ): array {
		$searchConfig = MediaWikiServices::getInstance()->getSearchEngineConfig();
		return $searchConfig->userNamespaces( $user ) ?: $searchConfig->defaultNamespaces();
	}

	/**
	 * Checks if there is a search request, and it already specifies namespaces.
	 * @param WebRequest $request
	 * @return bool
	 */
	private static function isNamespacedSearch( WebRequest $request ): bool {
		if ( $request->getRawVal( 'search', '' ) === '' ) {
			return true;
		}

		foreach ( $request->getValueNames() as $requestKey ) {
			if ( preg_match( '/^ns\d+$/', $requestKey ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param User $user
	 * @param array[] &$preferences
	 */
	public function onGetPreferences( $user, &$preferences ) {
		$preferences['advancedsearch-disable'] = [
			'type' => 'toggle',
			'label-message' => 'advancedsearch-preference-disable',
			'section' => 'searchoptions/advancedsearch',
			'help-message' => 'advancedsearch-preference-help',
		];
	}
}
