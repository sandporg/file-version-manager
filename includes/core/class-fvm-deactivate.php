<?php

namespace FVM\FileVersionManager;

class FVM_Deactivate {

	public static function deactivate() {
		flush_rewrite_rules();
	}
}