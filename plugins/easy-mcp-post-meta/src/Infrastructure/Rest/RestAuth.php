<?php
declare(strict_types=1);

namespace EasyMCP\PostMeta\Infrastructure\Rest;

use EasyMCP\PostMeta\Domain\Logger\LoggerInterface;

final class RestAuth {

	private LoggerInterface $logger;
	private string $last_fail = '';

	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	public function register(): void {
		$this->restore_authorization_header();
		add_filter( 'wp_is_application_passwords_available', [ $this, 'allow_when_helper_headers' ], 20 );
		add_filter( 'application_password_is_api_request', [ $this, 'mark_api_request' ], 20 );
		add_filter( 'determine_current_user', [ $this, 'user_from_headers' ], 21 );
	}

	public function allow_when_helper_headers( $available ) {
		return $this->credentials() !== null ? true : $available;
	}

	public function mark_api_request( $is_api ) {
		return $this->credentials() !== null ? true : $is_api;
	}

	/**
	 * @param int|false $user_id
	 * @return int|false
	 */
	public function user_from_headers( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		$user = $this->login_from_request();
		return $user instanceof \WP_User ? (int) $user->ID : $user_id;
	}

	public function ensure_logged_in(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$user = $this->login_from_request();
		if ( $user instanceof \WP_User ) {
			wp_set_current_user( (int) $user->ID );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function debug_data(): array {
		$creds = $this->credentials();
		return [
			'logged_in'     => is_user_logged_in(),
			'user_id'       => get_current_user_id(),
			'ssl'           => is_ssl(),
			'has_basic'     => $this->header( 'Authorization' ) !== '' || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ),
			'has_empm_user' => $this->header( 'X-EMPM-User' ) !== '',
			'has_empm_pass' => $this->application_password() !== '',
			'got_login'     => $creds !== null,
			'fail'          => $this->last_fail !== '' ? $this->last_fail : null,
		];
	}

	public function login_from_request(): ?\WP_User {
		$creds = $this->credentials();
		if ( $creds === null ) {
			$this->last_fail = 'no_credentials';
			return null;
		}
		$user = $this->authenticate( $creds['user'], $creds['pass'] );
		if ( $user instanceof \WP_User ) {
			$this->last_fail = '';
			$this->logger->debug( 'REST auth as user.', [ 'user_id' => (int) $user->ID ] );
			return $user;
		}
		return null;
	}

	private function restore_authorization_header(): void {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return;
		}
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$_SERVER['HTTP_AUTHORIZATION'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
			return;
		}
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( (string) $_SERVER['PHP_AUTH_USER'] . ':' . (string) $_SERVER['PHP_AUTH_PW'] );
			return;
		}
		$from_headers = $this->header( 'Authorization' );
		if ( $from_headers !== '' ) {
			$_SERVER['HTTP_AUTHORIZATION'] = $from_headers;
		}
	}

	/**
	 * @return array{user:string,pass:string}|null
	 */
	private function credentials(): ?array {
		$login = $this->header( 'X-EMPM-User' );
		$pass  = $this->application_password();
		if ( $login !== '' && $pass !== '' ) {
			return [ 'user' => $login, 'pass' => $pass ];
		}
		return $this->basic_credentials();
	}

	private function application_password(): string {
		return str_replace( ' ', '', $this->header( 'X-EMPM-App-Password' ) );
	}

	/**
	 * @return array{user:string,pass:string}|null
	 */
	private function basic_credentials(): ?array {
		$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : $this->header( 'Authorization' );
		if ( stripos( $header, 'basic ' ) !== 0 ) {
			return null;
		}
		$decoded = base64_decode( trim( substr( $header, 6 ) ), true );
		if ( ! is_string( $decoded ) || strpos( $decoded, ':' ) === false ) {
			return null;
		}
		$parts = explode( ':', $decoded, 2 );
		$user  = trim( $parts[0] );
		$pass  = str_replace( ' ', '', trim( $parts[1] ) );
		if ( $user === '' || $pass === '' ) {
			return null;
		}
		return [ 'user' => $user, 'pass' => $pass ];
	}

	private function authenticate( string $login, string $password ): ?\WP_User {
		$password = preg_replace( '/[^a-z0-9]/i', '', $password );
		if ( ! is_string( $password ) || $password === '' ) {
			$this->last_fail = 'empty_password';
			return null;
		}
		$user = get_user_by( 'login', $login );
		if ( ! $user instanceof \WP_User && is_email( $login ) ) {
			$user = get_user_by( 'email', $login );
		}
		if ( ! $user instanceof \WP_User ) {
			$this->last_fail = 'user_not_found';
			$this->logger->error( 'REST auth: user not found.' );
			return null;
		}
		if ( function_exists( 'wp_authenticate_application_password' ) ) {
			$result = wp_authenticate_application_password( null, $user->user_login, $password );
			if ( $result instanceof \WP_User ) {
				return $result;
			}
		}
		if ( class_exists( '\WP_Application_Passwords' ) && method_exists( '\WP_Application_Passwords', 'get_user_application_passwords' ) ) {
			$stored = \WP_Application_Passwords::get_user_application_passwords( (int) $user->ID );
			if ( is_array( $stored ) ) {
				foreach ( $stored as $item ) {
					$hash = isset( $item['password'] ) ? (string) $item['password'] : '';
					if ( $hash === '' ) {
						continue;
					}
					$ok = false;
					if ( method_exists( '\WP_Application_Passwords', 'check_password' ) ) {
						$ok = \WP_Application_Passwords::check_password( $password, $hash );
					} elseif ( function_exists( 'wp_check_password' ) ) {
						$ok = wp_check_password( $password, $hash );
					}
					if ( $ok ) {
						return $user;
					}
				}
			}
			$this->last_fail = 'bad_app_password';
			$this->logger->error( 'REST auth: application password mismatch.' );
			return null;
		}
		$this->last_fail = 'app_passwords_unavailable';
		return null;
	}

	private function header( string $name ): string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		if ( ! empty( $_SERVER[ $key ] ) ) {
			return trim( (string) $_SERVER[ $key ] );
		}
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $header_name => $value ) {
					if ( strtolower( (string) $header_name ) === strtolower( $name ) ) {
						return trim( (string) $value );
					}
				}
			}
		}
		return '';
	}
}
