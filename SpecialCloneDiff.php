<?php

class SpecialCloneDiff extends SpecialPage {
	private $categories, $namespace;

	public function __construct() {
		parent::__construct( 'CloneDiff', 'clonediff' );
	}

	function execute( $query ) {
		if ( !$this->getUser()->isAllowed( 'clonediff' ) ) {
			throw new PermissionsError( 'clonediff' );
		}
		$this->setHeaders();
		$request = $this->getRequest();
		$out = $this->getOutput();

		if ( $request->getCheck( 'continue' ) ) {
			$this->displayDiffsForm();
			return;
		}

		if ( $request->getCheck( 'import' ) ) {
			$this->importAndDisplayResults();
			return;
		}

		// This must be the starting screen - show the form.
		$this->displayInitialForm();
	}

	function importAndDisplayResults() {
		global $wgCloneDiffWikis;

		wfProfileIn( __METHOD__ );

		$out = $this->getOutput();
		$user = $this->getUser();
		$request = $this->getRequest();

		$selectedWiki = $request->getVal( 'wikinum' );
		$apiURL = $wgCloneDiffWikis[$selectedWiki]['API URL'];

		$replacement_params['user_id'] = $user->getId();
		//$replacement_params['edit_summary'] = $this->msg( 'clonediff-editsummary' )->inContentLanguage()->plain();

		$pagesToImport = [];
		foreach ( $request->getValues() as $key => $value ) {
			if ( $value == '1' && $key !== 'import' && $key !== 'wikinum' ) {
				$pagesToImport[] = $key;
			}
		}

		$jobs = [];
		$remotePageData = $this->getRemoteDataForPageSet( $apiURL, $pagesToImport );
		foreach ( $remotePageData as $remotePage ) {
			$pageName = $remotePage->title;
			$remotePageRev = $remotePage->revisions[0];
			$replacement_params['page_text'] = $remotePageRev->{'*'}; // ???

			$title = Title::newFromText( $pageName );
			if ( $title !== null ) {
				$jobs[] = new ImportFromCloneJob( $title, $replacement_params );
			}
		}

		JobQueueGroup::singleton()->push( $jobs );

		$count = $this->getLanguage()->formatNum( count( $jobs ) );
		$out->addWikiMsg( 'clonediff-success', $count );

		// Link back
		$out->addHTML(
			Linker::link( $this->getTitle(),
				$this->msg( 'clonediff-return' )->escaped() )
		);

		wfProfileOut( __METHOD__ );
	}

	public function getLocalPages() {
		$dbr = wfGetDB( DB_REPLICA );
		$tables = [ 'page' ];
		$vars = [ 'page_id', 'page_namespace', 'page_title' ];
		$conds = [];
		if ( $this->namespace !== null ) {
			$conds['page_namespace'] = $this->namespace;
		}

		if ( count( $this->categories ) > 0 ) {
			$categoryStrings = [];
			foreach ( $this->categories as $category ) {
				$categoryStrings[] = "'" . str_replace( ' ', '_', $category ) . "'";
			}
			$tables[] = 'categorylinks';
			$conds[] = 'page_id = cl_from';
			$conds[] = 'cl_to IN (' . implode( ', ', $categoryStrings ) . ')';
		}

		$options = [ 'ORDER BY' => 'page_namespace, page_title' ];

		$res = $dbr->select( $tables, $vars, $conds, __METHOD__, $options );

		$localPages = [];
		foreach ( $res as $row ) {
			$title = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
			if ( $title == null ) {
				continue;
			}
			$localPages[] = $title->getPrefixedText();
		}

		return $localPages;
	}


	function getAllRemotePagesInCategories( $remoteAPIURL ) {
		$remotePages = [];

		foreach ( $this->categories as $category ) {
			$offset = 0;
			do {
				$apiURL = $remoteAPIURL . '?action=query&list=categorymembers&cmtitle=Category:' . $category;
				if ( $this->namespace !== 'all' ) {
					$apiURL .= '&cmnamespace=' . $this->namespace;
				}
				$apiURL .= "&offset=$offset&limit=500&format=json";
				$apiResult = Http::get( $apiURL );
				if ( $apiResult == '' ) {
					throw new MWException( "API at $remoteAPIURL is not responding." );
				}
				$apiResultData = json_decode( $apiResult );
				//$remotePageData = get_object_vars( $apiResultData->query->categorymembers );
				$remotePageData = $apiResultData->query->categorymembers;
				foreach ( $remotePageData as $remotePage ) {
					$remotePages[] = $remotePage->title;
				}
				$offset += 500;
			} while ( count( $remotePageData ) == 500 );
		}
		return $remotePages;
	}

