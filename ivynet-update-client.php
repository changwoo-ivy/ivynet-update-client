<?php
/**
 * Plugin Name: Ivynet Update Client
 * Description: 아이비넷의 플러그인 업데이트 체크를 위한 확장 플러그인
 * Version:     0.0.0
 * Author:      Ivynet
 * Author URI:  https://ivynet.kr
 * License:     GPLv2+
 */

define( 'IUC_MAIN', __FILE__ );
define( 'IUC_DIR', __DIR__ );
define( 'IUC_VERSION', '0.0.0' );

add_action( 'load-plugins.php', 'iuc_update_plugins', 100 );
add_action( 'load-update.php', 'iuc_update_plugins', 100 );
add_action( 'load-update-core.php', 'iuc_update_plugins', 100 );
add_action( 'wp_update_plugins', 'iuc_update_plugins', 100 );

function iuc_update_plugins( $extra_stats = array() ) {
    if ( wp_installing() ) {
        return;
    }

    // include an unmodified $wp_version
    /**
     * @var string $wp_version
     */
    include( ABSPATH . WPINC . '/version.php' );

    $plugins      = get_plugins();
    $translations = wp_get_installed_translations( 'plugins' );

    $active  = get_option( 'active_plugins', array() );
    $current = get_site_transient( 'update_plugins' );
    if ( ! is_object( $current ) ) {
        $current = new stdClass();
    }

    $new_option               = new stdClass;
    $new_option->last_checked = time();

    $doing_cron = wp_doing_cron();

    // Check for update on a different schedule, depending on the page.
    switch ( current_filter() ) {
        case 'upgrader_process_complete' :
            $timeout = 0;
            break;
        case 'load-update-core.php' :
            $timeout = MINUTE_IN_SECONDS;
            break;
        case 'load-plugins.php' :
        case 'load-update.php' :
            $timeout = HOUR_IN_SECONDS;
            break;
        default :
            if ( $doing_cron ) {
                $timeout = 0;
            } else {
                $timeout = 12 * HOUR_IN_SECONDS;
            }
    }

    $time_not_changed = isset( $current->last_checked ) && $timeout > ( time() - $current->last_checked );
    $time_not_changed = FALSE; // 개발중.
    if ( $time_not_changed && ! $extra_stats ) {
        $plugin_changed = FALSE;
        foreach ( $plugins as $file => $p ) {
            $new_option->checked[ $file ] = $p['Version'];

            if ( ! isset( $current->checked[ $file ] ) || strval( $current->checked[ $file ] ) !== strval( $p['Version'] ) ) {
                $plugin_changed = TRUE;
            }
        }

        if ( isset ( $current->response ) && is_array( $current->response ) ) {
            foreach ( $current->response as $plugin_file => $update_details ) {
                if ( ! isset( $plugins[ $plugin_file ] ) ) {
                    $plugin_changed = TRUE;
                    break;
                }
            }
        }

        // Bail if we've checked recently and if nothing has changed
        if ( ! $plugin_changed ) {
            return;
        }
    }

    // Update last_checked for current to prevent multiple blocking requests if request hangs
    $current->last_checked = time();
    set_site_transient( 'update_plugins', $current );

    $to_send = compact( 'plugins', 'active' );
    /**
     * Filters the locales requested for plugin translations.
     *
     * @since 3.7.0
     * @since 4.5.0 The default value of the `$locales` parameter changed to include all locales.
     *
     * @param array $locales Plugin locales. Default is all available locales of the site.
     */
    $locales = array_unique( apply_filters( 'plugins_update_check_locales',
        array_values( get_available_languages() ) ) );

    if ( $doing_cron ) {
        $timeout = 30;
    } else {
        // Three seconds, plus one extra second for every 10 plugins
        $timeout = 3 + (int) ( count( $plugins ) / 10 );
    }

    $options = array(
//        'timeout'    => $timeout,
        'timeout'    => -1,
        'body'       => array(
            'plugins'      => wp_json_encode( $to_send ),
            'translations' => wp_json_encode( $translations ),
            'locale'       => wp_json_encode( $locales ),
            'all'          => wp_json_encode( TRUE ),
        ),
        'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' ),
        'cookies'    => array( 'XDEBUG_SESSION' => 'phpstorm-xdebug' ),
    );

    if ( $extra_stats ) {
        $options['body']['update_stats'] = wp_json_encode( $extra_stats );
    }

    $url = $http_url = 'http://wp48.local/plugins/update-check/1.0/';
//    if ( $ssl = wp_http_supports( array( 'ssl' ) ) ) {
//        $url = set_url_scheme( $url, 'https' );
//    }

    $raw_response = wp_remote_post( $url, $options );
    if ( $ssl && is_wp_error( $raw_response ) ) {
        trigger_error( sprintf(/* translators: %s: support forums URL */
                           __( 'An unexpected error occurred. Something may be wrong with WordPress.org or this server&#8217;s configuration. If you continue to have problems, please try the <a href="%s">support forums</a>.' ),
                           __( 'https://wordpress.org/support/' ) ) . ' ' . __( '(WordPress could not establish a secure connection to WordPress.org. Please contact your server administrator.)' ),
            headers_sent() || WP_DEBUG ? E_USER_WARNING : E_USER_NOTICE );
        $raw_response = wp_remote_post( $http_url, $options );
    }

    if ( is_wp_error( $raw_response ) || 200 != wp_remote_retrieve_response_code( $raw_response ) ) {
        return;
    }

    $response = json_decode( wp_remote_retrieve_body( $raw_response ), TRUE );
    foreach ( $response['plugins'] as &$plugin ) {
        $plugin = (object) $plugin;
        if ( isset( $plugin->compatibility ) ) {
            $plugin->compatibility = (object) $plugin->compatibility;
            foreach ( $plugin->compatibility as &$data ) {
                $data = (object) $data;
            }
        }
    }
    unset( $plugin, $data );
    foreach ( $response['no_update'] as &$plugin ) {
        $plugin = (object) $plugin;
    }
    unset( $plugin );

    if ( is_array( $response ) ) {
        $new_option->response     = $response['plugins'];
        $new_option->translations = $response['translations'];
        $new_option->no_update    = $response['no_update'];
    } else {
        $new_option->response     = array();
        $new_option->translations = array();
        $new_option->no_update    = array();
    }

    // set_site_transient( 'update_plugins', $new_option );
}