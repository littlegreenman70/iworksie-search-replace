<?php
/**
 * One-time hand-off from the validated form to the job.
 *
 * @package IWorksIE\SearchReplace
 */

namespace IWorksIE\SearchReplace\Support;

/**
 * The shared job runner sanitises its start parameters as plain text, which would
 * change search strings (tags, %xx sequences, extra spaces). So the validated
 * request is parked here and the job only receives a random token.
 */
final class Pending {

	const OPTION   = 'iwiesr_pending';
	const LIFETIME = 900;

	/**
	 * Park a request and return its token.
	 *
	 * @param array<string,mixed> $request mode + config|run_id (+ preview_id).
	 * @param int                 $user_id Current user.
	 * @return string
	 */
	public static function put( array $request, $user_id ) {
		$token             = wp_generate_password( 32, false, false );
		$request['token']  = $token;
		$request['user']   = (int) $user_id;
		$request['issued'] = time();
		update_option( self::OPTION, $request, false );
		return $token;
	}

	/**
	 * Take (and delete) a parked request if the token, user and age match.
	 *
	 * @param string $token   Token.
	 * @param int    $user_id Current user.
	 * @return array<string,mixed>|null
	 */
	public static function take( $token, $user_id ) {
		$request = get_option( self::OPTION, null );
		if ( ! is_array( $request ) || empty( $request['token'] ) || '' === (string) $token ) {
			return null;
		}
		if ( ! hash_equals( (string) $request['token'], (string) $token ) ) {
			return null;
		}
		delete_option( self::OPTION );
		if ( (int) $request['user'] !== (int) $user_id || ( time() - (int) $request['issued'] ) > self::LIFETIME ) {
			return null;
		}
		return $request;
	}
}
