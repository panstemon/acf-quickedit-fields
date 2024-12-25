<?php

namespace ACFQuickEdit\Admin;

use ACFQuickEdit\Core;
use ACFQuickEdit\Fields;

if ( ! defined( 'ABSPATH' ) )
	die('Nope.');

class BackendSearch extends Feature {

	/**
	 *	@inheritdoc
	 */
	public function get_type() {
		return 'backendsearch';
	}

	/**
	 *	@inheritdoc
	 */
	public function get_fieldgroup_option() {
		return 'allow_backendsearch';
	}

	/**
	 *	@inheritdoc
	 */
	public function load_field( $field ) {
		return wp_parse_args( $field, [
			'allow_backendsearch' => false,
		]);
	}

	/**
	 *	@inheritdoc
	 */
	public function init_fields() {
		$is_active = parent::init_fields();
		if ( $is_active ) {
			$this->init_meta_query();
		}
		return $is_active;
	}


	/**
	 *	@inheritdoc
	 */
	public function parse_query( $query ) {
		if ( ! $query->get('s') ) {
			return;
		}

		global $wpdb;

		/**
		 *	@return string `AND ( ( ( post_title LIKE ... ) OR ( post_content LIKE ... ) ) )`
		 */
		add_filter( 'posts_search', function( $search ) use ( $query, $wpdb ) {

			$all_sql = [];

			foreach( array_values( $query->get('search_terms') ) as $i => $term ) {
				$terms_sql = [];
				$terms_join = '';

				// stolen from wp_query
				$default_search_columns = array( 'post_title', 'post_excerpt', 'post_content' );
				$search_columns         = ! empty( $q['search_columns'] ) ? $q['search_columns'] : $default_search_columns;
				if ( ! is_array( $search_columns ) ) {
					$search_columns = array( $search_columns );
				}
				$search_columns = (array) apply_filters( 'post_search_columns', $search_columns, $search, $query );
				$search_columns = array_intersect( $search_columns, $default_search_columns );
				if ( empty( $search_columns ) ) {
					$search_columns = $default_search_columns;
				}
				// END stolen from wp_query

				$terms_sql[] = $wpdb->prepare(
					"(meta{$i}.meta_value LIKE %s)",
					'%'. $wpdb->esc_like($term) . '%'
				);

				foreach ( $search_columns as $search_column ) {
					$terms_sql[] = $wpdb->prepare(
						"({$wpdb->posts}.$search_column LIKE %s)",
						'%'. $wpdb->esc_like($term) . '%'
					);
				}
				$all_sql[] = '( ' . "\n" . implode( "\n" . ' OR ', $terms_sql ) . "\n" . ' )';

			}
			return ' AND (' . "\n" . implode(' AND ', $all_sql ) . "\n" . ')';
		});

		add_filter( 'posts_join', function($join) use ( $query, $wpdb ) {
			foreach( array_values( $query->get('search_terms') ) as $i => $term ) {
				$join .=  sprintf(
					" LEFT JOIN {$wpdb->postmeta} AS meta{$i} ON (meta{$i}.meta_key IN (%s) AND {$wpdb->posts}.ID = meta{$i}.post_id)",
					implode( ',', array_map( function($field) use ( $term, $wpdb ){
						return $wpdb->prepare('%s', $field->get_meta_key() );
					}, $this->fields ))
				);
			}
			return $join;
		});

		add_filter('posts_groupby', function($group) use( $wpdb ) {
			$posts_group = "{$wpdb->posts}.ID";
			if ( strpos( $group, $posts_group ) === false ) {
				$group .= " {$posts_group}";
			}
			return $group;
		});
	}

	/**
	 *	@inheritdoc
	 */
	public function parse_term_query( $query ) {
		if ( ! $query->query_vars['search'] ) {
			return;
		}
		global $wpdb;

		$sql = $this->get_search_query( $query->query_vars['search'] )->get_sql( 'term', 't', 'term_id', $query );
		$sql['where'] = preg_replace( '/(^ AND \(|\)$)/', '', $sql['where']);

		add_filter( 'terms_clauses', function($clauses) use ( $sql ) {
			// JOIN
			if ( strpos( $clauses['join'], $sql['join'] ) === false ) {
				$clauses['join'] .=  $sql['join'];
			}
			// WHERE
			if ( strpos( $clauses['where'], $sql['where'] ) === false ) {
				$clauses['where'] = preg_replace_callback( '/\(\(.*LIKE.*\)\)/imsU', function($matches) use ($sql) {
					$search = substr($matches[0],0,-1);
					$search .= ' OR ' . $sql['where'] . ')';
					return $search;
				}, $clauses['where'] );
			}

			// GROUP
			// $groupby = 'GROUP BY t.term_id';
			// if ( false === strpos( $clauses['groupby'], $groupby ) ) {
			// 	$clauses['groupby'] = " {$groupby} {$clauses['groupby']}";
			// }

			return $clauses;
		});
	}

	/**
	 *	@inheritdoc
	 */
	public function pre_get_users( $query ) {
		if ( ! $query->get('search') ) {
			return;
		}
		global $wpdb;

		$sql = $this->get_search_query( $query->get('search') )->get_sql( 'user', $wpdb->users, 'ID', $query );

		add_action( 'pre_user_query', function($query) use ($sql) {
			if ( strpos( $query->query_from, $sql['join'] ) === false ) {
				$query->query_from .=  $sql['join'];
			}
		});

		add_filter( 'user_search_columns', function( $columns ) use ($wpdb) {
			array_map( function($field) use ( &$columns, $wpdb ){
				$columns[] = $wpdb->prepare(
					"{$wpdb->usermeta}.meta_key = %s AND {$wpdb->usermeta}.meta_value",
					$field->get_meta_key()
				);

			}, $this->fields );
			return $columns;
		} );
	}


	/**
	 *	@param string $search
	 *	@return WP_Meta_Query
	 */
	private function get_search_query( $search ) {

		$search_query = [
			'relation' => 'OR',
		];
		array_map( function($field) use ( &$search_query, $search ){
			$search_query[] = [
				'key'     => $field->get_meta_key(),
				'value'   => $search,
				'compare' => 'LIKE',
			];
		}, $this->fields );

		$meta_query = new \WP_Meta_Query();
		$meta_query->parse_query_vars( [ 'meta_query' => $search_query ] );
		return $meta_query;
	}
}
