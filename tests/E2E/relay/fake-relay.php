<?php
/**
 * Dev only: a stand-in for the LW sign-in service and for Dropbox's API (`php -S` router).
 *
 * Served behind the TLS front of bin/test-cloud.sh (service relay), one worker, so
 * requests run one at a time; the state is one JSON file under /tmp.
 * - lwm-relay.invalid: GET /v1/authorize checks the parameters and the return address
 *   (spec auth-lwplugins-com §2.1, §2.5; http only for the hosts in
 *   RELAY_ALLOW_HTTP_HOSTS) and answers as if the provider had consented: 302 to the
 *   return address with lwm_relay=1, a code, the site's state and a relay state that
 *   carries the provider and the PKCE challenge. These authorize and return-address
 *   checks are a happy-path subset of the relay's rule (enough to catch a plugin that
 *   sends the wrong shape), not a copy of it; the plugin's own RelayAddress tests pin
 *   the full rule. POST /v1/token and POST /v1/refresh want Content-Type
 *   application/json and exactly the relay's body keys (token: v, provider, code,
 *   code_verifier, relay_state; refresh: v, provider, refresh_token), else 400
 *   {"error":"invalid_request"} like the relay. /v1/token checks the relay state, the
 *   code and the verifier against the challenge (the relay's own PKCE check);
 *   /v1/refresh accepts rt-1, or answers the error set by the test control
 *   (rate_limited with Retry-After, like the relay).
 *   GET /_e2e/stats and POST /_e2e/control {"refresh": "<error code>" or ""} are the
 *   test's own.
 * - api.dropboxapi.com, content.dropboxapi.com: users/get_current_account, the upload
 *   session (start, append_v2, finish), files/download with a byte range,
 *   files/delete_v2. The first access token (at-1) is accepted only by
 *   users/get_current_account (the connect's account check), so the first other request
 *   gets 401 and the plugin refreshes once.
 *
 * @package LightweightPlugins\Migration
 */

const LWM_STATE = '/tmp/lwm-fake-relay.json';

function lwm_state(): array {
	$state = is_file( LWM_STATE ) ? json_decode( (string) file_get_contents( LWM_STATE ), true ) : null;

	return is_array( $state ) ? $state : [ 'tokens' => [], 'refreshes' => 0, 'refreshError' => '', 'sessions' => [], 'files' => [] ];
}

function lwm_save( array $state ): void {
	file_put_contents( LWM_STATE, (string) json_encode( $state ) );
}

function lwm_json( int $status, $body ): bool {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	header( 'Cache-Control: no-store' );
	echo json_encode( $body, JSON_UNESCAPED_SLASHES );

	return true;
}

function lwm_b64( string $bytes ): string {
	return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
}

function lwm_header( string $name ): string {
	foreach ( getallheaders() as $key => $value ) {
		if ( 0 === strcasecmp( (string) $key, $name ) ) {
			return (string) $value;
		}
	}

	return '';
}

/**
 * The JSON object of a /v1/token or /v1/refresh body when the request is one the relay
 * decodes: Content-Type application/json and exactly these keys (the relay refuses an
 * unknown field and a missing one alike).
 *
 * @param string   $body Raw body.
 * @param string[] $keys The endpoint's keys.
 * @return array<string, mixed>|null The body, or null for an invalid_request.
 */
function lwm_body( string $body, array $keys ): ?array {
	$type = strtolower( trim( explode( ';', lwm_header( 'Content-Type' ) )[0] ) );
	$in   = 'application/json' === $type ? json_decode( $body, true ) : null;
	if ( ! is_array( $in ) ) {
		return null;
	}
	$have = array_keys( $in );
	sort( $have );
	sort( $keys );

	return $have === $keys ? $in : null;
}

function lwm_return_ok( string $return ): bool {
	$allowed = array_values( array_filter( explode( ',', (string) getenv( 'RELAY_ALLOW_HTTP_HOSTS' ) ) ) );
	$p       = strlen( $return ) > 2048 ? false : parse_url( $return );
	if ( ! is_array( $p ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['fragment'] ) || 443 !== ( $p['port'] ?? 443 ) ) {
		return false;
	}
	$host   = (string) ( $p['host'] ?? '' );
	$scheme = (string) ( $p['scheme'] ?? '' );
	if ( 'https' !== $scheme && ! ( 'http' === $scheme && in_array( $host, $allowed, true ) ) ) {
		return false;
	}
	if ( false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) || $host !== strtolower( $host ) ) {
		return false;
	}
	$path  = (string) ( $p['path'] ?? '' );
	$query = $p['query'] ?? null;

	return ( null === $query && str_ends_with( $path, '/wp-json/lw-migration/v1/oauth/callback' ) )
		|| ( 'rest_route=/lw-migration/v1/oauth/callback' === $query && ( str_ends_with( $path, '/' ) || str_ends_with( $path, '/index.php' ) ) );
}

