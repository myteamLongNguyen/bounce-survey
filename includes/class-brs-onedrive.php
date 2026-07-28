<?php
/**
 * Pushes an up-to-date CSV of all submissions into a OneDrive folder via the
 * Microsoft Graph API, in the background right after each new submission.
 *
 * Uses the OAuth2 authorization-code flow, acting as whichever Microsoft
 * account was connected on the OneDrive Sync settings screen (delegated
 * permission, Files.ReadWrite) - so it can only ever reach folders that
 * account already has access to, same as opening onedrive.com in a browser.
 * No credentials belonging to the survey respondent, or to whoever owns the
 * destination folder, are ever involved.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BRS_OneDrive {

	const SETTINGS_OPTION = 'brs_onedrive_settings';
	const TOKEN_OPTION     = 'brs_onedrive_token';
	const STATE_TRANSIENT  = 'brs_onedrive_oauth_state';
	const SCOPE            = 'offline_access Files.ReadWrite';

	public static function settings() {
		return wp_parse_args(
			get_option( self::SETTINGS_OPTION, array() ),
			array(
				'tenant_id'     => '',
				'client_id'     => '',
				'client_secret' => '',
				'folder_link'   => '',
			)
		);
	}

	public static function is_configured() {
		$s = self::settings();
		return $s['tenant_id'] && $s['client_id'] && $s['client_secret'];
	}

	public static function is_connected() {
		$token = get_option( self::TOKEN_OPTION, array() );
		return ! empty( $token['refresh_token'] );
	}

	public static function redirect_uri() {
		return admin_url( 'admin.php?page=brs-onedrive&brs_oauth_callback=1' );
	}

	/**
	 * Sends the connecting user to Microsoft's own sign-in and consent
	 * screen. They land back on redirect_uri() afterwards, with a ?code= to
	 * exchange or an ?error= to show.
	 */
	public static function authorize_url() {
		$s     = self::settings();
		$state = wp_generate_password( 32, false );
		set_transient( self::STATE_TRANSIENT, $state, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'     => $s['client_id'],
			'response_type' => 'code',
			'redirect_uri'  => self::redirect_uri(),
			'response_mode' => 'query',
			'scope'         => self::SCOPE,
			'state'         => $state,
		);

		// "organizations", not the configured tenant_id: hitting a specific
		// tenant's endpoint requires the signing-in account to already exist
		// there (member or pre-provisioned guest) even for a multi-tenant app
		// registration - it blocks the ad hoc guest sign-in this needs, since
		// the destination folder usually lives in a different tenant than
		// wherever this app was registered.
		return 'https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize?' . http_build_query( $params );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function handle_callback( $code, $state ) {
		$expected = get_transient( self::STATE_TRANSIENT );
		delete_transient( self::STATE_TRANSIENT );

		if ( ! $expected || ! hash_equals( $expected, (string) $state ) ) {
			return new WP_Error( 'brs_onedrive_state', 'That sign-in link had expired or did not match - please try Connect again.' );
		}

		$s = self::settings();

		$response = wp_remote_post(
			self::token_endpoint(),
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $s['client_id'],
					'client_secret' => $s['client_secret'],
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => self::redirect_uri(),
					'scope'         => self::SCOPE,
				),
			)
		);

		return self::store_token_response( $response );
	}

	// Must match the issuer used in authorize_url() above - "organizations",
	// not the configured tenant_id.
	private static function token_endpoint() {
		return 'https://login.microsoftonline.com/organizations/oauth2/v2.0/token';
	}

	private static function store_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			$message = isset( $body['error_description'] ) ? $body['error_description'] : 'Microsoft did not return an access token.';
			return new WP_Error( 'brs_onedrive_token', $message );
		}

		update_option(
			self::TOKEN_OPTION,
			array(
				'access_token'  => $body['access_token'],
				// A missing refresh_token would mean offline_access was not
				// actually granted this time - keep the previous one rather
				// than silently losing the ability to refresh later.
				'refresh_token' => ! empty( $body['refresh_token'] ) ? $body['refresh_token'] : self::current_refresh_token(),
				'expires_at'    => time() + (int) ( isset( $body['expires_in'] ) ? $body['expires_in'] : 3600 ) - 60,
			),
			false
		);

		return true;
	}

	private static function current_refresh_token() {
		$token = get_option( self::TOKEN_OPTION, array() );
		return isset( $token['refresh_token'] ) ? $token['refresh_token'] : '';
	}

	/**
	 * @return string|WP_Error
	 */
	private static function get_access_token() {
		$token = get_option( self::TOKEN_OPTION, array() );

		if ( empty( $token['refresh_token'] ) ) {
			return new WP_Error( 'brs_onedrive_not_connected', 'OneDrive is not connected yet.' );
		}

		if ( ! empty( $token['access_token'] ) && ! empty( $token['expires_at'] ) && time() < $token['expires_at'] ) {
			return $token['access_token'];
		}

		$s        = self::settings();
		$response = wp_remote_post(
			self::token_endpoint(),
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $s['client_id'],
					'client_secret' => $s['client_secret'],
					'grant_type'    => 'refresh_token',
					'refresh_token' => $token['refresh_token'],
					'scope'         => self::SCOPE,
				),
			)
		);

		$result = self::store_token_response( $response );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$fresh = get_option( self::TOKEN_OPTION, array() );
		return $fresh['access_token'];
	}

	/**
	 * Which Microsoft account is actually behind "Connected" - shown on the
	 * settings screen so a mismatch (connected as the wrong identity, or an
	 * identity that doesn't have edit access to the destination folder) is
	 * obvious at a glance instead of only surfacing later as a cryptic
	 * "access denied" on test push.
	 *
	 * @return string|WP_Error
	 */
	public static function connected_account() {
		$token = self::get_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get(
			'https://graph.microsoft.com/v1.0/me?$select=displayName,userPrincipalName,mail',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['userPrincipalName'] ) && empty( $body['mail'] ) ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Could not read the connected account.';
			return new WP_Error( 'brs_onedrive_whoami', $message );
		}

		$email = ! empty( $body['mail'] ) ? $body['mail'] : $body['userPrincipalName'];
		$name  = ! empty( $body['displayName'] ) ? $body['displayName'] : '';

		return $name ? "$name ($email)" : $email;
	}

	/**
	 * Turns any OneDrive/SharePoint web link into the encoded form the
	 * "shares" API expects. Documented by Microsoft as: base64-encode the
	 * URL, make it URL-safe, drop the padding, prefix "u!".
	 */
	private static function encode_share_url( $url ) {
		$b64 = base64_encode( $url );
		$b64 = rtrim( $b64, '=' );
		$b64 = str_replace( array( '+', '/' ), array( '-', '_' ), $b64 );
		return 'u!' . $b64;
	}

	/**
	 * Resolves the folder link saved in settings to the driveId/itemId pair
	 * Graph actually addresses things by. This one call is what lets the same
	 * code reach a personal OneDrive folder AND a SharePoint team site
	 * document library folder - both come back in the same shape, so nothing
	 * downstream needs to know or guess which kind of link it was.
	 *
	 * @return array{drive_id:string,item_id:string}|WP_Error
	 */
	private static function resolve_folder( $token ) {
		$s = self::settings();

		if ( ! $s['folder_link'] ) {
			return new WP_Error( 'brs_onedrive_no_folder', 'No folder link has been saved yet.' );
		}

		$share_id = self::encode_share_url( $s['folder_link'] );

		$response = wp_remote_get(
			'https://graph.microsoft.com/v1.0/shares/' . $share_id . '/driveItem',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['id'] ) || empty( $body['parentReference']['driveId'] ) ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Could not resolve that folder link - check it was copied correctly and this account still has access.';
			return new WP_Error( 'brs_onedrive_resolve', $message );
		}

		return array(
			'drive_id' => $body['parentReference']['driveId'],
			'item_id'  => $body['id'],
		);
	}

	/**
	 * Uploads (overwrites) one file in the configured folder. Submissions are
	 * few enough, and the CSV small enough, that re-uploading the whole
	 * export each time is simpler and safer than trying to append to an
	 * existing file over the API.
	 *
	 * @return true|WP_Error
	 */
	public static function push_csv( $csv_string, $filename = 'bounce-survey.csv' ) {
		$token = self::get_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$folder = self::resolve_folder( $token );

		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		$url = sprintf(
			'https://graph.microsoft.com/v1.0/drives/%s/items/%s:/%s:/content',
			rawurlencode( $folder['drive_id'] ),
			rawurlencode( $folder['item_id'] ),
			rawurlencode( $filename )
		);

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'text/csv',
				),
				'body'    => $csv_string,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'Upload failed with status ' . $code );
			return new WP_Error( 'brs_onedrive_upload', $message );
		}

		return true;
	}

	/**
	 * Fired via wp_schedule_single_event() right after a submission is
	 * saved, so the person submitting the form never waits on a call to
	 * Microsoft. Failures are logged, never surfaced to a respondent - the
	 * submission itself is already safely stored in the database regardless
	 * of whether this succeeds.
	 */
	public static function do_push() {
		if ( ! self::is_connected() ) {
			return;
		}

		$result = self::push_csv( BRS_Admin::build_csv_string() );

		if ( is_wp_error( $result ) ) {
			error_log( 'Bounce Survey OneDrive push failed: ' . $result->get_error_message() );
		}
	}
}
