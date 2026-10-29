<?php
/**
 * Dev only: `php -S` router serving one file (LWM_RANGE_FILE) with byte-range support.
 *
 * @package LightweightPlugins\Migration
 */

$file = getenv( 'LWM_RANGE_FILE' );
if ( ! is_string( $file ) || ! is_file( $file ) ) {
	http_response_code( 404 );
	return true;
}
$size = (int) filesize( $file );
header( 'Content-Type: application/octet-stream' );
header( 'Accept-Ranges: bytes' );
$range = (string) ( $_SERVER['HTTP_RANGE'] ?? '' );
if ( 1 === preg_match( '/^bytes=(\d+)-(\d*)$/', $range, $m ) ) {
	$from = (int) $m[1];
	$to   = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
	http_response_code( 206 );
	header( "Content-Range: bytes {$from}-{$to}/{$size}" );
	header( 'Content-Length: ' . ( $to - $from + 1 ) );
	$h = fopen( $file, 'rb' );
	fseek( $h, $from );
	echo fread( $h, $to - $from + 1 );
	fclose( $h );
	return true;
}
header( 'Content-Length: ' . $size );
readfile( $file );
return true;
