<?php
/*
 * Plugin Name: Jeremy
 * Description: Track Activity on the Jetpack Repo and keep it tidy
 * Version: 1.0.0
 * Author: Osk
 */


$sorceress_trackers = [];

require 'lib/RationalOptionPages.php';

define( 'SORCERESS_FILTER', 10 * 1024 );
define( 'SORCERESS_DEBUG', false );
sorceress_init();

function sorceress_init() {
	// do_action( 'sorceress_init' );
	add_filter( 'all', 'sorceress_all_action' );
	add_action( 'shutdown', 'sorceress_shutdown', 1000000 );
	// sorceress_setup_settings_page();
}



function sorceress_all_action( $tag ) {
	global $wp_filter, $sorceress_trackers;

	// Let's assume this is enough of a check
	// if ( sorceress_is_jetpack_defined_filter( $tag ) ) {
	// 	//error_log( "Ignoring filter $tag" );
	// 	return;
	// }
	if ( sorceress_is_ignorable_filter( $tag ) ) {
		//error_log( "Ignoring filter $tag" );
		return;
	}
	$isset = isset( $wp_filter[$tag] );
	if ( SORCERESS_DEBUG && $isset ) {
		// l( "\$wp_filter[$tag] is not set" );
	}
	$callbacks = isset( $wp_filter[ $tag ] ) ? sorceress_parse_filter_callbacks( $wp_filter[ $tag ] ) : [];

	$sorceress_trackers[] = [
		'filter' => $tag,
		'start' => sorceress_get_memory(),
		'end' => 0,
		'peak_start' => sorceress_get_peak_memory(),
		'peak_end' => 'N/A',
		'peak_diff' => 'N/A',
		'callbacks' => join( ', ', $callbacks ),
	];
	add_filter( $tag, 'sorceress_tracker_end' , 1000000 );
}

function sorceress_is_jetpack_defined_filter( $tag ) {
	$prefix = 'jetpack_';
	return substr( $tag , 0, strlen( $prefix ) ) === $prefix;
}
function sorceress_is_ignorable_filter( $tag ) {
	$ignored = [
			'gettext',
			'esc_html',
	];
	return in_array( $tag, $ignored );
}

function sorceress_tracker_start() {
	global $wp_current_filter;
}

function sorceress_tracker_end( $filterable = '' ) {
	global $wp_current_filter, $sorceress_trackers;

	$current_filter = $wp_current_filter[ count( $wp_current_filter) - 1];
	$sorceress_trackers[ count( $sorceress_trackers ) - 1 ]['end'] = sorceress_get_memory();
	$sorceress_trackers[ count( $sorceress_trackers ) - 1 ]['peak_end'] = sorceress_get_peak_memory();
	remove_filter($current_filter, 'sorceress_tracker_end' , 1000000 );
	return $filterable;
}

function sorceress_shutdown() {
	global $sorceress_trackers;
	foreach( $sorceress_trackers as &$t ) {
		$t['total'] = ( $t['end'] - $t['start'] ) ;
		$t['peak_diff'] = ( $t['peak_end'] - $t['peak_start'] );
		$t['peak_start'] = $t['peak_start'];
		$t['peak_end'] = $t['peak_end'];
	}
	if ( SORCERESS_DEBUG ) {
		l( sorceress_filter( $sorceress_trackers, SORCERESS_FILTER ) );
	} else {
		foreach( sorceress_filter( $sorceress_trackers, SORCERESS_FILTER ) as $t ) {
			$s = sprintf( '%01.2f MB - %s peak increased by %01.2f KB. Callbacks: %s',
				$t['peak_end'] / 1024 / 1024,
				$t['filter'],
				$t['peak_diff'] / 1024,
				$t['callbacks'] );
			l( $s );
		}
	}

}

function sorceress_get_memory() {
	return memory_get_usage( settings( 'real_usage' ) );
}
function sorceress_get_peak_memory() {
	return memory_get_peak_usage( settings( 'real_usage' ) );
}

function sorceress_filter( $all, $min ) {
	return array_filter( $all, function( $a ) use( $min ) {
		if ( $a['peak_diff'] >= $min ) {
			return true;
		}
	} );
}

function sorceress_setup_settings_page() {
	$settings_page = array(
		'sorceress' => array(
			'page_title' => __( 'Sorceress', 'sorceress' ),
			'menu_title' => __( 'Sorceress' ),
			'icon_url' => 'dashicons-tickets',
			'menu_slug' => 'sorceress',
			'sections' => array(
				'section-one' => array(
					'title' => __( 'Memory Settings', 'sorceress' ),
					'fields' => array(
						'real_usage' => array(
							'id' => 'real_usage',
							'title' => __( 'Check this to get total memory allocated from system', 'jurassic-ninja' ),
							'text' => __( 'Set this to get total memory allocated from system, including unused pages. If not checked only the used memory is reported.', 'jurassic-ninja' ),
							'type' => 'checkbox',
							'checked' => false,
						),
					)
				),
			),
		),
	);
	new \RationalOptionPages( $settings_page );
}

/**
 * Access a plugin option
 * @param  String $key The particular option we want to access
 * @param  Mixed  $default As with get_option you can specify a defaul value to return if the option is not set
 * @return String      The option value. All of the are just strings.
 */
function settings( $key = null, $default = null ) {
	//$options = get_option( 'sorceress' );
	$options = array(
		'real_usage' => true,
	);

	if ( ! isset( $options[ $key ] ) ) {
		return func_num_args() === 2 ? $default : null ;
	}
	return $options[ $key ];
}


function sorceress_parse_filter_callbacks( $filter ) {
	$callbacks = [];
	$priorities = is_array( $filter->callbacks ) ? $filter->callbacks : [];
	if ( is_array( $priorities ) && count( $priorities ) ) {
		foreach( $priorities as $priority ) {
			foreach( $priority as $callback ) {
				if (  gettype( $callback['function'] ) === 'string' ) {
					$callbacks[] = $callback['function'];
				} else if ( gettype( $callback['function'][0] ) === 'object' ) {
					$callbacks[] =  get_class( $callback['function'][0] ) . '->' . $callback['function'][1];
				} else {
					$callbacks[] =  $callback['function'][0] . '::' . $callback['function'][1];
				}
			}
		}
	}
	return $callbacks;
}
