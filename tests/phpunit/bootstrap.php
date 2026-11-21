<?php
/**
 * Standalone PHPUnit Bootstrap & Deterministic WordPress Test Harness.
 *
 * Allows running the full WPilot AI unit and security suite via `composer test`
 * without requiring a MySQL server or full WordPress core checkout, while exercising
 * real plugin classes, SQL conditions, capability checks, and post/meta persistence.
 *
 * @package WPilotAI\Tests
 */

declare(strict_types=1);

if ( file_exists( dirname( __DIR__, 2 ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			if ( str_starts_with( $class, 'WPilotAI\\' ) ) {
				$rel  = str_replace( '\\', '/', substr( $class, strlen( 'WPilotAI\\' ) ) );
				$file = dirname( __DIR__, 2 ) . '/includes/' . $rel . '.php';
				if ( file_exists( $file ) ) {
					require_once $file;
				}
			}
		}
	);
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'WPILOT_AI_VERSION' ) ) {
	define( 'WPILOT_AI_VERSION', '1.0.0' );
}
if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
	define( 'ELEMENTOR_VERSION', '3.25.4' );
}
if ( ! defined( 'RANK_MATH_VERSION' ) ) {
	define( 'RANK_MATH_VERSION', '1.0.231' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		/** @var mixed */
		private mixed $data;

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		/** @var array<string, mixed> */
		private array $params;
		/** @var array<string, mixed> */
		private array $json_params;

		/**
		 * @param array<string, mixed> $params
		 * @param array<string, mixed> $json_params
		 */
		public function __construct( array $params = array(), array $json_params = array() ) {
			$this->params      = $params;
			$this->json_params = $json_params;
		}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? $this->json_params[ $key ] ?? null;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_json_params(): array {
			return $this->json_params;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public mixed $data;
		public int $status;

		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WPilotTestProduct' ) ) {
	class WPilotTestProduct {
		private int $id;
		private string $name;
		private string $sku;
		private string $price;
		private string $stock_status;
		private string $short_description;
		private string $description;
		private int $image_id;
		private string $status;

		public function __construct(
			int $id,
			string $name,
			string $sku,
			string $price,
			string $short_description = '',
			string $description = '',
			string $stock_status = 'instock',
			int $image_id = 10,
			string $status = 'publish'
		) {
			$this->id                = $id;
			$this->name              = $name;
			$this->sku               = $sku;
			$this->price             = $price;
			$this->short_description = $short_description;
			$this->description       = $description;
			$this->stock_status      = $stock_status;
			$this->image_id          = $image_id;
			$this->status            = $status;
		}

		public function get_id(): int { return $this->id; }
		public function get_name(): string { return $this->name; }
		public function get_sku(): string { return $this->sku; }
		public function get_price(): string { return $this->price; }
		public function get_stock_status(): string { return $this->stock_status; }
		public function get_short_description(): string { return $this->short_description; }
		public function get_description(): string { return $this->description; }
		public function get_image_id(): int { return $this->image_id; }
		public function get_status(): string { return $this->status; }

		public function set_name( string $name ): void { $this->name = $name; }
		public function set_short_description( string $desc ): void { $this->short_description = $desc; }
		public function set_description( string $desc ): void { $this->description = $desc; }

		public function save(): int {
			WPTestState::$products[ $this->id ] = $this;
			if ( isset( WPTestState::$posts[ $this->id ] ) ) {
				WPTestState::$posts[ $this->id ]->post_title   = $this->name;
				WPTestState::$posts[ $this->id ]->post_excerpt = $this->short_description;
				WPTestState::$posts[ $this->id ]->post_content = $this->description;
			}
			return $this->id;
		}
	}
}

if ( ! class_exists( 'WPTestWpdb' ) ) {
	class WPTestWpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public bool $fail_next_insert = false;
		public bool $fail_next_update = false;

		/** @var array<string, array<int, array<string, mixed>>> */
		public array $tables = array();
		/** @var array<string, int> */
		private array $seq = array();

		public function reset(): void {
			$this->tables           = array();
			$this->seq              = array();
			$this->insert_id        = 0;
			$this->fail_next_insert = false;
			$this->fail_next_update = false;
		}

		public function prepare( string $query, mixed ...$args ): string {
			$index = 0;
			return (string) preg_replace_callback(
				'/%[dsi]/',
				static function ( array $m ) use ( &$index, $args ): string {
					$val = $args[ $index++ ] ?? '';
					if ( '%d' === $m[0] ) {
						return (string) (int) $val;
					}
					if ( '%i' === $m[0] ) {
						return (string) preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $val );
					}
					return "'" . str_replace( "'", "\\'", (string) $val ) . "'";
				},
				$query
			);
		}

		/**
		 * @param array<string, mixed> $data
		 */
		public function insert( string $table, array $data ): int|false {
			if ( $this->fail_next_insert ) {
				$this->fail_next_insert = false;
				$this->insert_id        = 0;
				return false;
			}
			$next                    = ( $this->seq[ $table ] ?? 0 ) + 1;
			$this->seq[ $table ]     = $next;
			$row                     = array_merge( array( 'id' => $next ), $data );
			$this->tables[ $table ][ $next ] = $row;
			$this->insert_id         = $next;
			return 1;
		}

		/**
		 * @param array<string, mixed> $data
		 * @param array<string, mixed> $where
		 */
		public function update( string $table, array $data, array $where ): int|false {
			if ( $this->fail_next_update ) {
				$this->fail_next_update = false;
				return false;
			}
			$count = 0;
			foreach ( $this->tables[ $table ] ?? array() as $id => $row ) {
				$match = true;
				foreach ( $where as $k => $v ) {
					if ( (string) ( $row[ $k ] ?? '' ) !== (string) $v ) {
						$match = false;
						break;
					}
				}
				if ( $match ) {
					$this->tables[ $table ][ $id ] = array_merge( $row, $data );
					++$count;
				}
			}
			return $count;
		}

		public function query( string $sql ): int|false {
			if ( $this->fail_next_update ) {
				$this->fail_next_update = false;
				return false;
			}
			// Atomic conditional UPDATE for approve_task / cancel_task.
			if ( preg_match( "/^UPDATE\s+(\S+)\s+SET\s+(.+)\s+WHERE\s+(.+)$/i", trim( $sql ), $m ) ) {
				$table     = $m[1];
				$set_str   = $m[2];
				$where_str = $m[3];

				$updates = array();
				foreach ( explode( ',', $set_str ) as $pair ) {
					if ( preg_match( "/^\s*(\w+)\s*=\s*(?:'((?:\\\\'|[^'])*)'|(-?\d+))\s*$/", trim( $pair ), $pm ) ) {
						$updates[ $pm[1] ] = isset( $pm[3] ) && '' !== $pm[3] ? (int) $pm[3] : str_replace( "\\'", "'", $pm[2] );
					}
				}

				$conditions  = array();
				$null_checks = array();
				foreach ( preg_split( '/\s+AND\s+/i', $where_str ) ?: array() as $cond ) {
					$trimmed_cond = trim( $cond );
					if ( preg_match( '/^(\w+)\s+IS\s+NULL$/i', $trimmed_cond, $nm ) ) {
						$null_checks[] = $nm[1];
					} elseif ( preg_match( "/^(\w+)\s*=\s*(?:'((?:\\\\'|[^'])*)'|(-?\d+))$/", $trimmed_cond, $cm ) ) {
						$conditions[ $cm[1] ] = isset( $cm[3] ) && '' !== $cm[3] ? (int) $cm[3] : str_replace( "\\'", "'", $cm[2] );
					}
				}

				$affected = 0;
				foreach ( $this->tables[ $table ] ?? array() as $id => $row ) {
					$match = true;
					foreach ( $null_checks as $null_col ) {
						if ( isset( $row[ $null_col ] ) && '' !== $row[ $null_col ] ) {
							$match = false;
							break;
						}
					}
					if ( $match ) {
						foreach ( $conditions as $k => $v ) {
							if ( (string) ( $row[ $k ] ?? '' ) !== (string) $v ) {
								$match = false;
								break;
							}
						}
					}
					if ( $match ) {
						$this->tables[ $table ][ $id ] = array_merge( $row, $updates );
						++$affected;
					}
				}
				return $affected;
			}
			return 0;
		}

		/**
		 * @return array<string, mixed>|null
		 */
		public function get_row( string $sql, string $output = 'ARRAY_A' ): ?array {
			$rows = $this->get_results( $sql, $output );
			return $rows[0] ?? null;
		}

		/**
		 * @return array<int, array<string, mixed>>
		 */
		public function get_results( string $sql, string $output = 'ARRAY_A' ): array {
			if ( ! preg_match( '/FROM\s+(\S+)/i', $sql, $tm ) ) {
				return array();
			}
			$table = $tm[1];
			$rows  = array_values( $this->tables[ $table ] ?? array() );

			if ( preg_match( '/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+LIMIT|$)/i', $sql, $wm ) ) {
				$conditions = array();
				foreach ( preg_split( '/\s+AND\s+/i', trim( $wm[1] ) ) ?: array() as $cond ) {
					if ( preg_match( "/^\s*(\w+)\s*=\s*(?:'((?:\\\\'|[^'])*)'|(-?\d+))\s*$/", trim( $cond ), $cm ) ) {
						$conditions[ $cm[1] ] = isset( $cm[3] ) && '' !== $cm[3] ? (int) $cm[3] : str_replace( "\\'", "'", $cm[2] );
					}
				}
				$rows = array_values(
					array_filter(
						$rows,
						static function ( array $row ) use ( $conditions ): bool {
							foreach ( $conditions as $k => $v ) {
								if ( (string) ( $row[ $k ] ?? '' ) !== (string) $v ) {
									return false;
								}
							}
							return true;
						}
					)
				);
			}

			if ( preg_match( '/ORDER\s+BY\s+(\w+)\s+(ASC|DESC)/i', $sql, $om ) ) {
				$col = $om[1];
				$dir = strtoupper( $om[2] );
				usort(
					$rows,
					static function ( array $a, array $b ) use ( $col, $dir ): int {
						$cmp = ( $a[ $col ] ?? 0 ) <=> ( $b[ $col ] ?? 0 );
						return 'DESC' === $dir ? -$cmp : $cmp;
					}
				);
			}

			if ( preg_match( '/LIMIT\s+(\d+)/i', $sql, $lm ) ) {
				$rows = array_slice( $rows, 0, (int) $lm[1] );
			}

			return $rows;
		}
	}
}

