<?php
/**
 * Unit tests covering WP_REST_Global_Styles_Controller functionality.
 *
 * @package WordPress
 * @subpackage REST API
 */

/**
 * @covers WP_REST_Global_Styles_Controller
 * @group restapi-global-styles
 * @group restapi
 */
class WP_REST_Global_Styles_Controller_Test extends WP_Test_REST_Controller_Testcase {
	/**
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * @var int
	 */
	protected static $global_styles_id;

	/**
	 * @var int
	 */
	protected static $post_id;

	private function find_and_normalize_global_styles_by_id( $global_styles, $id ) {
		foreach ( $global_styles as $style ) {
			if ( $style['id'] === $id ) {
				unset( $style['_links'] );
				return $style;
			}
		}

		return null;
	}

	public function set_up() {
		parent::set_up();
		switch_theme( 'tt1-blocks' );
	}

	/**
	 * Create fake data before our tests run.
	 *
	 * @param WP_UnitTest_Factory $factory Helper that lets us create fake data.
	 */
	public static function wpSetupBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		self::$subscriber_id = $factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		// This creates the global styles for the current theme.
		self::$global_styles_id = $factory->post->create(
			array(
				'post_content' => '{"version": ' . WP_Theme_JSON::LATEST_SCHEMA . ', "isGlobalStylesUserThemeJSON": true }',
				'post_status'  => 'publish',
				'post_title'   => 'Custom Styles',
				'post_type'    => 'wp_global_styles',
				'post_name'    => 'wp-global-styles-tt1-blocks',
				'tax_input'    => array(
					'wp_theme' => 'tt1-blocks',
				),
			)
		);

