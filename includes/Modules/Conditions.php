<?php
/**
 * Module «شرط نمایش»: when card to card is offered (product categories, shipping methods, customer
 * roles) and which card goes to whom (per card: roles and an amount range — e.g. a separate card
 * for wholesale customers or for large orders).
 *
 * @package CardToCard
 */

namespace CardToCard\Modules;

use CardToCard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Display rules.
 */
final class Conditions extends Module {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'rules';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'شرط نمایش درگاه و کارت', 'card-to-card' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'کارت به کارت فقط برای دسته‌ها، روش‌های ارسال یا نقش‌های کاربری خاص؛ و برای هر کارت، نقش و بازه مبلغ (مثلاً کارت جدا برای عمده‌فروش‌ها).', 'card-to-card' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon(): string {
		return 'filter';
	}

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'cats_mode' => 'off', // off | only (every item in these) | except (no item in these).
			'cats'      => array(),
			'shipping'  => array(), // Allowed methods (empty = all): «flat_rate» or «flat_rate:3».
			'roles'     => array(), // Allowed roles (empty = all), «guest» for not logged in.
			'cards'     => array(), // card id => { roles, min, max }.
		);
	}

	/**
	 * Choices.
	 *
	 * @return array<string, string[]>
	 */
	protected function choices(): array {
		return array( 'cats_mode' => array( 'off', 'only', 'except' ) );
	}

	/**
	 * Sanitise lists and card rules.
	 *
	 * @param array<string, mixed> $input Raw.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ): array {
		$out             = parent::sanitize( $input );
		$out['cats']     = array_values( array_unique( array_filter( array_map( 'absint', (array) $out['cats'] ) ) ) );
		$out['shipping'] = self::keys( (array) $out['shipping'], '/[^a-z0-9_:\-]/' );
		$out['roles']    = self::keys( (array) $out['roles'], '/[^a-z0-9_\-]/' );
		$cards           = array();
		foreach ( (array) $out['cards'] as $card => $rule ) {
			$card = sanitize_key( (string) $card );
			if ( '' === $card || ! is_array( $rule ) ) {
				continue;
			}
			$clean = array(
				'roles' => self::keys( (array) ( $rule['roles'] ?? array() ), '/[^a-z0-9_\-]/' ),
				'min'   => max( 0, (int) \CardToCard\Support\Persian::only_digits( (string) ( $rule['min'] ?? 0 ) ) ),
				'max'   => max( 0, (int) \CardToCard\Support\Persian::only_digits( (string) ( $rule['max'] ?? 0 ) ) ),
			);
			if ( $clean['roles'] || $clean['min'] || $clean['max'] ) {
				$cards[ $card ] = $clean;
			}
		}
		$out['cards'] = $cards;
		return $out;
	}

	/**
	 * Clean a list of keys.
	 *
	 * @param array<int, mixed> $values  Values.
	 * @param string            $pattern Characters to strip.
	 * @return string[]
	 */
	private static function keys( array $values, string $pattern ): array {
		$out = array();
		foreach ( $values as $value ) {
			$value = (string) preg_replace( $pattern, '', strtolower( (string) $value ) );
			if ( '' !== $value ) {
				$out[] = substr( $value, 0, 60 );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Hooks.
	 */
	public function boot(): void {
		add_filter( 'ctc_is_available', array( $this, 'available' ) );
		add_filter( 'ctc_eligible_cards', array( $this, 'cards' ), 10, 3 );
	}

	/**
	 * Whether the gateway rules let a checkout through.
	 *
	 * @param Context $ctx Facts.
	 * @return bool
	 */
	public function allows( Context $ctx ): bool {
		$cats = (array) $this->conf( 'cats', array() );
		$mode = (string) $this->conf( 'cats_mode', 'off' );
		if ( 'off' !== $mode && $cats && $ctx->known ) {
			$hit = (bool) array_intersect( $cats, $ctx->categories );
			if ( 'except' === $mode && $hit ) {
				return false;
			}
			if ( 'only' === $mode && ! $this->all_items_in( $ctx, $cats ) ) {
				return false;
			}
		}
		$ship = (array) $this->conf( 'shipping', array() );
		if ( $ship && $ctx->shipping && ! array_intersect( $ship, $ctx->shipping ) ) {
			return false;
		}
		$roles = (array) $this->conf( 'roles', array() );
		if ( $roles && ! array_intersect( $roles, $ctx->roles ) ) {
			return false;
		}
		return true;
	}

	/**
	 * «Only these categories»: every product is in one of them (or a child).
	 *
	 * @param Context $ctx  Facts.
	 * @param int[]   $cats Category ids.
	 * @return bool
	 */
	private function all_items_in( Context $ctx, array $cats ): bool {
		foreach ( $ctx->items as $item ) {
			if ( ! array_intersect( $cats, $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Gateway availability (cart or pay-for-order page).
	 *
	 * @param bool $available Available.
	 * @return bool
	 */
	public function available( $available ): bool {
		if ( ! $available || ! $this->enabled() ) {
			return (bool) $available;
		}
		return $this->allows( Context::current() );
	}

	/**
	 * Whether a card may go to a customer with these roles and this amount.
	 *
	 * @param string   $card   Card id.
	 * @param string[] $roles  Customer roles.
	 * @param float    $amount Amount.
	 * @return bool
	 */
	public function card_allows( string $card, array $roles, float $amount ): bool {
		$all  = (array) $this->conf( 'cards', array() );
		$rule = isset( $all[ $card ] ) && is_array( $all[ $card ] ) ? $all[ $card ] : array();
		if ( ! empty( $rule['roles'] ) && ! array_intersect( (array) $rule['roles'], $roles ) ) {
			return false;
		}
		if ( ! empty( $rule['min'] ) && $amount > 0 && $amount < (float) $rule['min'] ) {
			return false;
		}
		if ( ! empty( $rule['max'] ) && $amount > (float) $rule['max'] ) {
			return false;
		}
		return true;
	}

	/**
	 * Per-card rules.
	 *
	 * @param array<int, array<string, mixed>> $cards  Cards.
	 * @param \WC_Order|null                   $order  Order (null = cart / current user).
	 * @param float                            $amount Amount.
	 * @return array<int, array<string, mixed>>
	 */
	public function cards( $cards, $order = null, $amount = 0.0 ): array {
		if ( ! $this->enabled() || ! $this->conf( 'cards' ) ) {
			return (array) $cards;
		}
		$roles = $order instanceof \WC_Order ? Context::from_order( $order )->roles : Context::from_cart()->roles;
		$out   = array();
		foreach ( (array) $cards as $card ) {
			if ( $this->card_allows( (string) ( $card['id'] ?? '' ), $roles, (float) $amount ) ) {
				$out[] = $card;
			}
		}
		return $out;
	}

	/**
	 * Panel: categories, shipping methods (with zone instances) and roles to pick from.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$cats  = array();
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 300,
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					$cats[] = array(
						'value' => (string) $term->term_id,
						'label' => $term->name,
					);
				}
			}
		}
		$ship = array();
		if ( function_exists( 'WC' ) && WC()->shipping() ) {
			foreach ( WC()->shipping()->get_shipping_methods() as $id => $method ) {
				$ship[] = array(
					'value' => (string) $id,
					/* translators: %s: shipping method */
					'label' => sprintf( __( '%s (همه مناطق)', 'card-to-card' ), wp_strip_all_tags( (string) $method->get_method_title() ) ),
				);
			}
			if ( class_exists( '\WC_Shipping_Zones' ) ) {
				$zones   = \WC_Shipping_Zones::get_zones();
				$zones[] = array(
					'zone_name'        => __( 'سایر مناطق', 'card-to-card' ),
					'shipping_methods' => ( new \WC_Shipping_Zone( 0 ) )->get_shipping_methods(),
				);
				foreach ( $zones as $zone ) {
					foreach ( (array) ( $zone['shipping_methods'] ?? array() ) as $method ) {
						if ( $method instanceof \WC_Shipping_Method ) {
							$ship[] = array(
								'value' => $method->id . ':' . $method->get_instance_id(),
								'label' => (string) $zone['zone_name'] . ' — ' . wp_strip_all_tags( (string) $method->get_title() ),
							);
						}
					}
				}
			}
		}
		$roles = array(
			array(
				'value' => 'guest',
				'label' => __( 'مهمان (بدون ورود)', 'card-to-card' ),
			),
		);
		foreach ( wp_roles()->get_names() as $role => $name ) {
			$roles[] = array(
				'value' => (string) $role,
				'label' => translate_user_role( (string) $name ),
			);
		}
		return array(
			'cats'     => $cats,
			'shipping' => $ship,
			'roles'    => $roles,
			'cards'    => count( Settings::cards() ),
		);
	}
}
