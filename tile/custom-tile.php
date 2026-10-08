<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

class DT_Prayer_List_Tile
{
    public $page_title = 'Prayer List';
    public $page_description = 'A micro app for daily prayer list';
    public $root = "prayer_list_app";
    public $type = 'daily';
    public $post_type = 'contacts';
    public $meta_key = 'prayer_list_app_daily_magic_key';

    private static $_instance = null;
    public static function instance(){
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct(){
        add_filter( 'dt_details_additional_tiles', [ $this, 'dt_details_additional_tiles' ], 10, 2 );
        add_filter( 'dt_settings_apps_list', [ $this, 'dt_settings_apps_list' ], 10, 1 );
        add_filter( 'dt_custom_fields_settings', [ $this, 'dt_custom_fields_settings' ], 50, 2 );

        add_filter( "dt_user_list_filters", [ $this, "dt_user_list_filters" ], 30, 2 );
    }

    public function dt_details_additional_tiles( $tiles, $post_type = "" ) {
        if ( $post_type === 'contacts' && ! isset( $tiles["apps"] ) ){
            $tiles["apps"] = [
                "label" => __( "Apps", 'disciple_tools' ),
                "description" => __( "Apps available on this record.", 'disciple_tools' )
            ];
        }
        return $tiles;
    }

    public function dt_settings_apps_list( $apps_list ) {
        $apps_list[$this->meta_key] = [
            'key' => $this->meta_key,
            'url_base' => $this->root. '/'. $this->type,
            'label' => $this->page_title,
            'description' => $this->page_description,
        ];
        return $apps_list;
    }

    /**
     * @param array $fields
     * @param string $post_type
     * @return array
     */
    public function dt_custom_fields_settings( array $fields, string $post_type = "" ) {

        if ( in_array( $post_type, [ 'contacts','groups','trainings','streams' ] ) ){
            $fields[$this->meta_key] = [
                'name' => __( 'Prayer List Tags', 'disciple_tools' ),
                'description' => _x( 'Add tags to organize your prayer lists', 'Optional Documentation', 'disciple_tools' ),
                'type'        => 'tags',
                'private' => true,
                'default'     => [],
                'tile'        => 'status',
                'icon' => get_template_directory_uri() . "/dt-assets/images/tag.svg",
            ];
        }

        return $fields;
    }

    public function dt_user_list_filters( $filters, $post_type ){

        if ( in_array( $post_type, [ 'contacts','groups','trainings', 'streams' ] ) ) {
            $counts = $this->get_my_prayer_counts( $post_type );
            $meta_value_counts = [];
            $total_prayer_items = 0;
            foreach ($counts as $count) {
                $total_prayer_items += $count["count"];
                dt_increment( $meta_value_counts[$count["meta_value"]], $count["count"] );
            }

            $filters["tabs"][] = [
                "key" => $this->meta_key,
                "label" => _x( "Prayer List", 'List Filters', 'disciple_tools' ),
                "count" => $total_prayer_items,
                "order" => 20
            ];

            foreach ($counts as $count) {
                $filters["filters"][] = [
                    'ID' => 'prayer_' . $count["meta_value"],
                    'tab' => $this->meta_key,
                    'name' => $count['meta_value'],
                    'query' => [
                        'fields' => [ $this->meta_key => [ $count['meta_value'] ] ],
                        'sort' => 'name',
                        'shared_with' => [ "me" ]
                    ],
                    "count" => $meta_value_counts[$count["meta_value"]] ?? 0,
                ];
            }
        }
        return $filters;
    }

    public function get_my_prayer_counts( $post_type ){

        global $wpdb;
        $current_user = get_current_user_id();

        $results = $wpdb->get_results( $wpdb->prepare( "
            SELECT pum.meta_value, count(pum.id) as count
                FROM $wpdb->dt_post_user_meta pum
                JOIN $wpdb->posts p ON p.ID=pum.post_id
                WHERE
                pum.user_id = %d
                AND pum.meta_key = %s
                AND p.post_type = %s
                GROUP BY pum.meta_value
        ", $current_user, $this->meta_key, $post_type ), ARRAY_A);

        return $results;
    }
}
DT_Prayer_List_Tile::instance();