	function getAllRemotePagesInNamespace( $remoteAPIURL) {
		$remotePages = [];

		$offset = 0;
		do {
			$apiURL = $remoteAPIURL . '?action=query&list=allpages&apnamespace=' .
				$this->namespace . "&offset=$offset&limit=500&format=json";
			$apiResult = Http::get( $apiURL );
			if ( $apiResult == '' ) {
				throw new MWException( "API at $remoteAPIURL is not responding." );
			}
			$apiResultData = json_decode( $apiResult );
			$remotePageData = $apiResultData->query->allpages;
			foreach ( $remotePageData as $remotePage ) {
				$remotePages[] = $remotePage->title;
			}
		} while ( count( $remotePageData ) == 500 );

		return $remotePages;
	}

	function displayDiffsForm() {
		global $wgCloneDiffWikis;

		wfProfileIn( __METHOD__ );
		$out = $this->getOutput();
		$request = $this->getRequest();

		$this->categories = [];
		$categoriesFromRequest = $request->getArray( 'categories' );
		if ( is_array( $categoriesFromRequest ) ) {
			foreach ( $request->getArray( 'categories' ) as $category => $val ) {
				$this->categories[] = $category;
			}
		}
		$this->namespace = $request->getVal( 'namespace' );
		if ( count( $wgCloneDiffWikis ) == 1 ) {
			$selectedWiki = 0;
		} else {
			$selectedWiki = $request->getVal( 'remoteWiki' );
		}
		$apiURL = $wgCloneDiffWikis[$selectedWiki]['API URL'];

		$out->addModuleStyles( 'mediawiki.diff.styles' );

		// Make sure that at least one namespace or one
		// category has been selected.
		if ( $this->namespace == null && count( $this->categories ) == 0 ) {
			$this->displayInitialForm( 'clonediff-nonamespace' );
			wfProfileOut( __METHOD__ );
			return;
		}

		$formOpts = [
			'id' => 'choose_pages',
			'method' => 'post',
			'action' => $this->getTitle()->getFullUrl()
		];
		$out->addHTML(
			Xml::openElement( 'form', $formOpts ) . "\n" .
			Html::hidden( 'title', $this->getTitle()->getPrefixedText() ) .
			Html::hidden( 'import', 1 ) .
			Html::hidden( 'wikinum', $selectedWiki )
		);

		$localPages = $this->getLocalPages();

		if ( count( $this->categories ) == 0 ) {
			$remotePages = $this->getAllRemotePagesInNamespace( $apiURL );
		} else {
			$remotePages = $this->getAllRemotePagesInCategories( $apiURL );
		}

		$allPages = array();

		$pagesOnlyInLocal = array_diff( $localPages, $remotePages );
		foreach ( $pagesOnlyInLocal as $pageName ) {
			$allPages[$pageName] = 0;
		}

		$pagesOnlyInRemote = array_diff( $remotePages, $localPages );
		foreach ( $pagesOnlyInRemote as $pageName ) {
			$allPages[$pageName] = 1;
		}

		$pagesInBoth = array_intersect( $localPages, $remotePages );
		foreach ( $pagesInBoth as $pageName ) {
			$allPages[$pageName] = 2;
		}

		ksort( $allPages );

		$allPageNames = array_keys( $allPages );

		list( $limit, $offset ) = $request->getLimitOffset();
		$this->showNavigation( count( $allPages ), $limit, $offset, true );

		$pagesToBeDisplayed = array();
		for ( $i = $offset; $i < $offset + $limit && $i < count( $allPageNames ); $i++ ) {
			$pageName = $allPageNames[$i];
			$status = $allPages[$pageName];
			$pagesToBeDisplayed[$pageName] = $status;
		}

		$localAndRemoteData = $this->getLocalAndRemoteDataForPageSet( $apiURL, $pagesToBeDisplayed );

		$diffEngine = new DifferenceEngine();
		foreach ( $localAndRemoteData as $pageName => $curPageData ) {
			$localText = $curPageData['localText'];
			$remoteText = $curPageData['remoteText'];
			if ( $localText != null ) {
				$title = Title::newFromText( $pageName );
			}
			if ( $remoteText != $localText && $remoteText != '' ) {
				$out->addHTML( Xml::check( $pageName, true ) . ' ' );
			}
			$out->addHTML( "<big><b>$pageName</b></big><br />" );
			if ( $remoteText == null ) {
				$html = '<p>Exists only on local wiki.</p>';
			} elseif ( $localText == $remoteText ) {
				$html = '<p>No change.</p>';
			} else {
				$html = '';
				if ( $localText == null ) {
					$html = '<p>Exists only on remote wiki.</p>';
				}
				$localContent = new WikitextContent( $localText );
				$remoteContent = new WikitextContent( $remoteText );
				$diffText = $diffEngine->generateContentDiffBody( $remoteContent, $localContent );

				// Replace line numbers with the text in the user's language
				if ( $diffText !== false ) {
					$diffText = $diffEngine->localiseLineNumbers( $diffText );
				}
$url = '';
				$outsideLink = Html::element( 'a', [ 'href' => $url ], 'Remote version' );
				$localLink = Linker::link( $title, 'Local version' );
				$html .= $diffEngine->addHeader( $diffText, $outsideLink, $localLink );
			}
			$out->addHTML( $html );
			$out->addHTML( "<br /><hr /><br />\n" );
		}

		$out->addHTML(
			Xml::submitButton( $this->msg( 'clonediff-import' )->text() ) .
			Xml::closeElement( 'form' )
		);

		$this->showNavigation( count( $allPages ), $limit, $offset, false );

		wfProfileOut( __METHOD__ );
	}

