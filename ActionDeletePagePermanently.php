<?php

class ActionDeletePagePermanently extends FormAction {

	public static function AddSkinHook( SkinTemplate &$sktemplate, array &$links ) {
		if ( !$sktemplate->getUser()->isAllowed( 'deleteperm' ) ) {

			return false;
		}

		$title = $sktemplate->getRelevantTitle();
		$action = self::getActionName( $sktemplate );

		if ( self::canDeleteTitle( $title ) ) {
			$links['actions']['delete_page_permanently'] = [
				'class' => ( $action === 'delete_page_permanently' ) ? 'selected' : false,
				'text' => $sktemplate->msg( 'deletepagesforgood-delete_permanently' )->text(),
				'href' => $title->getLocalUrl( 'action=delete_page_permanently' )
			];
		}

		return true;
	}

	public function getName() {
		return 'delete_page_permanently';
	}

	public function doesWrites() {
		return true;
	}

	public function getDescription() {
		return '';
	}

	public static function canDeleteTitle( Title $title ) {
		global $wgDeletePagesForGoodNamespaces;

		if ( $title->exists() && $title->getArticleID() !== 0 &&
			$title->getDBkey() !== '' &&
			$title->getNamespace() !== NS_SPECIAL &&
			isset( $wgDeletePagesForGoodNamespaces[ $title->getNamespace() ] ) &&
			$wgDeletePagesForGoodNamespaces[ $title->getNamespace() ]
		) {
			return true;
		} else {
			return false;
		}
	}

	public function onSubmit( $data ) {

		if ( self::canDeleteTitle( $this->getTitle() ) ) {
			$this->deletePermanently( $this->getTitle() );
			return true;
		} else {
			# $output->addHTML( $this->msg( 'deletepagesforgood-del_impossible' )->escaped() );
			return [ 'deletepagesforgood-del_impossible' ];
		}
	}

