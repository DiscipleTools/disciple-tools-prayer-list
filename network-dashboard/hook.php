<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

add_action( 'dt_insert_report', function( $args ){

    if ( ! function_exists( 'dt_network_site_id' ) ) {
        return;
    }
    if ( ! class_exists( 'DT_Network_Dashboard_Metrics_Base' ) ) {
        return;
    }
    if ( ! isset( $args['type'], $args['subtype'], $args['post_id'] ) ) {
        return;
    }

    if ( 'prayer_list_app' === $args['type'] && 'daily' === $args['subtype'] ) {
        $data = [
            'site_id' => dt_network_site_id(),
            'site_record_id' => null,
            'site_object_id' => $args['post_id'],
            'action' => $args['type'],
            'category' => $args['subtype'],
            'lng' => $args['lng'] ?? '',
            'lat' => $args['lat'] ?? '',
            'level' => $args['level'] ?? '',
            'label' => $args['label'] ?? '',
            'grid_id' => $args['grid_id'] ?? '',
            'payload' => $args['payload'] ?? [ 'language_code' => get_locale() ],
            'timestamp' => $args['time_end'] ?? time()
        ];

        DT_Network_Activity_Log::insert( $data );
    }
}, 10, 1 );

/**
 * READ LOG
 */
add_filter( 'dt_network_dashboard_build_message', 'dt_prayer_list_build_message', 10, 1 );
function dt_prayer_list_build_message( $activity_log ){

    if ( ! function_exists( 'dt_create_initials' ) ) {
        foreach ( $activity_log as $index => $log ){

            /* prayer_list_app */
            if ( 'prayer_list_app' === $log['action'] ) {
                $initials = dt_create_initials( $log['lng'], $log['lat'], $log['payload'] );
                $initials_2 = dt_create_initials( $log['lng'], $log['lat'], $log['payload'] );
                $activity_log[$index]['message'] = $initials . ' is praying for ' . $initials_2;
            }
        }
    }

    return $activity_log;
}