	function getRemoteDataForPageSet( $apiURL, $pagesInRemoteWiki ) {
		$pageDataURL = $apiURL .
			'?action=query&prop=revisions&titles=' . 
			str_replace( ' ', '_', implode( '|', $pagesInRemoteWiki ) ) .
			'&rvprop=user|timestamp|content&format=json';

		$apiResult = Http::get( $pageDataURL );
		$apiResultData = json_decode( $apiResult );
		if ( isset( $apiResultData->query ) ) {
			return get_object_vars( $apiResultData->query->pages );
		} else {
			return [];
		}
	}

	function getLocalAndRemoteDataForPageSet( $apiURL, $pageSet ) {
		$pagesNotInRemoteWiki = array();
		$pagesInRemoteWiki = array();
		foreach( $pageSet as $pageName => $status ) {
			if ( $status == 0 ) {
				$pagesNotInRemoteWiki[] = $pageName;
			} else {
				$pagesInRemoteWiki[] = $pageName;
			}
		}

		$remotePageData = $this->getRemoteDataForPageSet( $apiURL, $pagesInRemoteWiki );
		$allPageData = [];
		foreach ( $remotePageData as $remotePage ) {
			$curPageData = [];
			$pageName = $remotePage->title;
			if ( $pageSet[$pageName] == 2 ) {
				$localTitle = Title::newFromText( $pageName );
				$rev = Revision::newFromTitle( $localTitle );
				$localContent = $rev->getContent();
				$localText = $localContent->serialize();
			} else {
				$localText = '';
			}
			$curPageData['localText'] = $localText;

			$remotePageRev = $remotePage->revisions[0];
			$remoteText = $remotePageRev->{'*'}; // ???
			$curPageData['remoteText'] = $remoteText;

			if ( $localText == $remoteText ) {
				//$pageData[$pageName] = 'no change';
			} else {
				$curPageData['time'] = $remotePageRev->timestamp;
			}
			$allPageData[$pageName] = $curPageData;
		}

		foreach ( $pagesNotInRemoteWiki as $pageName ) {
			$curPageData = [];
			$localTitle = Title::newFromText( $pageName );
			$rev = Revision::newFromTitle( $localTitle );
			$localContent = $rev->getContent();
			$localText = $localContent->serialize();
			$curPageData['localText'] = $localText;
			$curPageData['remoteText'] = null;
			$allPageData[$pageName] = $curPageData;
		}

		ksort( $allPageData );

		return $allPageData;
	}

	// Based on code in the QueryPage class.
	function showNavigation( $numRows, $limit, $offset, $showMessage ) {
		$out = $this->getOutput();
		if ( $numRows > 0 ) {
			if ( $showMessage ) {
				$out->addHTML( $this->msg( 'showingresultsinrange' )->numParams(
					min( $numRows, $limit ), # do not show the one extra row, if exist
					$offset + 1, ( min( $numRows, $limit ) + $offset ) )->parseAsBlock() );
			}
			# Disable the "next" link when we reach the end
			$atEnd = ( $numRows <= $offset + $limit );
			$paging = $this->getLanguage()->viewPrevNext(
				$this->getPageTitle(), $offset,
				$limit, $this->linkParameters(), $atEnd
			);
			$out->addHTML( '<p>' . $paging . '</p>' );
		} else {
			# No results to show, so don't bother with "showing X of Y" etc.
			# -- just let the user know and give up now
			if ( $showMessage ) {
				$out->addWikiMsg( 'specialpage-empty' );
			}
			$out->addHTML( Xml::closeElement( 'div' ) );
		}
	}

