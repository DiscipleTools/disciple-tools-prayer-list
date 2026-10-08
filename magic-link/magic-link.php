<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.


class DT_Prayer_List_Magic_Link extends DT_Magic_Url_Base
{

    public $page_title = 'Prayer List App';
    public $page_description = 'A micro user app page that can be added to home screen.';
    public $root = "prayer_list_app";
    public $type = 'daily';
    public $type_name = 'Prayer List App';
    public $post_type = 'user';
    private $meta_key = '';

    private static $_instance = null;
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    } // End instance()

    public function __construct() {
        $this->meta_key = $this->root . '_' . $this->type . '_magic_key';
        parent::__construct();

        /**
         * user_app and module section
         */
        add_action( 'rest_api_init', [ $this, 'add_endpoints' ] );
        /**
         * tests if other URL
         */
        $url = dt_get_url_path();
        if ( strpos( $url, $this->root . '/' . $this->type ) === false ) {
            return;
        }

        /**
         * tests magic link parts are registered and have valid elements
         */
        if ( !$this->check_parts_match( false ) ){
            return;
        }

        // load if valid url
        add_action( 'wp_enqueue_scripts', [ $this, '_wp_enqueue_scripts' ], 100 );
        add_action( 'dt_blank_body', [ $this, 'body' ] );
        add_filter( 'dt_magic_url_base_allowed_css', [ $this, 'dt_magic_url_base_allowed_css' ], 10, 1 );
        add_filter( 'dt_magic_url_base_allowed_js', [ $this, 'dt_magic_url_base_allowed_js' ], 10, 1 );
    }

    public function dt_magic_url_base_allowed_js( $allowed_js ) {
        $allowed_js[] = 'jquery-touch-punch';
        $allowed_js[] = 'lodash';
        $allowed_js[] = 'prayer-list-daily-js';
        return $allowed_js;
    }

    public function dt_magic_url_base_allowed_css( $allowed_css ) {
        $allowed_css[] = 'prayer-list-daily-css';
        return $allowed_css;
    }

    public function _wp_enqueue_scripts(){
        wp_register_script( 'jquery-touch-punch', '/wp-includes/js/jquery/jquery.ui.touch-punch.js' ); // @phpcs:ignore
        wp_enqueue_script('prayer-list-daily-js', trailingslashit( plugin_dir_url( __FILE__ ) ) . 'prayer-list-daily.js', [
            'jquery',
        ], filemtime( plugin_dir_path( __FILE__ ) .'prayer-list-daily.js' ), true );

        wp_enqueue_style( 'prayer-list-daily-css', trailingslashit( plugin_dir_url( __FILE__ ) ) . 'prayer-list-daily.css', [ 'site-css' ], filemtime( plugin_dir_path( __FILE__ ) .'prayer-list-daily.css' ) );
    }

    public function header_style(){
        ?>
        <style>
            body {
                background-color: white;
            }
        </style>
        <?php
    }

    /**
     * Writes javascript to the footer
     *
     * @see DT_Magic_Url_Base()->footer_javascript() for default state
     */
    public function footer_javascript(){
        ?>
        <script>
            let jsObject = [<?php echo json_encode([
                'map_key' => DT_Mapbox_API::get_key(),
                'root' => esc_url_raw( rest_url() ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'parts' => $this->parts,
                'translations' => [],
            ]) ?>][0]


        </script>
        <?php
        return true;
    }

    public function body(){
        include( 'prayer-list-daily.html' );
    }

    /**
     * Register REST Endpoints
     * @link https://github.com/DiscipleTools/disciple-tools-theme/wiki/Site-to-Site-Link for outside of wordpress authentication
     */
    public function add_endpoints() {
        $namespace = $this->root . '/v1';
        register_rest_route(
            $namespace, '/'.$this->type, [
                [
                    'methods'  => "POST",
                    'callback' => [ $this, 'endpoint' ],
                    'permission_callback' => '__return_true',
                ],
            ]
        );
    }

    public function endpoint( WP_REST_Request $request ) {
        $params = $request->get_params();

        if ( ! isset( $params['parts'], $params['action'] ) ) {
            return new WP_Error( __METHOD__, "Missing parameters", [ 'status' => 400 ] );
        }

        $params = dt_recursive_sanitize_array( $params );
        $action = sanitize_text_field( wp_unslash( $params['action'] ) );

        switch ( $action ) {
            case 'get_all':
                return $this->endpoint_get_all( $params['parts'] );
            case 'get':
                return $this->endpoint_get( $params['parts'] );
            case 'filter_list':
                return $this->endpoint_filter_lists( $params['parts'] );
            case 'filter':
                return $this->endpoint_filter( $params['parts'], $params['post_type'], $params['meta_value'] );
            case 'log':
                if ( ! isset( $params['post_id'] ) ){
                    return new WP_Error( __METHOD__, "Missing parameters", [ 'status' => 400 ] );
                }
                return $this->endpoint_log( $params['parts'], $params['post_id'] );
            default:
                return new WP_Error( __METHOD__, "Missing valid action", [ 'status' => 400 ] );
        }
    }

    public function endpoint_get_all( $parts ) {
        global $wpdb;
        $data = [
            'tags' => [],
            'list' => [],
            'totals' => [],
            'status' => [ 'active' ],
            'status_list' => [],
            'status_totals' => []
        ];

        // TAGS
        $results = $wpdb->get_results( $wpdb->prepare( "
            SELECT p.ID as post_id,
                   p.post_title as name,
                   p.post_type,
                   (
                    SELECT r.timestamp
                    FROM $wpdb->dt_reports r
                    WHERE r.user_id = %d AND r.post_id = p.ID AND r.type = %s AND r.subtype = %s
                    ORDER BY timestamp DESC LIMIT 1
                    ) as last_report,
                   (SELECT count(id)
                       FROM $wpdb->dt_reports rr
                       WHERE rr.user_id = %d AND rr.post_id = p.ID AND rr.type = %s AND rr.subtype = %s
                       GROUP BY rr.post_id
                   ) as times_prayed
            FROM $wpdb->dt_post_user_meta pum
            JOIN $wpdb->posts p ON p.ID=pum.post_id
            WHERE pum.user_id = %d
              AND pum.meta_key = %s
              AND pum.meta_value != 'none'
        ", $parts['post_id'], $parts['root'], $parts['type'], $parts['post_id'], $parts['root'], $parts['type'], $parts['post_id'], $this->meta_key ), ARRAY_A );

        $tags = $wpdb->get_results( $wpdb->prepare( "
            SELECT pum.post_id, pum.meta_value as tag
            FROM $wpdb->dt_post_user_meta pum
            WHERE pum.user_id = %d
              AND pum.meta_key = %s
        ", $parts['post_id'], $this->meta_key ), ARRAY_A );

        $today_at_midnight = strtotime( "today" );

        foreach ( $results as $item ) {

            $item['last_report'] = (int) $item['last_report'];
//            $item['id'] = (int) $item['id'];
            $item['post_id'] = (int) $item['post_id'];
            $item['times_prayed'] = (int) $item['times_prayed'];
            $item['prayed_today'] = ( $item['last_report'] >= $today_at_midnight );

            $data['list'][$item['post_id']] = $item;

            if ( ! isset( $data['totals'][$item['post_type']] ) ) {
                $data['totals'][$item['post_type']] = 0;
            }
            $data['totals'][$item['post_type']]++;
        }
        foreach ( $tags as $tag ){
            if ( isset( $data['list'][$tag['post_id']] ) ) {
                if ( ! isset( $data['list'][$tag['post_id']]['tags'] ) ) {
                    $data['list'][$tag['post_id']]['tags'] = [];
                }
                $data['list'][$tag['post_id']]['tags'][] = $tag['tag'];

                $tag_key = $this->underscore( $tag['tag'] );
                if ( ! isset( $data['tags'][$tag_key] ) ) {
                    $data['tags'][$tag_key] = [
                        'label' => '',
                        'count' => 0,
                    ];
                }
                $data['tags'][$tag_key]['label'] = $tag['tag'];
                $data['tags'][$tag_key]['count']++;
            }
        }
        // end tagged posts

        // STATUS
        $types = [ 'active' ];
        $keys = [ 'overall_status', 'group_status', 'status' ];
        $status_types = dt_array_to_sql( $types );
        $status_key = dt_array_to_sql( $keys );

        // @phpcs:disable
        $status = $wpdb->get_results( $wpdb->prepare( "
            SELECT DISTINCT s.post_id,
                   p.post_title as name,
                   p.post_type,
                   pm.meta_value as status,
                   ( SELECT r.timestamp
                     FROM $wpdb->dt_reports r
                     WHERE r.user_id = %d AND r.post_id = p.ID AND r.type = %s AND r.subtype = %s
                     ORDER BY timestamp DESC LIMIT 1) as last_report,
                   (SELECT count(id)
                     FROM $wpdb->dt_reports rr
                     WHERE rr.user_id = %d AND rr.post_id = p.ID AND rr.type = %s AND rr.subtype = %s
                     GROUP BY rr.post_id) as times_prayed
            FROM $wpdb->dt_share s
            JOIN $wpdb->postmeta pm ON pm.post_id=s.post_id AND pm.meta_key IN ($status_key) AND pm.meta_value IN ($status_types)
            LEFT JOIN $wpdb->posts p ON p.ID=s.post_id
            LEFT JOIN $wpdb->dt_post_user_meta pum ON s.post_id=pum.post_id
            WHERE s.user_id = %d
        ", $parts['post_id'], $parts['root'], $parts['type'], $parts['post_id'], $parts['root'], $parts['type'], $parts['post_id'], $parts['post_id'] ), ARRAY_A );
        // @phpcs:enable

        foreach ( $status as $item ) {

            $item['last_report'] = (int) $item['last_report'];
            $item['post_id'] = (int) $item['post_id'];
            $item['times_prayed'] = (int) $item['times_prayed'];
            $item['prayed_today'] = ( $item['last_report'] >= $today_at_midnight );

            $data['status_list'][$item['post_id']] = $item;

            if ( ! isset( $data['status_totals'][$item['post_type']] ) ) {
                $data['status_totals'][$item['post_type']] = 0;
            }
            $data['status_totals'][$item['post_type']]++;

        }

        return $data;
    }

    public function underscore( $str, array $no_strip = [] ) {
        // non-alpha and non-numeric characters become spaces
        $str = preg_replace( '/[^a-z0-9' . implode( "", $no_strip ) . ']+/i', ' ', $str );
        $str = trim( $str );
        $str = str_replace( " ", "_", $str );
        $str = strtolower( $str );

        return $str;
    }

    public function endpoint_get( $parts ) {
        global $wpdb;

        $data = [
            'lists' => [
                'today' => [],
                'weekly' => [],
                'monthly' => [],
            ],
            'counts' => [
                'today' => 0,
                'weekly' => 0,
                'monthly' => 0,
            ],
        ];

        $day_number = gmdate( 'N', time() );
        $day_key = 'every_' .  $day_number;

        $time_at_start_of_day = strtotime( 'today' );

        $data['lists']['today'] = $wpdb->get_results( $wpdb->prepare( "
            SELECT p.ID as post_id, p.post_title as name, p.post_type
            FROM $wpdb->dt_post_user_meta pum
            JOIN $wpdb->posts p ON p.ID=pum.post_id
            WHERE pum.user_id = %s
                AND pum.meta_key = %s
                AND ( pum.meta_value = 'every_day' OR pum.meta_value = %s )
                AND pum.post_id NOT IN (
                    SELECT DISTINCT r.post_id
                    FROM $wpdb->dt_reports r
                    WHERE r.timestamp > %d
                      AND r.parent_id = %d
                    AND r.type = %s
                    AND r.subtype = %s
                )
        ", $parts['post_id'], $this->meta_key, $day_key, $time_at_start_of_day, $parts['post_id'], $parts['root'], $parts['type'] ), ARRAY_A );
        if ( ! empty( $data['lists']['today'] ) ){
            $data['counts']['today'] = count( $data['lists']['today'] );
        }

        $time_a_week_ago = strtotime( '1 week ago' );
        $data['lists']['weekly'] = $wpdb->get_results( $wpdb->prepare( "
            SELECT p.ID as post_id, p.post_title as name, p.post_type
            FROM $wpdb->dt_post_user_meta pum
            JOIN $wpdb->posts p ON p.ID=pum.post_id
            WHERE pum.user_id = %s
                AND pum.meta_key = %s
                AND pum.meta_value = 'every_week'
                AND pum.post_id NOT IN (
                    SELECT DISTINCT r.post_id
                    FROM $wpdb->dt_reports r
                    WHERE r.timestamp > %d
                      AND r.parent_id = %d
                    AND r.type = %s
                    AND r.subtype = %s
                )
        ", $parts['post_id'], $this->meta_key, $time_a_week_ago, $parts['post_id'], $parts['root'], $parts['type'], ), ARRAY_A );
        if ( ! empty( $data['lists']['weekly'] ) ){
            $data['counts']['weekly'] = count( $data['lists']['weekly'] );
        }

        $time_a_month_ago = strtotime( '1 month ago' );
        $data['lists']['monthly'] = $wpdb->get_results( $wpdb->prepare( "
            SELECT p.ID as post_id, p.post_title as name, p.post_type
            FROM $wpdb->dt_post_user_meta pum
            JOIN $wpdb->posts p ON p.ID=pum.post_id
            WHERE pum.user_id = %s
                AND pum.meta_key = %s
                AND pum.meta_value = 'every_month'
                AND pum.post_id NOT IN (
                    SELECT DISTINCT r.post_id
                    FROM $wpdb->dt_reports r
                    WHERE r.timestamp > %d
                    AND r.parent_id = %d
                    AND r.type = 'prayer_list_app'
                    AND r.subtype = 'daily'
                )
        ", $parts['post_id'], $this->meta_key, $time_a_month_ago, $parts['post_id'] ), ARRAY_A );
        if ( ! empty( $data['lists']['monthly'] ) ){
            $data['counts']['monthly'] = count( $data['lists']['monthly'] );
        }

        return $data;
    }

    public function endpoint_filter( $parts, $post_type, $meta_value ) {
        global $wpdb;

        $data = [
            'lists' => [],
            'counts' => [],
        ];

        if ( ! isset( $data['lists'][$meta_value] ) ) {
            $data['lists'][$meta_value] = [];
        }
        if ( ! isset( $data['counts'][$meta_value] ) ) {
            $data['counts'][$meta_value] = 0;
        }

        $data['lists'][$meta_value] = $wpdb->get_results( $wpdb->prepare( "
            SELECT p.ID as post_id, p.post_title as name, p.post_type
            FROM $wpdb->dt_post_user_meta pum
            JOIN $wpdb->posts p ON p.ID=pum.post_id
            WHERE pum.user_id = %s
              AND pum.meta_key = %s
              AND p.post_type = %s
              AND pum.meta_value = %s
        ", $parts['post_id'], $this->meta_key, $post_type, $meta_value ), ARRAY_A );
        if ( ! empty( $data['lists'][$meta_value] ) ){
            $data['counts'][$meta_value] = count( $data['lists'][$meta_value] );
        }

        return $data;
    }

    public function endpoint_filter_lists( $parts ) {
        global $wpdb;

        $data = [
            'lists' => [],
            'counts' => [],
        ];

        $results = $wpdb->get_results( $wpdb->prepare( "
            SELECT p.post_type, pum.meta_value, count(p.ID) as count
            FROM $wpdb->dt_post_user_meta pum
            JOIN $wpdb->posts p ON p.ID=pum.post_id
            WHERE pum.user_id = %s
              AND pum.meta_key = %s
            GROUP BY p.post_type, pum.meta_value
        ", $parts['post_id'], $this->meta_key ), ARRAY_A );

        foreach ( $results as $result ) {
            if ( ! isset( $data['lists'][$result['post_type']] ) ) {
                $data['lists'][$result['post_type']] = [];
            }
            $data['lists'][$result['post_type']][$result['meta_value']] = $result;
        }

        return $data;
    }

    public function endpoint_log( $parts, $post_id ) {
        $post_type = get_post_type( $post_id );

        $args = [
            'user_id' => $parts['post_id'], // using parent_id to record the user_id. i.e. parent of the record is the user.
            'post_id' => $post_id,
            'post_type' => $post_type,
            'type' => $parts['root'],
            'subtype' => $parts['type'],
            'payload' => null,
            'value' => 1,
            'time_end' => time(),
        ];

        // get geolocation of the contact, not the user
        $post_object = DT_Posts::get_post( $post_type, $post_id, true, false, true );
        if ( isset( $post_object['location_grid_meta'] ) ) {
            $location = $post_object['location_grid_meta'][0];
            if ( isset( $location['lng'] ) ) {
                $args['lng'] = $location['lng'];
                $args['lat'] = $location['lat'];
                $args['level'] = $location['level'];
                $args['label'] = $location['label'];
                $args['grid_id'] = $location['grid_id'];
            }
        } else if ( isset( $post_object['location_grid'][0] ) ) {
            $location = $post_object['location_grid'][0];
            $grid_record = Disciple_Tools_Mapping_Queries::get_by_grid_id( $location['id'] );
            if ( isset( $grid_record['lng'] ) ) {
                $args['lng'] = $grid_record['lng'];
                $args['lat'] = $grid_record['lat'];
                $args['level'] = $grid_record['level'];
                $args['label'] = $location['label'];
                $args['grid_id'] = $location['grid_id'];
            }
        }

        return Disciple_Tools_Reports::insert( $args );
    }

    public function get_filter_counts() {
        global $wpdb;
        $current_user = get_current_user_id(); // @todo can't use this in magic because of non-logged in

        $results = $wpdb->get_results( $wpdb->prepare( "
            SELECT p.post_type, pum.meta_value, count(pum.id) as count
                FROM $wpdb->dt_post_user_meta pum
                JOIN $wpdb->posts p ON p.ID=pum.post_id
                WHERE
                pum.user_id = %d
                AND pum.meta_key = %s
                GROUP BY p.post_type, pum.meta_value
        ", $current_user, $this->meta_key ), ARRAY_A);

        $filters = [];
        if ( ! empty( $results ) ) {
            foreach ( $results as $result ){
                if ( ! isset( $filters[$result['post_type']][$result['meta_value']] ) ){
                    $filters[$result['post_type']][$result['meta_value']] = 0;
                }
                $filters[$result['post_type']][$result['meta_value']] = $filters[$result['post_type']][$result['meta_value']] + $result['count'];
            }
        }

        return $filters;
    }
}
DT_Prayer_List_Magic_Link::instance();
