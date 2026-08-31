<?php
/** Minimal standalone block type registry for materializer admission tests. */

if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	final class WP_Block_Type_Registry {
		private static ?self $instance = null;
		private array $registered = array();

		public static function get_instance(): self {
			self::$instance ??= new self();
			return self::$instance;
		}

		public function register( string $name, array $args = array() ): object {
			$block = (object) array(
				'name'           => $name,
				'parent'         => isset( $args['parent'] ) && is_array( $args['parent'] ) ? $args['parent'] : null,
				'allowed_blocks' => isset( $args['allowed_blocks'] ) && is_array( $args['allowed_blocks'] ) ? $args['allowed_blocks'] : null,
			);
			$this->registered[ $name ] = $block;
			return $block;
		}

		public function is_registered( string $name ): bool {
			return isset( $this->registered[ $name ] );
		}

		public function get_registered( string $name ): ?object {
			return $this->registered[ $name ] ?? null;
		}
	}
}
