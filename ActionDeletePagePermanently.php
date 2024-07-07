<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\BlobStore;
use Wikimedia\Rdbms\IDatabase;

class ActionDeletePagePermanently extends FormAction {

	/**
	 * @param SkinTemplate $sktemplate
	 * @param array &$links
	 */
	public static function onAddSkinHook( SkinTemplate $sktemplate, array &$links ) {
		if ( $sktemplate->getUser()->isAllowed( 'deleteperm' ) ) {
			$title = $sktemplate->getRelevantTitle();
			$action = self::getActionName( $sktemplate );

			if ( self::canDeleteTitle( $title ) ) {
				$links['actions']['delete_page_permanently'] = [
					'class' => ( $action === 'delete_page_permanently' ) ? 'selected' : false,
					'text' => $sktemplate->msg( 'deletepagesforgood-delete_permanently' )->text(),
					'href' => $title->getLocalUrl( 'action=delete_page_permanently' )
				];
			}
		}
	}

	/** @inheritDoc */
	public function getName() {
		return 'delete_page_permanently';
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	public function getDescription() {
		return '';
	}

	/** @inheritDoc */
	protected function usesOOUI() {
		return true;
	}

	/**
	 * @param Title $title
	 * @return bool
	 */
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

	/**
	 * @param mixed $data
	 * @return bool|string[]
	 */
	public function onSubmit( $data ) {
		if ( self::canDeleteTitle( $this->getTitle() ) ) {
			$this->deletePermanently( $this->getTitle() );
			return true;
		} else {
			# $output->addHTML( $this->msg( 'deletepagesforgood-del_impossible' )->escaped() );
			return [ 'deletepagesforgood-del_impossible' ];
		}
	}

	/**
	 * @param Title $title
	 * @return bool|string
	 */
	public function deletePermanently( Title $title ) {
		$ns = $title->getNamespace();
		$t = $title->getDBkey();
		$id = $title->getArticleID();
		$cats = $title->getParentCategories();

		$dbw = wfGetDB( DB_PRIMARY );

		$dbw->startAtomic( __METHOD__ );

		/*
		 * First delete entries, which are in direct relation with the page:
		 */

		# Delete redirect...
		$dbw->delete( 'redirect', [ 'rd_from' => $id ], __METHOD__ );

		# Delete external links...
		$dbw->delete( 'externallinks', [ 'el_from' => $id ], __METHOD__ );

		# Delete language links...
		$dbw->delete( 'langlinks', [ 'll_from' => $id ], __METHOD__ );

		if ( $GLOBALS['wgDBtype'] !== "postgres" && $GLOBALS['wgDBtype'] !== "sqlite" ) {
			# Delete search index...
			$dbw->delete( 'searchindex', [ 'si_page' => $id ], __METHOD__ );
		}

		# Delete restrictions for the page
		$dbw->delete( 'page_restrictions', [ 'pr_page' => $id ], __METHOD__ );

		# Delete page links
		$dbw->delete( 'pagelinks', [ 'pl_from' => $id ], __METHOD__ );

		# Delete category links
		$dbw->delete( 'categorylinks', [ 'cl_from' => $id ], __METHOD__ );

		# Delete template links
		$dbw->delete( 'templatelinks', [ 'tl_from' => $id ], __METHOD__ );

		// $wgMultiContentRevisionSchemaMigrationStage existed between 1.32 (included) and 1.39 (excluded)
		$mcrSchemaMigrationStage = isset( $GLOBALS['wgMultiContentRevisionSchemaMigrationStage'] )
			? $GLOBALS['wgMultiContentRevisionSchemaMigrationStage']
			: 0;

		// Before 1.32, revision.rev_text_id existed, but $mcrSchemaMigrationStage === 0
		if ( ( $mcrSchemaMigrationStage & SCHEMA_COMPAT_WRITE_OLD ) ||
			$dbw->fieldExists( 'revision', 'rev_text_id', __METHOD__ )
		) {
			# Read text entries for all revisions and delete them.
			$res = $dbw->select( 'revision', 'rev_text_id', "rev_page=$id" );
			foreach ( $res as $row ) {
				$value = $row->rev_text_id;
				$dbw->delete( 'text', [ 'old_id' => $value ], __METHOD__ );
			}

			# Read text entries for all archived pages and delete them.
			$arRes = $dbw->select( 'archive', 'ar_text_id', [
				'ar_namespace' => $ns,
				'ar_title' => $t
			] );
			foreach ( $arRes as $arRow ) {
				$value = $arRow->ar_text_id;
				$dbw->delete( 'text', [ 'old_id' => $value ], __METHOD__ );
			}
		}

		// From 1.35, revision.rev_text_id does not exist, and from 1.39 $mcrSchemaMigrationStage === 0
		if ( ( $mcrSchemaMigrationStage & SCHEMA_COMPAT_WRITE_NEW ) ||
			!$dbw->fieldExists( 'revision', 'rev_text_id', __METHOD__ )
		) {
			# Delete slot, content, and text entries for all revisions.
			$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
			$blobStore = MediaWikiServices::getInstance()->getBlobStore();
			$revQuery = $revisionStore->getQueryInfo();

			$res = $dbw->select(
				$revQuery['tables'],
				$revQuery['fields'],
				"rev_page=$id",
				__METHOD__,
				[],
				$revQuery['joins']
			);
			foreach ( $res as $row ) {
				$rev = $revisionStore->newRevisionFromRow( $row );
				$this->deleteSlotsPermanently( $dbw,
					$rev->getSlots()->getSlots(),
					$rev->getId(),
					$blobStore
				);
			}

			$arRevQuery = $revisionStore->getArchiveQueryInfo();
			$arRes = $dbw->select(
				$arRevQuery['tables'],
				$arRevQuery['fields'],
				[
					'ar_namespace' => $ns,
					'ar_title' => $t
				],
				__METHOD__,
				[],
				$arRevQuery['joins']
			);
			foreach ( $arRes as $arRow ) {
				$rev = $revisionStore->newRevisionFromArchiveRow( $arRow );
				$this->deleteSlotsPermanently( $dbw,
					$rev->getSlots()->getSlots(),
					$rev->getId(),
					$blobStore
				);
			}
		}

		# In the table 'revision' : Delete all the revision of the page where 'rev_page' = $id
		$dbw->delete( 'revision', [ 'rev_page' => $id ], __METHOD__ );

		# Delete image links
		$dbw->delete( 'imagelinks', [ 'il_from' => $id ], __METHOD__ );

		/*
		 * then delete entries which are not in direct relation with the page:
		 */

		# Clean up recentchanges entries...
		$dbw->delete( 'recentchanges', [
			'rc_namespace' => $ns,
			'rc_title' => $t
		], __METHOD__ );

		# Clean up archive entries...
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

		$dbw->delete( 'watchlist', [
			'wl_namespace' => MediaWikiServices::getInstance()
				->getNamespaceInfo()
				->getAssociated( $ns ),
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
				if ( !is_object( $cat ) ) {
					// Blank error to allow us to continue
				} else {
					DeferredUpdates::addCallableUpdate( [ $cat, 'refreshCounts' ] );
				}
			}
		}

		/*
		 * If an image is being deleted, some extra work needs to be done
		 */
		if ( $ns == NS_FILE ) {
			if ( method_exists( MediaWikiServices::class, 'getRepoGroup' ) ) {
				// MediaWiki 1.34+
				$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $t );
			} else {
				$file = wfFindFile( $t );
			}

			if ( $file ) {
				# Get all filenames of old versions:
				$fields = OldLocalFile::selectFields();
				$res = $dbw->select( 'oldimage', $fields, [ 'oi_name' => $t ] );

				foreach ( $res as $row ) {
					$oldLocalFile = OldLocalFile::newFromRow( $row, $file->repo );
					$path = $oldLocalFile->getArchivePath() . '/' . $oldLocalFile->getArchiveName();

					try {
						unlink( $path );
					} catch ( Exception $e ) {
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

			# Clean the filearchive for the given filename:
			$dbw->delete( 'filearchive', [ 'fa_name' => $t ], __METHOD__ );

			# Delete old db entries of the image:
			$dbw->delete( 'oldimage', [ 'oi_name' => $t ], __METHOD__ );

			# Delete archive entries of the image:
			$dbw->delete( 'filearchive', [ 'fa_name' => $t ], __METHOD__ );

			# Delete image entry:
			$dbw->delete( 'image', [ 'img_name' => $t ], __METHOD__ );

			// $dbw->endAtomic( __METHOD__ );

			$linkCache = MediaWikiServices::getInstance()->getLinkCache();
			$linkCache->clear();
		}
		$dbw->endAtomic( __METHOD__ );
		return true;
	}

	/**
	 * In MCR schema, delete the slots corresponding to some revision.
	 *
	 * @param IDatabase $dbw Database handle
	 * @param SlotRecord[] $slots Slots
	 * @param int $revId Revision ID
	 * @param BlobStore $blobStore MediaWiki service BlobStore
	 * @return bool true if the content can be deleted, false otherwise
	 */
	private function deleteSlotsPermanently( $dbw, $slots, $revId, $blobStore ) {
		foreach ( $slots as $role => $slot ) {
			if ( $this->shouldDeleteContent( $dbw, $revId, $slot->getContentId() ) ) {
				$textId = $blobStore->getTextIdFromAddress( $slot->getAddress() );
				if ( $textId ) {
					$dbw->delete( 'text', [ 'old_id' => $textId ], __METHOD__ );
				}
				$dbw->delete( 'content',
					[ 'content_id' => $slot->getContentId() ],
					__METHOD__
				);
			}
		}

		// This may orphan content types other than text
		$dbw->delete( 'slots',
			[ 'slot_revision_id' => $revId ],
			__METHOD__
		);
	}

	/**
	 * Determines if a particular piece of content should be deleted. Deleting requires querying
	 * if the content is used in any other revisions. This can be slow, and the caller will have
	 * a transaction open on the master database. Setting $wgDeletePagesForGoodDeleteContent to
	 * false is faster, because it skips the query and leaves the content alone. But it leaves
	 * orphaned content in storage.
	 *
	 * @param IDatabase $dbw Database handle
	 * @param int $revId Revision ID
	 * @param int $contentId Content ID to consider
	 * @return bool true if the content can be deleted, false otherwise
	 */
	private function shouldDeleteContent( $dbw, $revId, $contentId ) {
		global $wgDeletePagesForGoodDeleteContent;

		if ( !$wgDeletePagesForGoodDeleteContent ) {
			return false;
		}

		$count = $dbw->selectRowCount(
			'slots',
			'*',
			[
				'slot_content_id' => $contentId,
				"slot_revision_id != $revId"
			]
		);
		return $count == 0;
	}

	/**
	 * Returns the name that goes in the \<h1\> page title
	 *
	 * @return string
	 */
	protected function getPageTitle() {
		return $this->msg( 'deletepagesforgood-deletepagetitle', $this->getTitle()->getPrefixedText() );
	}

	/**
	 * @param HTMLForm $form
	 */
	protected function alterForm( HTMLForm $form ) {
		$title = $this->getTitle();
		$output = $this->getOutput();

		$output->addBacklinkSubtitle( $title );
		$form->addPreHtml( $this->msg( 'confirmdeletetext' )->parseAsBlock() );

		$form->addPreHtml(
			$this->msg( 'deletepagesforgood-ask_deletion' )->parseAsBlock()
		);

		$form->setSubmitTextMsg( 'deletepagesforgood-yes' );
	}

	/** @inheritDoc */
	public function getRestriction() {
		return 'deleteperm';
	}

	/**
	 * @return bool
	 */
	public function onSuccess() {
		$output = $this->getOutput();
		$output->addHTML( $this->msg( 'deletepagesforgood-del_done' )->escaped() );
		return false;
	}
}
