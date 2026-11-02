<?php
declare(strict_types=1);

namespace EasyMCP\ZipPostImport\Infrastructure\Repository;

use EasyMCP\ZipPostImport\Domain\Exception\ImportException;
use EasyMCP\ZipPostImport\Domain\Logger\LoggerInterface;
use EasyMCP\ZipPostImport\Domain\Repository\ZipArchiveRepositoryInterface;

final class PhpZipArchiveRepository implements ZipArchiveRepositoryInterface {

	private LoggerInterface $logger;

	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	public function extract( string $zip_path ): string {
		if ( ! is_readable( $zip_path ) ) {
			throw new ImportException( 'ZIP file is not readable.', 'zip_unreadable' );
		}

		$this->assert_allowed_path( $zip_path );
		$this->assert_zip_magic( $zip_path );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$dest = trailingslashit( get_temp_dir() ) . 'empzi-' . wp_generate_password( 12, false, false );
		if ( ! wp_mkdir_p( $dest ) ) {
			throw new ImportException( 'Could not create temp directory for ZIP.', 'zip_temp_failed' );
		}

		$this->logger->debug( 'Extracting ZIP.', [ 'zip' => $zip_path, 'dest' => $dest ] );

		$result = unzip_file( $zip_path, $dest );
		if ( is_wp_error( $result ) ) {
			$this->cleanup( $dest );
			throw new ImportException( $result->get_error_message(), 'zip_extract_failed' );
		}

		$this->assert_no_zip_slip( $dest );

		return $dest;
	}

	public function download_from_url( string $url ): string {
		$url = $this->assert_safe_remote_url( $url );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			throw new ImportException( $tmp->get_error_message(), 'zip_download_failed' );
		}

		$tmp = (string) $tmp;
		$max = 50 * 1024 * 1024;
		$size = filesize( $tmp );
		if ( $size === false || $size > $max ) {
			unlink( $tmp );
			throw new ImportException( 'ZIP is larger than 50 MB.', 'zip_too_large' );
		}
		if ( $size < 22 ) {
			unlink( $tmp );
			throw new ImportException( 'Downloaded file is too small to be a ZIP.', 'not_a_zip' );
		}

		try {
			$this->assert_zip_magic( $tmp );
		} catch ( ImportException $e ) {
			unlink( $tmp );
			throw $e;
		}

		$this->logger->debug( 'Downloaded ZIP from URL.', [ 'url' => $url, 'tmp' => $tmp, 'bytes' => $size ] );

		return $tmp;
	}

	private function assert_safe_remote_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( $url === '' ) {
			throw new ImportException( 'ZIP URL is empty.', 'invalid_zip_url' );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			throw new ImportException( 'Invalid ZIP URL.', 'invalid_zip_url' );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( $scheme !== 'https' && $scheme !== 'http' ) {
			throw new ImportException( 'ZIP URL must be http or https.', 'invalid_zip_url' );
		}

		$host = strtolower( (string) $parts['host'] );
		if ( $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || substr( $host, -6 ) === '.local' ) {
			throw new ImportException( 'ZIP URL host is not allowed.', 'zip_url_forbidden' );
		}

		$ip = gethostbyname( $host );
		if ( is_string( $ip ) && $ip !== $host && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, $flags ) ) {
				throw new ImportException( 'ZIP URL points to a private network.', 'zip_url_forbidden' );
			}
		}

		if ( ! wp_http_validate_url( $url ) ) {
			throw new ImportException( 'Invalid ZIP URL.', 'invalid_zip_url' );
		}

		return $url;
	}

	public function cleanup( string $directory ): void {
		if ( $directory === '' || ! is_dir( $directory ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			$path = $file->getPathname();
			if ( $file->isDir() ) {
				rmdir( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $directory );
		$this->logger->debug( 'Cleaned temp directory.', [ 'dir' => $directory ] );
	}

	public function delete_source( string $zip_path ): void {
		if ( $zip_path === '' || ! file_exists( $zip_path ) ) {
			return;
		}

		$this->assert_allowed_path( $zip_path );

		if ( ! unlink( $zip_path ) ) {
			$this->logger->error( 'Could not delete source ZIP after import.', [ 'zip' => $zip_path ] );
			return;
		}

		$this->logger->debug( 'Deleted source ZIP.', [ 'zip' => $zip_path ] );
	}

	private function assert_allowed_path( string $zip_path ): void {
		$real = realpath( $zip_path );
		if ( $real === false ) {
			throw new ImportException( 'ZIP path is invalid.', 'zip_invalid_path' );
		}

		$roots = [
			realpath( get_temp_dir() ),
			realpath( sys_get_temp_dir() ),
			realpath( (string) ini_get( 'upload_tmp_dir' ) ),
			realpath( wp_upload_dir()['basedir'] ),
		];

		foreach ( $roots as $root ) {
			if ( is_string( $root ) && $root !== '' && strpos( $real, $root ) === 0 ) {
				return;
			}
		}

		throw new ImportException( 'zip_path must be inside uploads or a temp directory.', 'zip_path_forbidden' );
	}

	private function assert_zip_magic( string $zip_path ): void {
		$handle = fopen( $zip_path, 'rb' );
		if ( $handle === false ) {
			throw new ImportException( 'ZIP file is not readable.', 'zip_unreadable' );
		}
		$magic = fread( $handle, 4 );
		fclose( $handle );
		if ( $magic !== "PK\x03\x04" && $magic !== "PK\x05\x06" && $magic !== "PK\x07\x08" ) {
			throw new ImportException( 'File is not a ZIP archive.', 'not_a_zip' );
		}
	}

	private function assert_no_zip_slip( string $dest ): void {
		$real_dest = realpath( $dest );
		if ( $real_dest === false ) {
			throw new ImportException( 'Extracted ZIP path is invalid.', 'zip_invalid_path' );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dest, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$real = realpath( $file->getPathname() );
			if ( $real === false || strpos( $real, $real_dest ) !== 0 ) {
				throw new ImportException( 'ZIP contains files outside the extract directory.', 'zip_slip' );
			}
		}
	}
}
