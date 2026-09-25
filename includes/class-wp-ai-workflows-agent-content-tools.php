<?php
/**
 * Agent Content Tools - read-only "concierge" search tools (search_products,
 * search_content) that turn WordPress/WooCommerce content into rich-message
 * cards for the chat widget. Opt-in per tool (Actions → Capabilities, default
 * OFF); search_products is hidden when WooCommerce is inactive.
 *
 * Each executor returns array( '_ui_blocks' => Block[], 'summary' => string ):
 * the orchestrator shows `_ui_blocks` to the widget and feeds only the compact
 * `summary` back to the model. All card fields are sanitised at the source and
 * only published, non-private content is ever returned.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Agent_Content_Tools {

	/** @var int Hard ceiling on cards returned by any single search. */
	const MAX_CARDS = 6;

	/** @var int Default number of cards when the model does not specify a limit. */
	const DEFAULT_LIMIT = 4;

	/**
	 * Tool names owned by this class (used by the chat handler to decide whether
	 * the agentic loop must run and by the frontend capabilities list).
	 *
	 * @return string[]
	 */
	public static function tool_names() {
		return array( 'search_products', 'search_content' );
	}

	/**
	 * Whether WooCommerce is active (gates the product tool everywhere).
	 *
	 * @return bool
	 */
	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' );
	}

	/**
	 * Register the opt-in content tools onto a registry.
	 *
	 * Both tools default to DISABLED so they never change a simple chatbot's
	 * behaviour until the operator turns them on. The product tool is only
	 * registered when WooCommerce is active.
	 *
	 * @param WP_AI_Workflows_Agent_Tool_Registry $registry  Target registry.
	 * @param array                                $overrides Per-tool guardrail overrides (data.agent.tools).
	 * @return void
	 */
	public static function register( $registry, $overrides ) {
		self::register_content_tool( $registry, $overrides );
		if ( self::woocommerce_active() ) {
			self::register_product_tool( $registry, $overrides );
		}
	}

	/**
	 * Resolve per-tool guardrails with a DISABLED-by-default posture (opt-in).
	 *
	 * @param array  $overrides All overrides.
	 * @param string $name      Tool name.
	 * @return array{enabled:bool,requiresConfirmation:bool,rateLimit:int}
	 */
	private static function guardrails_for( $overrides, $name ) {
		$o = isset( $overrides[ $name ] ) && is_array( $overrides[ $name ] ) ? $overrides[ $name ] : array();
		return array(
			// Opt-in: enabled only when explicitly set true.
			'enabled'              => isset( $o['enabled'] ) ? (bool) $o['enabled'] : false,
			'requiresConfirmation' => ! empty( $o['requiresConfirmation'] ),
			'rateLimit'            => isset( $o['rateLimit'] ) ? (int) $o['rateLimit'] : 0,
		);
	}

	private static function register_content_tool( $registry, $overrides ) {
		$registry->register(
			new WP_AI_Workflows_Agent_Tool(
				'search_content',
				'Search this site\'s published posts and pages and show the visitor rich content cards (title, image, excerpt and a Read link). Use when the visitor is looking for articles, guides, documentation or pages on the site.',
				array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Keywords to search the site content for.',
						),
						'type'  => array(
							'type'        => 'string',
							'enum'        => array( 'any', 'post', 'page' ),
							'description' => 'Restrict to posts, pages, or any (default any).',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Maximum number of results to show (1-6, default 4).',
						),
					),
					'required'   => array( 'query' ),
				),
				WP_AI_Workflows_Agent_Tool::KIND_BUILTIN,
				self::guardrails_for( $overrides, 'search_content' ),
				array( __CLASS__, 'exec_search_content' )
			)
		);
	}

	/**
	 * Execute the content search. Published-only; returns cards + a compact summary.
	 *
	 * @param array $args {query, type?, limit?}
	 * @param array $ctx  Execution context (unused).
	 * @return array{_ui_blocks:array,summary:string}
	 */
	public static function exec_search_content( array $args, array $ctx ) {
		$query = isset( $args['query'] ) ? trim( wp_strip_all_tags( (string) $args['query'] ) ) : '';
		if ( '' === $query ) {
			return array(
				'_ui_blocks' => array(),
				'summary'    => 'No search query was provided.',
			);
		}

		$limit = self::clamp_limit( isset( $args['limit'] ) ? $args['limit'] : self::DEFAULT_LIMIT );
		$type  = isset( $args['type'] ) ? (string) $args['type'] : 'any';
		$ptype = ( 'post' === $type || 'page' === $type ) ? $type : array( 'post', 'page' );

		$wp_query = new WP_Query(
			array(
				'post_type'           => $ptype,
				'post_status'         => 'publish',
				's'                   => $query,
				'posts_per_page'      => $limit,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'has_password'        => false,
			)
		);

		$cards   = array();
		$titles  = array();
		if ( $wp_query->have_posts() ) {
			foreach ( $wp_query->posts as $post ) {
				$card = self::build_content_card( $post );
				if ( $card ) {
					$cards[]  = $card;
					$titles[] = $card['title'];
				}
			}
		}
		wp_reset_postdata();

		if ( empty( $cards ) ) {
			return array(
				'_ui_blocks' => array(),
				'summary'    => 'No published content matched "' . $query . '".',
			);
		}

		$summary = sprintf(
			/* translators: 1: result count, 2: search query, 3: titles list. */
			'Found %1$d result(s) for "%2$s": %3$s. Rich cards were shown to the visitor.',
			count( $cards ),
			$query,
			implode( '; ', $titles )
		);

		return array(
			'_ui_blocks' => array( self::cards_block( $cards ) ),
			'summary'    => $summary,
		);
	}

	/**
	 * Build one content card from a post object.
	 *
	 * @param WP_Post $post Post/page.
	 * @return array|null
	 */
	private static function build_content_card( $post ) {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return null;
		}
		$id    = (int) $post->ID;
		$title = self::clean_text( get_the_title( $id ) );
		$url   = get_permalink( $id );

		$excerpt = has_excerpt( $id ) ? get_the_excerpt( $post ) : (string) $post->post_content;
		$excerpt = self::clean_text( $excerpt );
		$excerpt = wp_trim_words( $excerpt, 22, '…' );

		$image = get_the_post_thumbnail_url( $id, 'medium' );

		return array(
			'image'    => $image ? esc_url_raw( $image ) : '',
			'title'    => $title,
			'subtitle' => $excerpt,
			'price'    => '',
			'url'      => $url ? esc_url_raw( $url ) : '',
			'buttons'  => array(
				array(
					'label'  => __( 'Read', 'ai-workflow-automation-lite' ),
					'action' => 'link',
					'value'  => $url ? esc_url_raw( $url ) : '',
				),
			),
		);
	}

	private static function register_product_tool( $registry, $overrides ) {
		$registry->register(
			new WP_AI_Workflows_Agent_Tool(
				'search_products',
				'Search this store\'s WooCommerce products and show the visitor interactive product cards (image, name, price, Add to cart and View). Use when the visitor is shopping, asking about products, prices, or what the store sells.',
				array(
					'type'       => 'object',
					'properties' => array(
						'query'    => array(
							'type'        => 'string',
							'description' => 'Keywords to match product names/descriptions. Omit to browse.',
						),
						'category' => array(
							'type'        => 'string',
							'description' => 'Optional product category slug to filter by.',
						),
						'on_sale'  => array(
							'type'        => 'boolean',
							'description' => 'Only include products currently on sale.',
						),
						'limit'    => array(
							'type'        => 'integer',
							'description' => 'Maximum number of products to show (1-6, default 4).',
						),
					),
				),
				WP_AI_Workflows_Agent_Tool::KIND_BUILTIN,
				self::guardrails_for( $overrides, 'search_products' ),
				array( __CLASS__, 'exec_search_products' )
			)
		);
	}

	/**
	 * Execute the product search. Published + visible + purchasable-aware; returns
	 * product cards (carousel) + a compact summary. Never mutates the cart.
	 *
	 * @param array $args {query?, category?, on_sale?, limit?}
	 * @param array $ctx  Execution context (unused).
	 * @return array{_ui_blocks:array,summary:string}
	 */
	public static function exec_search_products( array $args, array $ctx ) {
		if ( ! self::woocommerce_active() ) {
			return array(
				'_ui_blocks' => array(),
				'summary'    => 'The store is not available.',
			);
		}

		$query    = isset( $args['query'] ) ? trim( wp_strip_all_tags( (string) $args['query'] ) ) : '';
		$category = isset( $args['category'] ) ? sanitize_title( (string) $args['category'] ) : '';
		$on_sale  = ! empty( $args['on_sale'] );
		$limit    = self::clamp_limit( isset( $args['limit'] ) ? $args['limit'] : self::DEFAULT_LIMIT );

		$products = self::query_products( $query, $category, $on_sale, $limit );

		$cards  = array();
		$labels = array();
		foreach ( $products as $product ) {
			$card = self::build_product_card( $product );
			if ( $card ) {
				$cards[]  = $card;
				$labels[] = $card['title'] . ( '' !== $card['price'] ? ' (' . $card['price'] . ')' : '' );
			}
		}

		if ( empty( $cards ) ) {
			$scope = '' !== $query ? '"' . $query . '"' : 'your request';
			return array(
				'_ui_blocks' => array(),
				'summary'    => 'No matching products were found for ' . $scope . '.',
			);
		}

		$summary = sprintf(
			/* translators: 1: product count, 2: product list. */
			'Found %1$d product(s): %2$s. Interactive product cards were shown to the visitor.',
			count( $cards ),
			implode( '; ', $labels )
		);

		return array(
			'_ui_blocks' => array( self::carousel_block( $cards ) ),
			'summary'    => $summary,
		);
	}

	/**
	 * Query published, catalog-visible products. Uses wc_get_products; falls back
	 * to a keyword ('s') WP_Query for text search (which wc_get_products does not
	 * cover), then loads the WC_Product objects.
	 *
	 * @param string $query    Keyword ('' = browse).
	 * @param string $category Category slug ('' = any).
	 * @param bool   $on_sale  On-sale only.
	 * @param int    $limit    Max products.
	 * @return WC_Product[]
	 */
	private static function query_products( $query, $category, $on_sale, $limit ) {
		$ids = array();

		if ( '' !== $query ) {
			// Keyword search via WP_Query (published + not password protected).
			$args = array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				's'                   => $query,
				'posts_per_page'      => $limit,
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'has_password'        => false,
				'tax_query'           => array(               // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
						'operator' => 'NOT IN',
					),
				),
			);
			if ( '' !== $category ) {
				$args['tax_query'][] = array(                 // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => $category,
				);
			}
			$q   = new WP_Query( $args );
			$ids = array_map( 'intval', (array) $q->posts );
			wp_reset_postdata();
		} else {
			// Browse via the WooCommerce data store (catalog-visible, published).
			$wc_args = array(
				'status'   => 'publish',
				'limit'    => $limit,
				'orderby'  => 'popularity',
				'order'    => 'DESC',
				'visibility' => 'catalog',
				'return'   => 'ids',
			);
			if ( '' !== $category ) {
				$wc_args['category'] = array( $category );
			}
			$ids = array_map( 'intval', (array) wc_get_products( $wc_args ) );
		}

		$products = array();
		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || 'publish' !== $product->get_status() ) {
				continue;
			}
			if ( $on_sale && ! $product->is_on_sale() ) {
				continue;
			}
			$products[] = $product;
			if ( count( $products ) >= $limit ) {
				break;
			}
		}
		return $products;
	}

	/**
	 * Build one product card from a WC_Product.
	 *
	 * @param WC_Product $product Product.
	 * @return array|null
	 */
	private static function build_product_card( $product ) {
		if ( ! is_object( $product ) ) {
			return null;
		}
		$id    = (int) $product->get_id();
		$title = self::clean_text( $product->get_name() );
		$url   = get_permalink( $id );
		$price = self::clean_text( $product->get_price_html() );

		$subtitle = self::clean_text( $product->get_short_description() );
		$subtitle = wp_trim_words( $subtitle, 16, '…' );

		$image_id  = (int) $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
		if ( ! $image_url ) {
			$image_url = get_the_post_thumbnail_url( $id, 'medium' );
		}
		if ( ! $image_url && function_exists( 'wc_placeholder_img_src' ) ) {
			// WooCommerce's own catalog placeholder so cards stay visually even.
			$image_url = wc_placeholder_img_src( 'woocommerce_thumbnail' );
		}

		$buttons = array();
		// Add-to-cart only for simple, purchasable, in-stock products (variable
		// products need variation selection, which happens on the product page).
		if ( $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() ) {
			$buttons[] = array(
				'label'  => __( 'Add to cart', 'ai-workflow-automation-lite' ),
				'action' => 'add_to_cart',
				'value'  => (string) $id,
			);
		}
		$buttons[] = array(
			'label'  => __( 'View', 'ai-workflow-automation-lite' ),
			'action' => 'link',
			'value'  => $url ? esc_url_raw( $url ) : '',
		);

		return array(
			'image'    => $image_url ? esc_url_raw( $image_url ) : '',
			'title'    => $title,
			'subtitle' => $subtitle,
			'price'    => $price,
			'url'      => $url ? esc_url_raw( $url ) : '',
			'buttons'  => $buttons,
		);
	}

	/**
	 * A vertical cards block (used for content; and a single product).
	 *
	 * @param array $cards Cards.
	 * @return array
	 */
	private static function cards_block( $cards ) {
		return array(
			'type'  => count( $cards ) > 1 ? 'carousel' : 'cards',
			'cards' => array_values( $cards ),
		);
	}

	/**
	 * A horizontally-scrollable carousel block (used for products).
	 *
	 * @param array $cards Cards.
	 * @return array
	 */
	private static function carousel_block( $cards ) {
		return array(
			'type'  => count( $cards ) > 1 ? 'carousel' : 'cards',
			'cards' => array_values( $cards ),
		);
	}

	/**
	 * Normalise a display string for a card: strip tags, then decode HTML
	 * entities (e.g. WooCommerce's `&#36;` currency symbol or a `&amp;` in a
	 * title) so the widget can render it as an inert React text node and still
	 * show real glyphs. The widget re-escapes on render, so this stays injection
	 * safe - it never becomes markup.
	 *
	 * @param string $raw Raw string (may contain tags/entities).
	 * @return string
	 */
	private static function clean_text( $raw ) {
		$text = wp_strip_all_tags( (string) $raw );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return trim( $text );
	}

	/**
	 * Clamp a requested limit into [1, MAX_CARDS].
	 *
	 * @param mixed $raw Requested limit.
	 * @return int
	 */
	private static function clamp_limit( $raw ) {
		$n = (int) $raw;
		if ( $n < 1 ) {
			$n = self::DEFAULT_LIMIT;
		}
		return min( self::MAX_CARDS, max( 1, $n ) );
	}
}