function lwm_relay( string $method, string $path, string $body, array $state ): bool {
	if ( 'GET' === $method && '/v1/authorize' === $path ) {
		$q     = array_map( 'strval', array_filter( $_GET, 'is_string' ) );
		$need  = [ 'v', 'provider', 'return', 'state', 'code_challenge', 'code_challenge_method' ];
		$known = array_merge( $need, [ 'access' ] );
		$ok    = count( $q ) === count( $_GET ) && [] === array_diff( $need, array_keys( $q ) ) && [] === array_diff( array_keys( $q ), $known )
			&& '1' === $q['v'] && in_array( $q['provider'], [ 'google', 'dropbox', 'microsoft' ], true )
			&& 1 === preg_match( '/^[A-Za-z0-9._~-]{16,256}$/', $q['state'] )
			&& 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $q['code_challenge'] )
			&& 'S256' === $q['code_challenge_method']
			&& ( 'microsoft' === $q['provider'] ? 'appfolder' === ( $q['access'] ?? '' ) : ! isset( $q['access'] ) )
			&& lwm_return_ok( $q['return'] );
		if ( ! $ok ) {
			http_response_code( 400 );
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<p>This sign-in request was refused.</p>';
			return true;
		}
		$relayState = lwm_b64( (string) json_encode( [ 'p' => $q['provider'], 'c' => $q['code_challenge'] ] ) ) . '.ZTJl';
		$back       = [ 'lwm_relay' => '1', 'code' => 'e2e-code', 'state' => $q['state'], 'relay_state' => $relayState ];
		header( 'Location: ' . $q['return'] . ( str_contains( $q['return'], '?' ) ? '&' : '?' ) . http_build_query( $back, '', '&', PHP_QUERY_RFC3986 ), true, 302 );
		return true;
	}
	if ( 'POST' === $method && '/v1/token' === $path ) {
		$in = lwm_body( $body, [ 'v', 'provider', 'code', 'code_verifier', 'relay_state' ] );
		if ( null === $in ) {
			return lwm_json( 400, [ 'error' => 'invalid_request' ] );
		}
		$parts    = explode( '.', (string) $in['relay_state'] );
		$payload  = 2 === count( $parts ) ? json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ) ), true ) : null;
		$verifier = is_string( $in['code_verifier'] ) ? $in['code_verifier'] : '';
		if ( 1 !== $in['v'] || ! is_array( $payload ) || $in['provider'] !== ( $payload['p'] ?? null ) || 'e2e-code' !== $in['code']
			|| 1 !== preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) || ! hash_equals( (string) ( $payload['c'] ?? '' ), lwm_b64( hash( 'sha256', $verifier, true ) ) ) ) {
			return lwm_json( 400, [ 'error' => 'invalid_request' ] );
		}
		$state['tokens']['at-1'] = 'connect';
		lwm_save( $state );
		return lwm_json( 200, [ 'access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 14400, 'token_type' => 'bearer', 'scope' => 'account_info.read files.content.read files.content.write files.metadata.read' ] );
	}
	if ( 'POST' === $method && '/v1/refresh' === $path ) {
		$in = lwm_body( $body, [ 'v', 'provider', 'refresh_token' ] );
		if ( null === $in ) {
			return lwm_json( 400, [ 'error' => 'invalid_request' ] );
		}
		if ( '' !== $state['refreshError'] ) {
			$status = [ 'rate_limited' => 429, 'invalid_grant' => 400, 'invalid_request' => 400 ][ $state['refreshError'] ] ?? 503;
			if ( 429 === $status ) {
				header( 'Retry-After: 1' );
			}
			return lwm_json( $status, [ 'error' => $state['refreshError'] ] );
		}
		if ( 1 !== $in['v'] || 'dropbox' !== $in['provider'] || 'rt-1' !== $in['refresh_token'] ) {
			return lwm_json( 400, [ 'error' => 'invalid_grant' ] );
		}
		++$state['refreshes'];
		$token                     = 'at-' . ( 1 + $state['refreshes'] );
		$state['tokens'][ $token ] = 'all';
		lwm_save( $state );
		return lwm_json( 200, [ 'access_token' => $token, 'expires_in' => 14400, 'token_type' => 'bearer' ] );
	}
	if ( 'GET' === $method && '/_e2e/stats' === $path ) {
		return lwm_json( 200, [ 'refreshes' => $state['refreshes'] ] );
	}
	$in = json_decode( $body, true );
	if ( 'POST' === $method && '/_e2e/control' === $path ) {
		$state['refreshError'] = is_array( $in ) && is_string( $in['refresh'] ?? null ) ? $in['refresh'] : '';
		lwm_save( $state );
		return lwm_json( 200, [ 'ok' => true ] );
	}

	return lwm_json( 404, [ 'error' => 'invalid_request' ] );
}

