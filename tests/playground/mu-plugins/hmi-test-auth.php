<?php
/**
 * TEST ONLY – mounted into WordPress Playground by tests/playground/run.mjs.
 * Authenticates REST requests carrying "X-HMI-Test-User: <id>" as that user.
 * Never install this on a real site.
 */

add_filter(
	'determine_current_user',
	static function ( $user_id ) {
		if ( ! empty( $_SERVER['HTTP_X_HMI_TEST_USER'] ) ) {
			return (int) $_SERVER['HTTP_X_HMI_TEST_USER'];
		}
		return $user_id;
	},
	99
);