		self::$post_id = $factory->post->create();
	}

	/**
	 *
	 */
	public static function wpTearDownAfterClass() {
		self::delete_user( self::$admin_id );
		self::delete_user( self::$subscriber_id );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::register_routes
	 * @ticket 54596
	 */
	public function test_register_routes() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey(
			'/wp/v2/global-styles/(?P<id>[\/\w-]+)',
			$routes,
			'Single global style based on the given ID route does not exist'
		);
		$this->assertCount(
			2,
			$routes['/wp/v2/global-styles/(?P<id>[\/\w-]+)'],
			'Single global style based on the given ID route does not have exactly two elements'
		);
		$this->assertArrayHasKey(
			'/wp/v2/global-styles/themes/(?P<stylesheet>[^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)',
			$routes,
			'Theme global styles route does not exist'
		);
		$this->assertCount(
			1,
			$routes['/wp/v2/global-styles/themes/(?P<stylesheet>[^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)'],
			'Theme global styles route does not have exactly one element'
		);
		$this->assertArrayHasKey(
			'/wp/v2/global-styles/themes/(?P<stylesheet>[\/\s%\w\.\(\)\[\]\@_\-]+)/variations',
			$routes,
			'Theme global styles variations route does not exist'
		);
	}

	/**
	 * @doesNotPerformAssertions
	 */
	public function test_context_param() {
		// Controller does not use get_context_param().
	}

	/**
	 * @doesNotPerformAssertions
	 */
	public function test_get_items() {
		// Controller does not implement get_items().
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::get_theme_item
	 * @ticket 54516
	 */
	public function test_get_theme_item_no_user() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/tt1-blocks' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_cannot_manage_global_styles', $response, 401 );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::get_theme_item
	 * @ticket 54516
	 */
	public function test_get_theme_item_permission_check() {
		wp_set_current_user( self::$subscriber_id );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/tt1-blocks' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_cannot_manage_global_styles', $response, 403 );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::get_theme_item
	 * @ticket 54516
	 */
	public function test_get_theme_item_invalid() {
		wp_set_current_user( self::$admin_id );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/invalid' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_theme_not_found', $response, 404 );
	}

	/**
	 * @dataProvider data_get_theme_item_invalid_theme_dirname
	 * @covers WP_REST_Global_Styles_Controller::get_theme_item
	 * @ticket 54596
	 *
	 * @param string $theme_dirname Theme directory to test.
	 * @param string $expected      Expected error code.
	 */
	public function test_get_theme_item_invalid_theme_dirname( $theme_dirname, $expected ) {
		wp_set_current_user( self::$admin_id );
		switch_theme( $theme_dirname );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/' . $theme_dirname );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( $expected, $response, 404 );
	}

	/**
	 * Data provider.
	 *
	 * @return array
	 */
	public function data_get_theme_item_invalid_theme_dirname() {
		return array(
			'+'                      => array(
				'theme_dirname' => 'my+theme+',
				'expected'      => 'rest_theme_not_found',
			),
			':'                      => array(
				'theme_dirname' => 'my:theme:',
				'expected'      => 'rest_no_route',
			),
			'<>'                     => array(
				'theme_dirname' => 'my<theme>',
				'expected'      => 'rest_no_route',
			),
			'*'                      => array(
				'theme_dirname' => 'my*theme*',
				'expected'      => 'rest_no_route',
			),
			'?'                      => array(
				'theme_dirname' => 'my?theme?',
				'expected'      => 'rest_no_route',
			),
			'"'                      => array(
				'theme_dirname' => 'my"theme?"',
				'expected'      => 'rest_no_route',
			),
			'| (invalid on Windows)' => array(
				'theme_dirname' => 'my|theme|',
				'expected'      => 'rest_no_route',
			),
			// Themes deep in subdirectories.
			'2 subdirectories deep'  => array(
				'theme_dirname' => 'subdir/subsubdir/mytheme',
				'expected'      => 'rest_global_styles_not_found',
			),
		);
	}

	/**
	 * @dataProvider data_get_theme_item
	 * @covers WP_REST_Global_Styles_Controller::get_theme_item
	 * @ticket 54596
	 *
	 * @param string $theme Theme directory to test.
	 */
	public function test_get_theme_item( $theme ) {
		wp_set_current_user( self::$admin_id );
		switch_theme( $theme );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/' . $theme );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$links    = $response->get_links();
		$this->assertArrayHasKey( 'settings', $data, 'Data does not have "settings" key' );
		$this->assertArrayHasKey( 'styles', $data, 'Data does not have "styles" key' );
		$this->assertArrayHasKey( 'self', $links, 'Links do not have a "self" key' );
		$this->assertStringContainsString( '/wp/v2/global-styles/themes/' . $theme, $links['self'][0]['href'] );
	}

	/**
	 * Data provider.
	 *
	 * @return array
	 */
	public function data_get_theme_item() {
		return array(
			'alphabetic'                     => array( 'mytheme' ),
			'alphanumeric'                   => array( 'mythemev1' ),
			'àáâãäåæç'                       => array( 'àáâãäåæç' ),
			'space'                          => array( 'my theme' ),
			'-_.'                            => array( 'my_theme-0.1' ),
			'[]'                             => array( 'my[theme]' ),
			'()'                             => array( 'my(theme)' ),
			'{}'                             => array( 'my{theme}' ),
			'&=#@!$,^~%'                     => array( 'theme &=#@!$,^~%' ),
			'all combined'                   => array( 'thémé {}&=@!$,^~%[0.1](-_-)' ),

			// Themes in a subdirectory.
			'subdir: alphabetic'             => array( 'subdir/mytheme' ),
			'subdir: alphanumeric in theme'  => array( 'subdir/mythemev1' ),
			'subdir: alphanumeric in subdir' => array( 'subdirv1/mytheme' ),
			'subdir: alphanumeric in both'   => array( 'subdirv1/mythemev1' ),
			'subdir: àáâãäåæç in theme'      => array( 'subdir/àáâãäåæç' ),
			'subdir: àáâãäåæç in subdir'     => array( 'àáâãäåæç/mythemev1' ),
			'subdir: àáâãäåæç in both'       => array( 'àáâãäåæç/àáâãäåæç' ),
			'subdir: space in theme'         => array( 'subdir/my theme' ),
			'subdir: space in subdir'        => array( 'sub dir/mytheme' ),
			'subdir: space in both'          => array( 'sub dir/my theme' ),
			'subdir: -_. in theme'           => array( 'subdir/my_theme-0.1' ),
			'subdir: -_. in subdir'          => array( 'sub_dir-0.1/mytheme' ),
			'subdir: -_. in both'            => array( 'sub_dir-0.1/my_theme-0.1' ),
			'subdir: all combined in theme'  => array( 'subdir/thémé {}&=@!$,^~%[0.1](-_-)' ),
			'subdir: all combined in subdir' => array( 'sűbdīr {}&=@!$,^~%[0.1](-_-)/mytheme' ),
			'subdir: all combined in both'   => array( 'sűbdīr {}&=@!$,^~%[0.1](-_-)/thémé {}&=@!$,^~%[0.1](-_-)' ),
		);
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::get_theme_item
	 * @ticket 54595
	 */
	public function test_get_theme_item_fields() {
		wp_set_current_user( self::$admin_id );
		$request = new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/tt1-blocks' );
		$request->set_param( '_fields', 'settings' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$this->assertArrayHasKey( 'settings', $data );
		$this->assertArrayNotHasKey( 'styles', $data );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::get_item
	 * @ticket 54516
	 */
	public function test_get_item_no_user() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/global-styles/' . self::$global_styles_id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_cannot_view', $response, 401 );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::get_item
	 * @ticket 54516
	 */
	public function test_get_item_invalid_post() {
		wp_set_current_user( self::$admin_id );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/global-styles/' . self::$post_id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_global_styles_not_found', $response, 404 );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::get_item
	 * @ticket 54516
	 */
	public function test_get_item_permission_check() {
		wp_set_current_user( self::$subscriber_id );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/global-styles/' . self::$global_styles_id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_cannot_view', $response, 403 );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::get_item
	 * @ticket 54516
	 */
	public function test_get_item_no_user_edit() {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', '/wp/v2/global-styles/' . self::$global_styles_id );
		$request->set_param( 'context', 'edit' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_forbidden_context', $response, 401 );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::get_item
	 * @ticket 54516
	 */
	public function test_get_item_permission_check_edit() {
		wp_set_current_user( self::$subscriber_id );
		$request = new WP_REST_Request( 'GET', '/wp/v2/global-styles/' . self::$global_styles_id );
		$request->set_param( 'context', 'edit' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_forbidden_context', $response, 403 );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::get_item
	 */
	public function test_get_item() {
		wp_set_current_user( self::$admin_id );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/global-styles/' . self::$global_styles_id );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$links    = $response->get_links();

		$this->assertEqualSets(
			array(
				'id'       => self::$global_styles_id,
				'title'    => array(
					'raw'      => 'Custom Styles',
					'rendered' => 'Custom Styles',
				),
				'settings' => new stdClass(),
				'styles'   => new stdClass(),
			),
			$data
		);

		$this->assertArrayHasKey( 'self', $links );
		$this->assertStringContainsString( '/wp/v2/global-styles/' . self::$global_styles_id, $links['self'][0]['href'] );
	}

	/**
	 * @doesNotPerformAssertions
	 */
	public function test_create_item() {
		// Controller does not implement create_item().
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::update_item
	 * @ticket 54516
	 */
	public function test_update_item() {
		wp_set_current_user( self::$admin_id );
		$request = new WP_REST_Request( 'PUT', '/wp/v2/global-styles/' . self::$global_styles_id );
		$request->set_body_params(
			array(
				'title' => 'My new global styles title',
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$this->assertSame( 'My new global styles title', $data['title']['raw'] );
	}


	/**
	 * @covers WP_REST_Global_Styles_Controller::update_item
	 * @ticket 54516
	 */
	public function test_update_item_no_user() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'PUT', '/wp/v2/global-styles/' . self::$global_styles_id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_cannot_edit', $response, 401 );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::update_item
	 * @ticket 54516
	 */
	public function test_update_item_invalid_post() {
		wp_set_current_user( self::$admin_id );
		$request  = new WP_REST_Request( 'PUT', '/wp/v2/global-styles/' . self::$post_id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_global_styles_not_found', $response, 404 );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::update_item
	 * @ticket 54516
	 */
	public function test_update_item_permission_check() {
		wp_set_current_user( self::$subscriber_id );
		$request  = new WP_REST_Request( 'PUT', '/wp/v2/global-styles/' . self::$global_styles_id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_cannot_edit', $response, 403 );
	}

	/**
	 * @doesNotPerformAssertions
	 */
	public function test_delete_item() {
		// Controller does not implement delete_item().
	}

	/**
	 * @doesNotPerformAssertions
	 */
	public function test_prepare_item() {
		// Controller does not implement prepare_item().
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::get_item_schema
	 * @ticket 54516
	 */
	public function test_get_item_schema() {
		$request    = new WP_REST_Request( 'OPTIONS', '/wp/v2/global-styles/' . self::$global_styles_id );
		$response   = rest_get_server()->dispatch( $request );
		$data       = $response->get_data();
		$properties = $data['schema']['properties'];
		$this->assertCount( 4, $properties, 'Schema properties array does not have exactly 4 elements' );
		$this->assertArrayHasKey( 'id', $properties, 'Schema properties array does not have "id" key' );
		$this->assertArrayHasKey( 'styles', $properties, 'Schema properties array does not have "styles" key' );
		$this->assertArrayHasKey( 'settings', $properties, 'Schema properties array does not have "settings" key' );
		$this->assertArrayHasKey( 'title', $properties, 'Schema properties array does not have "title" key' );
	}


	public function test_get_theme_items() {
		wp_set_current_user( self::$admin_id );
		switch_theme( 'block-theme' );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/block-theme/variations' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$expected = array(
			array(
				'version'  => 2,
				'title'    => 'Block theme variation',
				'settings' => array(
					'color' => array(
						'palette' => array(
							'theme' => array(
								array(
									'slug'  => 'foreground',
									'color' => '#3F67C6',
									'name'  => 'Foreground',
								),
							),
						),
					),
				),
				'styles'   => array(
					'blocks' => array(
						'core/post-title' => array(
							'typography' => array(
								'fontWeight' => '700',
							),
						),
					),
				),
			),
		);
		$this->assertSameSetsWithIndex( $data, $expected );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::get_available_actions
	 */
	public function test_assign_edit_css_action_admin() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/global-styles/' . self::$global_styles_id );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );
		$links    = $response->get_links();

		// Admins can only edit css on single site.
		if ( is_multisite() ) {
			$this->assertArrayNotHasKey( 'https://api.w.org/action-edit-css', $links );
		} else {
			$this->assertArrayHasKey( 'https://api.w.org/action-edit-css', $links );
		}
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::update_item
	 * @ticket 57536
	 */
	public function test_update_item_valid_styles_css() {
		wp_set_current_user( self::$admin_id );
		if ( is_multisite() ) {
			grant_super_admin( self::$admin_id );
		}
		$request = new WP_REST_Request( 'PUT', '/wp/v2/global-styles/' . self::$global_styles_id );
		$request->set_body_params(
			array(
				'styles' => array( 'css' => 'body { color: red; }' ),
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$this->assertSame( 'body { color: red; }', $data['styles']['css'] );
	}

	/**
	 * @covers WP_REST_Global_Styles_Controller::update_item
	 * @ticket 57536
	 */
	public function test_update_item_invalid_styles_css() {
		wp_set_current_user( self::$admin_id );
		if ( is_multisite() ) {
			grant_super_admin( self::$admin_id );
		}
		$request = new WP_REST_Request( 'PUT', '/wp/v2/global-styles/' . self::$global_styles_id );
		$request->set_body_params(
			array(
				'styles' => array( 'css' => '<p>test</p> body { color: red; }' ),
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_custom_css_illegal_markup', $response, 400 );
	}
}