function lwm_dropbox( string $host, string $route, string $body, array $state ): bool {
	$auth  = lwm_header( 'Authorization' );
	$token = str_starts_with( $auth, 'Bearer ' ) ? substr( $auth, 7 ) : '';
	$scope = $state['tokens'][ $token ] ?? null;
	if ( null === $scope || ( 'connect' === $scope && 'users/get_current_account' !== $route ) ) {
		return lwm_json( 401, [ 'error_summary' => 'expired_access_token/', 'error' => [ '.tag' => 'expired_access_token' ] ] );
	}
	$arg = json_decode( lwm_header( 'Dropbox-API-Arg' ), true );
	$arg = is_array( $arg ) ? $arg : [];
	$in  = json_decode( $body, true );
	if ( 'api.dropboxapi.com' === $host && 'users/get_current_account' === $route ) {
		return lwm_json( 200, [ 'email' => 'e2e@lwm-dropbox.invalid' ] );
	}
	if ( 'api.dropboxapi.com' === $host && 'files/delete_v2' === $route ) {
		foreach ( $state['files'] as $name => $file ) {
			if ( is_array( $in ) && $file['id'] === ( $in['path'] ?? null ) ) {
				unset( $state['files'][ $name ] );
				lwm_save( $state );
				return lwm_json( 200, [ 'metadata' => [ '.tag' => 'file', 'name' => $name ] ] );
			}
		}
		return lwm_json( 409, [ 'error_summary' => 'path_lookup/not_found/', 'error' => [ '.tag' => 'path_lookup', 'path_lookup' => [ '.tag' => 'not_found' ] ] ] );
	}
	if ( 'content.dropboxapi.com' === $host && 'files/upload_session/start' === $route ) {
		$id                       = 'sess-' . count( $state['sessions'] ) . '-' . bin2hex( random_bytes( 4 ) );
		$state['sessions'][ $id ] = base64_encode( $body );
		lwm_save( $state );
		return lwm_json( 200, [ 'session_id' => $id ] );
	}
	$id   = (string) ( $arg['cursor']['session_id'] ?? '' );
	$have = isset( $state['sessions'][ $id ] ) ? (string) base64_decode( $state['sessions'][ $id ] ) : null;
	if ( 'content.dropboxapi.com' === $host && 'files/upload_session/append_v2' === $route ) {
		if ( null === $have ) {
			return lwm_json( 409, [ 'error_summary' => 'not_found/', 'error' => [ '.tag' => 'not_found' ] ] );
		}
		if ( (int) ( $arg['cursor']['offset'] ?? -1 ) !== strlen( $have ) ) {
			return lwm_json( 409, [ 'error_summary' => 'incorrect_offset/', 'error' => [ '.tag' => 'incorrect_offset', 'correct_offset' => strlen( $have ) ] ] );
		}
		$state['sessions'][ $id ] = base64_encode( $have . $body );
		lwm_save( $state );
		return lwm_json( 200, null );
	}
	if ( 'content.dropboxapi.com' === $host && 'files/upload_session/finish' === $route && null !== $have ) {
		$name                    = ltrim( (string) ( $arg['commit']['path'] ?? '' ), '/' );
		$file                    = [ 'id' => 'id:e2e' . count( $state['files'] ) . bin2hex( random_bytes( 4 ) ), 'data' => base64_encode( $have ) ];
		$state['files'][ $name ] = $file;
		unset( $state['sessions'][ $id ] );
		lwm_save( $state );
		return lwm_json( 200, [ '.tag' => 'file', 'name' => $name, 'id' => $file['id'], 'size' => strlen( $have ), 'server_modified' => gmdate( 'Y-m-d\TH:i:s\Z' ) ] );
	}
	if ( 'content.dropboxapi.com' === $host && 'files/download' === $route ) {
		foreach ( $state['files'] as $file ) {
			if ( $file['id'] !== ( $arg['path'] ?? null ) ) {
				continue;
			}
			$data = (string) base64_decode( $file['data'] );
			if ( 1 !== preg_match( '/^bytes=(\d+)-(\d+)$/', lwm_header( 'Range' ), $m ) ) {
				header( 'Content-Type: application/octet-stream' );
				echo $data;
				return true;
			}
			$to = min( (int) $m[2], strlen( $data ) - 1 );
			http_response_code( 206 );
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Range: bytes ' . (int) $m[1] . '-' . $to . '/' . strlen( $data ) );
			echo substr( $data, (int) $m[1], $to - (int) $m[1] + 1 );
			return true;
		}
		return lwm_json( 409, [ 'error_summary' => 'path/not_found/', 'error' => [ '.tag' => 'path', 'path' => [ '.tag' => 'not_found' ] ] ] );
	}

	return lwm_json( 400, [ 'error_summary' => 'unsupported/' ] );
}

$lwm_host   = strtolower( (string) preg_replace( '/:\d+$/', '', (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ) );
$lwm_path   = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
$lwm_method = (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
$lwm_body   = (string) file_get_contents( 'php://input' );

if ( 'lwm-relay.invalid' === $lwm_host ) {
	return lwm_relay( $lwm_method, $lwm_path, $lwm_body, lwm_state() );
}
if ( in_array( $lwm_host, [ 'api.dropboxapi.com', 'content.dropboxapi.com' ], true ) && str_starts_with( $lwm_path, '/2/' ) ) {
	return lwm_dropbox( $lwm_host, substr( $lwm_path, 3 ), $lwm_body, lwm_state() );
}

return lwm_json( 404, [ 'error' => 'unknown host' ] );
