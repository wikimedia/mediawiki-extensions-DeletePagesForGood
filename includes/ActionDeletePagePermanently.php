<?php

namespace MediaWiki\Extension\DeletePagesForGood;

use MediaWiki\Actions\FormAction;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Skin\SkinTemplate;

class ActionDeletePagePermanently extends FormAction {

	/**
	 * @param SkinTemplate $sktemplate
	 * @param array &$links
	 */
	public static function onAddSkinHook( SkinTemplate $sktemplate, array &$links ) {
		if ( $sktemplate->getUser()->isAllowed( 'deleteperm' ) ) {
			$service = new DeletePagePermanently();
			$title = $sktemplate->getRelevantTitle();
			$action = self::getActionName( $sktemplate );

			if ( $service->canDeleteTitle( $title ) ) {
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
	 * @param mixed $data
	 * @return bool|string[]
	 */
	public function onSubmit( $data ) {
		$service = new DeletePagePermanently();
		if ( $service->canDeleteTitle( $this->getTitle() ) ) {
			$reason = $this->msg( 'deletepagesforgood-log-comment' )->text();
			$service->deletePermanently( $this->getTitle(), $this->getUser(), $reason );
			return true;
		} else {
			# $output->addHTML( $this->msg( 'deletepagesforgood-del_impossible' )->escaped() );
			return [ 'deletepagesforgood-del_impossible' ];
		}
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