	public function deletePermanently( Title $title ) {

		$ns = $title->getNamespace();
		$t = $title->getDBkey();
		$id = $title->getArticleID();
		$cats = $title->getParentCategories();

		$dbw = wfGetDB( DB_MASTER );

		$dbw->startAtomic( __METHOD__ );

		/*
		 * First delete entries, which are in direct relation with the page:
		 */

		# delete redirect...
		$dbw->delete( 'redirect', [ 'rd_from' => $id ], __METHOD__ );

		# delete external link...
		$dbw->delete( 'externallinks', [ 'el_from' => $id ], __METHOD__ );

		# delete language link...
		$dbw->delete( 'langlinks', [ 'll_from' => $id ], __METHOD__ );

		if ( !$GLOBALS['wgDBtype'] == "postgres" ) {
			# delete search index...
			$dbw->delete( 'searchindex', [ 'si_page' => $id ], __METHOD__ );
		}

		# Delete restrictions for the page
		$dbw->delete( 'page_restrictions', [ 'pr_page' => $id ], __METHOD__ );

		# Delete page Links
		$dbw->delete( 'pagelinks', [ 'pl_from' => $id ], __METHOD__ );

		# delete category links
		$dbw->delete( 'categorylinks', [ 'cl_from' => $id ], __METHOD__ );

		# delete template links
		$dbw->delete( 'templatelinks', [ 'tl_from' => $id ], __METHOD__ );

		# read text entries for all revisions and delete them.
		$res = $dbw->select( 'revision', 'rev_text_id', "rev_page=$id" );

		foreach ( $res as $row ) {
			$value = $row->rev_text_id;
			$dbw->delete( 'text', [ 'old_id' => $value ], __METHOD__ );
		}

		# In the table 'revision' : Delete all the revision of the page where 'rev_page' = $id
		$dbw->delete( 'revision', [ 'rev_page' => $id ], __METHOD__ );

		# delete image links
		$dbw->delete( 'imagelinks', [ 'il_from' => $id ], __METHOD__ );

		/*
		 * then delete entries which are not in direct relation with the page:
		 */

		# Clean up recentchanges entries...
		$dbw->delete( 'recentchanges', [
			'rc_namespace' => $ns,
			'rc_title' => $t
		], __METHOD__ );

		# read text entries for all archived pages and delete them.
		$res = $dbw->select( 'archive', 'ar_text_id', [
			'ar_namespace' => $ns,
			'ar_title' => $t
		] );

		foreach ( $res as $row ) {
			$value = $row->ar_text_id;
			$dbw->delete( 'text', [ 'old_id' => $value ], __METHOD__ );
		}

		# Clean archive entries...
		$dbw->delete( 'archive', [
			'ar_namespace' => $ns,
			'ar_title' => $t
		], __METHOD__ );

		# Clean up log entries...
		$dbw->delete( 'logging', [
			'log_namespace' => $ns,
			'log_title' => $t
		], __METHOD__ );

		# Clean up watchlist...
		$dbw->delete( 'watchlist', [
			'wl_namespace' => $ns,
			'wl_title' => $t
		], __METHOD__ );

		# In the table 'page' : Delete the page entry
		$dbw->delete( 'page', [ 'page_id' => $id ], __METHOD__ );

		/*
		 * If the article belongs to a category, update category counts
		 */
		if ( !empty( $cats ) ) {
			foreach ( $cats as $parentcat => $currentarticle ) {
				$catname = preg_split( '/:/', $parentcat, 2 );
				$cat = Category::newFromName( $catname[1] );
				$cat->refreshCounts();
			}
		}

		/*
		 * If an image is beeing deleted, some extra work needs to be done
		 */
		if ( $ns == NS_FILE ) {

			$file = wfFindFile( $t );

			if ( $file ) {
				# Get all filenames of old versions:
				$fields = OldLocalFile::selectFields();
				$res = $dbw->select( 'oldimage', $fields, [ 'oi_name' => $t ] );

				foreach ( $res as $row ) {
					$oldLocalFile = OldLocalFile::newFromRow( $row, $file->repo );
					$path = $oldLocalFile->getArchivePath() . '/' . $oldLocalFile->getArchiveName();

					try {
						unlink( $path );
					}
					catch ( Exception $e ) {
						return $e->getMessage();
					}
				}

				$path = $file->getLocalRefPath();

				try {
					$file->purgeThumbnails();
					unlink( $path );
				} catch ( Exception $e ) {
					return $e->getMessage();
				}
			}

			# clean the filearchive for the given filename:
			$dbw->delete( 'filearchive', [ 'fa_name' => $t ], __METHOD__ );

			# Delete old db entries of the image:
			$dbw->delete( 'oldimage', [ 'oi_name' => $t ], __METHOD__ );

			# Delete archive entries of the image:
			$dbw->delete( 'filearchive', [ 'fa_name' => $t ], __METHOD__ );

			# Delete image entry:
			$dbw->delete( 'image', [ 'img_name' => $t ], __METHOD__ );

			// $dbw->endAtomic( __METHOD__ );

			$linkCache = LinkCache::singleton();
			$linkCache->clear();
		}
		$dbw->endAtomic( __METHOD__ );
		return true;
	}

	/**
	 * Returns the name that goes in the \<h1\> page title
	 *
	 * @return string
	 */
	protected function getPageTitle() {
		return $this->msg( 'deletepagesforgood-deletepagetitle', $this->getTitle()->getPrefixedText() );
	}

	protected function alterForm( HTMLForm $form ) {

		$title = $this->getTitle();
		$output = $this->getOutput();

		$output->addBacklinkSubtitle( $title );
		$form->addPreText( $this->msg( 'confirmdeletetext' )->parseAsBlock() );

		$form->addPreText(
			$this->msg( 'deletepagesforgood-ask_deletion' )->parseAsBlock()
		);

		$form->setSubmitTextMsg( 'deletepagesforgood-yes' );
	}

	public function getRestriction() {
		return 'deleteperm';
	}

	public function onSuccess() {
		$output = $this->getOutput();
		$output->addHTML( $this->msg( 'deletepagesforgood-del_done' )->escaped() );
		return false;
	}
}