if ( ! class_exists( 'WPTestState' ) ) {
	class WPTestState {
		public static int $current_user_id = 1;
		/** @var array<int, object> */
		public static array $users = array();
		/** @var array<int, array<string, bool>> */
		public static array $user_caps = array();
		/** @var array<int, object> */
		public static array $posts = array();
		/** @var array<int, array<string, mixed>> */
		public static array $post_meta = array();
		/** @var array<string, mixed> */
		public static array $options = array();
		/** @var array<string, mixed> */
		public static array $transients = array();
		/** @var array<int, WPilotTestProduct> */
		public static array $products = array();
		public static bool $fail_next_wp_update_post = false;
		public static bool $fail_next_wp_untrash_post = false;
		/** @var array<string, mixed>|null */
		public static ?array $override_next_wp_update_fields = null;

		public static function reset(): void {
			global $wpdb;
			if ( ! $wpdb instanceof WPTestWpdb ) {
				$wpdb = new WPTestWpdb();
			}
			$wpdb->reset();

			self::$current_user_id                = 1;
			self::$users                          = array();
			self::$user_caps                      = array();
			self::$posts                          = array();
			self::$post_meta                      = array();
			self::$options                        = array();
			self::$transients                     = array();
			self::$products                       = array();
			self::$fail_next_wp_update_post       = false;
			self::$fail_next_wp_untrash_post      = false;
			self::$override_next_wp_update_fields = null;

			self::register_user(
				1,
				array( 'administrator' ),
				array(
					'manage_options'   => true,
					'edit_pages'       => true,
					'publish_pages'    => true,
					'delete_pages'     => true,
					'edit_posts'       => true,
					'edit_post'        => true,
					'delete_post'      => true,
					'upload_files'     => true,
					'activate_plugins' => true,
					'edit_themes'      => true,
					'edit_products'    => true,
					'read'             => true,
				)
			);
			self::register_user(
				2,
				array( 'editor' ),
				array(
					'edit_pages'   => true,
					'edit_posts'   => true,
					'edit_post'    => true,
					'upload_files' => true,
					'read'         => true,
				)
			);
			self::register_user(
				3,
				array( 'subscriber' ),
				array( 'read' => true )
			);
		}

		/**
		 * @param array<int, string>  $roles
		 * @param array<string, bool> $caps
		 */
		public static function register_user( int $id, array $roles, array $caps ): void {
			self::$users[ $id ]     = (object) array(
				'ID'    => $id,
				'roles' => $roles,
			);
			self::$user_caps[ $id ] = $caps;
		}

		/**
		 * @param array<string, mixed> $fields
		 */
		public static function seed_post( int $id, array $fields = array() ): object {
			$post = (object) array_merge(
				array(
					'ID'           => $id,
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_name'    => 'page-' . $id,
					'post_title'   => 'Page ' . $id,
					'post_content' => '<p>Hello world</p>',
					'post_excerpt' => '',
					'post_parent'  => 0,
					'menu_order'   => 0,
				),
				$fields
			);
			self::$posts[ $id ] = $post;
			return $post;
		}
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$GLOBALS['wpdb'] = new WPTestWpdb();
WPTestState::reset();

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return WPTestState::$current_user_id;
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): object {
		return WPTestState::$users[ WPTestState::$current_user_id ] ?? (object) array( 'ID' => 0, 'roles' => array() );
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $user_id ): object|false {
		return WPTestState::$users[ $user_id ] ?? false;
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( mixed $user, string $cap, mixed ...$args ): bool {
		$uid = is_object( $user ) ? (int) ( $user->ID ?? 0 ) : (int) $user;
		return ! empty( WPTestState::$user_caps[ $uid ][ $cap ] );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, mixed ...$args ): bool {
		return user_can( WPTestState::$current_user_id, $cap, ...$args );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		return array_key_exists( $key, WPTestState::$options ) ? WPTestState::$options[ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
		WPTestState::$options[ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $key ): bool {
		unset( WPTestState::$options[ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ): mixed {
		return WPTestState::$transients[ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
		WPTestState::$transients[ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $id ): ?object {
		return WPTestState::$posts[ $id ] ?? null;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	/**
	 * @param array<string, mixed> $postarr
	 */
	function wp_update_post( array $postarr, bool $wp_error = false ): int|WP_Error {
		if ( WPTestState::$fail_next_wp_update_post ) {
			WPTestState::$fail_next_wp_update_post = false;
			return $wp_error ? new WP_Error( 'db_update_error', 'Could not update post.' ) : 0;
		}
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( ! isset( WPTestState::$posts[ $id ] ) ) {
			return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post ID.' ) : 0;
		}
		foreach ( $postarr as $k => $v ) {
			if ( 'ID' !== $k ) {
				WPTestState::$posts[ $id ]->$k = $v;
			}
		}
		if ( is_array( WPTestState::$override_next_wp_update_fields ) ) {
			foreach ( WPTestState::$override_next_wp_update_fields as $ok => $ov ) {
				WPTestState::$posts[ $id ]->$ok = $ov;
			}
			WPTestState::$override_next_wp_update_fields = null;
		}
		return $id;
	}
}
if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( int $post_id ): object|false {
		if ( ! isset( WPTestState::$posts[ $post_id ] ) ) {
			return false;
		}
		WPTestState::$post_meta[ $post_id ]['_wp_trash_meta_status'] = WPTestState::$posts[ $post_id ]->post_status;
		WPTestState::$posts[ $post_id ]->post_status                 = 'trash';
		WPTestState::$posts[ $post_id ]->post_name                   = WPTestState::$posts[ $post_id ]->post_name . '__trashed';
		return WPTestState::$posts[ $post_id ];
	}
}
if ( ! function_exists( 'wp_untrash_post' ) ) {
	function wp_untrash_post( int $post_id ): object|false {
		if ( WPTestState::$fail_next_wp_untrash_post ) {
			WPTestState::$fail_next_wp_untrash_post = false;
			return false;
		}
		if ( ! isset( WPTestState::$posts[ $post_id ] ) ) {
			return false;
		}
		$prev_status                                 = WPTestState::$post_meta[ $post_id ]['_wp_trash_meta_status'] ?? 'draft';
		WPTestState::$posts[ $post_id ]->post_status = (string) $prev_status;
		WPTestState::$posts[ $post_id ]->post_name   = str_replace( '__trashed', '', (string) WPTestState::$posts[ $post_id ]->post_name );
		unset( WPTestState::$post_meta[ $post_id ]['_wp_trash_meta_status'] );
		return WPTestState::$posts[ $post_id ];
	}
}
if ( ! function_exists( 'metadata_exists' ) ) {
	function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool {
		return isset( WPTestState::$post_meta[ $object_id ] )
			&& array_key_exists( $meta_key, WPTestState::$post_meta[ $object_id ] );
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		if ( '' === $key ) {
			return WPTestState::$post_meta[ $post_id ] ?? array();
		}
		if ( ! isset( WPTestState::$post_meta[ $post_id ] ) || ! array_key_exists( $key, WPTestState::$post_meta[ $post_id ] ) ) {
			return $single ? '' : array();
		}
		return WPTestState::$post_meta[ $post_id ][ $key ];
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value ): int|bool {
		WPTestState::$post_meta[ $post_id ][ $key ] = is_string( $value ) ? wp_unslash( $value ) : $value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key ): bool {
		unset( WPTestState::$post_meta[ $post_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data ): string|false {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		return trim( (string) preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( $title ) ), '-' );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $data ): string {
		return (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $data );
	}
}
if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( string $value ): string {
		return addslashes( $value );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( string $value ): string {
		return stripslashes( $value );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( $text );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles(): object {
		return (object) array(
			'roles' => array(
				'administrator' => array( 'name' => 'Administrator' ),
				'editor'        => array( 'name' => 'Editor' ),
				'author'        => array( 'name' => 'Author' ),
				'contributor'   => array( 'name' => 'Contributor' ),
				'subscriber'    => array( 'name' => 'Subscriber' ),
				'shop_manager'  => array( 'name' => 'Shop manager' ),
			),
		);
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'test-deterministic-auth-salt-' . $scheme;
	}
}
if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( int $product_id ): ?WPilotTestProduct {
		return WPTestState::$products[ $product_id ] ?? null;
	}
}
if ( ! function_exists( 'wc_get_products' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return array<int, WPilotTestProduct>
	 */
	function wc_get_products( array $args = array() ): array {
		$limit = (int) ( $args['limit'] ?? 20 );
		return array_slice( array_values( WPTestState::$products ), 0, $limit );
	}
}
