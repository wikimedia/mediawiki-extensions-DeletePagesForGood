<?php

class DeletePagesForGoodHooks {

	public static function onDeletesPagesPermanently() {
		new DeletePagesForGood();

		return true;
	}
}