	function linkParameters() {
		$params = [ 'continue' => true ];
		foreach ( $this->categories as $category ) {
			$params['categories'][$category] = true;
		}
		return $params;
	}

	function displayInitialForm( $warning_msg = null ) {
		global $wgCloneDiffWikis;

		$out = $this->getOutput();

		$out->addHTML(
			Xml::openElement(
				'form',
				[
					'id' => 'powersearch',
					'action' => $this->getTitle()->getFullUrl(),
					'method' => 'post'
				]
			) . "\n" .
			Html::hidden( 'title', $this->getTitle()->getPrefixedText() ) .
			Html::hidden( 'continue', 1 )
		);

		if ( count( $wgCloneDiffWikis ) == 1 ) {
			$wiki = $wgCloneDiffWikis[0];
			$wikiLink = Html::element( 'a', [ 'href' => $wiki['URL'] ], $wiki['name'] );
			$out->addHTML( "<p><b>Remote wiki: $wikiLink.</b></p>" );
		} else {
			// Make a dropdown
			$dropdownHTML = '<select name="remoteWiki">';
			foreach ( $wgCloneDiffWikis as $i => $cloneDiffWiki ) {
				$dropdownHTML .= '<option value="' . $i . '">' . $cloneDiffWiki['name'] . '</option>';
			}
			$dropdownHTML .= '</select>';
			$out->addHTML( "<p><b>Remote wiki:</b> $dropdownHTML</p>" );
		}

		if ( is_null( $warning_msg ) ) {
			$out->addWikiMsg( 'clonediff-docu' );
		} else {
			$out->wrapWikiMsg(
				"<div class=\"errorbox\">\n$1\n</div><br clear=\"both\" />",
				$warning_msg
			);
		}

		// The interface is heavily based on the one in Special:Search.
		$namespaces = array( 'all' => '<em>All</em>' ) +
			SearchEngine::searchableNamespaces();
		$nsText = "\n";
		foreach ( $namespaces as $ns => $name ) {
			if ( '' == $name ) {
				$name = $this->msg( 'blanknamespace' )->text();
			}
			$name = str_replace( '_', ' ', $name );
			$nsButton = '<label><input type="radio" name="namespace"' .
				' value="' . $ns . '"';
			if ( $ns === 'all' ) {
				$nsButton .= ' checked="checked"';
			}
			$nsButton .= '" />' . $name . '</label>';
			$nsText .= '<span style="float: left; width: 150px;">' .
				$nsButton . "</span>\n";
		}
		$out->addHTML(
			"<fieldset id=\"mw-searchoptions\">\n" .
			Xml::tags( 'h4', null, "Search in namespace:" ) .
			"$nsText\n</fieldset>"
		);

		$dbr = wfGetDB( DB_SLAVE );
		$categorylinks = $dbr->tableName( 'categorylinks' );
		$res = $dbr->query( "SELECT DISTINCT cl_to FROM $categorylinks" );
		$categories = array();
		while ( $row = $dbr->fetchRow( $res ) ) {
			$categories[] = str_replace( '_', ' ', $row[0] );
		}
		$dbr->freeResult( $res );
		sort( $categories );

		//$tables = $this->categoryTables( $categories );
		$categoriesText = '';
		foreach ( $categories as $cat ) {
			$categoryCheck = Xml::checkLabel( $cat, "categories[$cat]", "mw-search-category-$cat" );
			$categoriesText .= '<span style="float: left; width: 170px; padding-right: 15px;">' .
				$categoryCheck . '</span>';
		}
		$out->addHTML(
			"<fieldset id=\"mw-searchoptions\">\n" .
			Xml::tags( 'h4', null, "Search in categories:" ) .
			"$categoriesText\n</fieldset>"
		);

		$out->addHTML(
			Xml::submitButton( $this->msg( 'clonediff-continue' )->parse() ) .
			Xml::closeElement( 'form' )
		);
	}

	protected function getGroupName() {
		return 'wiki';
	}
}