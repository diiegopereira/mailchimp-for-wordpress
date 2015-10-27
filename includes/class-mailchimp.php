<?php

class MC4WP_MailChimp {

	/**
	 * @var string
	 */
	protected $transient_name = 'mc4wp_mailchimp_lists';

	/**
	 * Empty the Lists cache
	 */
	public function empty_cache() {
		delete_transient( $this->transient_name );
		delete_transient( $this->transient_name . '_fallback' );
		delete_transient( 'mc4wp_list_counts' );
		delete_transient( 'mc4wp_list_counts_fallback' );
	}

	/**
	 * Get MailChimp lists
	 * Try cache first, then try API, then try fallback cache.
	 *
	 * @param bool $force_fallback
	 *
	 * @return array
	 */
	public function get_lists( $force_fallback = false ) {

		$cached_lists = get_transient( $this->transient_name  );

		// if force_fallback is true, get lists from older transient
		if( $force_fallback ) {
			$cached_lists = get_transient( $this->transient_name . '_fallback' );
		}

		if( is_array( $cached_lists ) ) {
			return $cached_lists;
		}

		// transient was empty, get lists from MailChimp
		$api = mc4wp_get_api();
		$lists_data = $api->get_lists();

		// if we did not get an array, something failed.
		// try fallback transient (if not tried before)
		if( ! is_array( $lists_data ) ) {
			$cached_lists = get_transient( $this->transient_name . '_fallback' );

			if( is_array( $cached_lists ) ) {
				return $cached_lists;
			}

			// fallback transient was empty as well...
			return array();
		}


		$lists = array();

		foreach ( $lists_data as $list ) {

			$lists["{$list->id}"] = (object) array(
				'id' => $list->id,
				'name' => $list->name,
				'subscriber_count' => $list->stats->member_count,
				'merge_vars' => array(),
				'interest_groupings' => array()
			);

			// only get interest groupings if list has some
			if( $list->stats->grouping_count > 0 ) {
				// get interest groupings
				$groupings_data = $api->get_list_groupings( $list->id );
				if ( $groupings_data ) {
					$lists["{$list->id}"]->interest_groupings = array_map( array( $this, 'strip_unnecessary_grouping_properties' ), $groupings_data );
				}
			}

		}

		// get merge vars for all lists at once
		$merge_vars_data = $api->get_lists_with_merge_vars( array_keys( $lists ) );
		if ( $merge_vars_data ) {
			foreach ( $merge_vars_data as $list ) {
				// add merge vars to list
				$merge_vars = array_map( array( $this, 'strip_unnecessary_merge_vars_properties' ), $list->merge_vars );
				$merge_vars = $this->transform_fields( $merge_vars );
				$lists["{$list->id}"]->merge_vars = $merge_vars;
			}
		}

		// store lists in transients
		set_transient(  $this->transient_name, $lists, ( 24 * 3600 ) ); // 1 day
		set_transient(  $this->transient_name . '_fallback', $lists, 1209600 ); // 2 weeks

		return $lists;
	}

	/**
	 * Translates some MailChimp fields to our own format
	 *
	 * - Separates address fields into addr1, addr2, city, state, zip & country field
	 *
	 * @param array $fields
	 * @return array
	 */
	protected function transform_fields( array $fields = array() ) {

		$new = array();

		foreach( $fields as $key => $field ) {

			// check for custom method
			$transform_method = 'transform_' . $field->field_type .'_field';
			if( method_exists( $this, $transform_method ) ) {
				$transformed_field = call_user_func( array( $this, $transform_method ), $field );
				$new = array_merge( $new, $transformed_field );
				continue;
			}

			// no custom method exists, just add to array
			$new[] = $field;
		}

		return $new;
	}

	/**
	 * @param $field
	 *
	 * @return array
	 */
	protected function transform_address_field( $field ) {
		$new = array();

		// addr1
		$addr1 = clone $field;
		$addr1->name = "Address";
		$addr1->field_type = 'text';
		$addr1->tag = $field->tag . '[addr1]';
		$new[] = $addr1;

		// city
		$city = clone $field;
		$city->name = "City";
		$city->field_type = 'text';
		$city->tag = $field->tag . '[city]';
		$new[] = $city;

		// state
		$state = clone $field;
		$state->name = "State";
		$state->field_type = 'text';
		$state->tag = $field->tag . '[state]';
		$new[] = $state;

		// zip
		$zip = clone $field;
		$zip->name = "ZIP";
		$zip->field_type = 'text';
		$zip->tag = $field->tag . '[zip]';
		$new[] = $zip;

		// country
		$country = clone $field;
		$country->name = "Country";
		$country->field_type = 'country';
		$country->tag = $field->tag . '[country]';
		$new[] = $country;

		return $new;
	}

	/**
	 * Get a given MailChimp list
	 *
	 * @param int $list_id
	 * @param bool $force_fallback
	 *
	 * @return bool
	 */
	public function get_list( $list_id, $force_fallback = false ) {
		$lists = $this->get_lists( $force_fallback );

		if( isset( $lists[$list_id] ) ) {
			return $lists[$list_id];
		}

		return false;
	}

	/**
	 * Get the name of the MailChimp list with the given ID.
	 *
	 * @param int $id
	 * @return string
	 */
	public function get_list_name( $id ) {
		$list = $this->get_list( $id );

		if( is_object( $list ) && isset( $list->name ) ) {
			return $list->name;
		}

		return '';
	}

	/**
	 * Get the interest grouping object for a given list.
	 *
	 * @param string $list_id ID of MailChimp list that contains the grouping
	 * @param string $grouping_id ID of the Interest Grouping
	 *
	 * @return object|null
	 */
	public function get_list_grouping( $list_id, $grouping_id ) {
		$list = $this->get_list( $list_id, false, true );

		if( is_object( $list ) && isset( $list->interest_groupings ) ) {
			foreach( $list->interest_groupings as $grouping ) {

				if( $grouping->id !== $grouping_id ) {
					continue;
				}

				return $grouping;
			}
		}

		return null;
	}

	/**
	 * Get the name of a list grouping by its ID
	 *
	 * @param $list_id
	 * @param $grouping_id
	 *
	 * @return string
	 */
	public function get_list_grouping_name( $list_id, $grouping_id ) {

		$grouping = $this->get_list_grouping( $list_id, $grouping_id );
		if( isset( $grouping->name ) ) {
			return $grouping->name;
		}

		return '';
	}

	/**
	 * Get the group object for a group in an interest grouping
	 *
	 * @param string $list_id ID of MailChimp list that contains the grouping
	 * @param string $grouping_id ID of the Interest Grouping containing the group
	 * @param string $group_id_or_name ID or name of the Group
	 * @return object|null
	 */
	public function get_list_grouping_group( $list_id, $grouping_id, $group_id_or_name ) {
		$grouping = $this->get_list_grouping( $list_id, $grouping_id );
		if( is_object( $grouping ) && isset( $grouping->groups ) ) {
			foreach( $grouping->groups as $group ) {

				if( $group->id == $group_id_or_name || $group->name === $group_id_or_name ) {
					return $group;
				}

			}
		}

		return null;
	}

	/**
	 * Returns number of subscribers on given lists.
	 *
	 * @param array $list_ids Array of list id's.
	 * @return int Sum of subscribers for given lists.
	 */
	public function get_subscriber_count( $list_ids ) {

		// don't count when $list_ids is empty or not an array
		if( ! is_array( $list_ids ) || count( $list_ids ) === 0 ) {
			return 0;
		}

		$list_counts = get_transient( 'mc4wp_list_counts' );

		if( false === $list_counts ) {
			// make api call
			$api = mc4wp_get_api();
			$lists = $api->get_lists();
			$list_counts = array();

			if ( is_array( $lists ) ) {

				foreach ( $lists as $list ) {
					$list_counts["{$list->id}"] = $list->stats->member_count;
				}

				$transient_lifetime = apply_filters( 'mc4wp_lists_count_cache_time', 1200 ); // 20 mins by default

				set_transient( 'mc4wp_list_counts', $list_counts, $transient_lifetime );
				set_transient( 'mc4wp_list_counts_fallback', $list_counts, 86400 ); // 1 day
			} else {
				// use fallback transient
				$list_counts = get_transient( 'mc4wp_list_counts_fallback' );
				if ( false === $list_counts ) {
					return 0;
				}
			}
		}

		// start calculating subscribers count for all list combined
		$count = 0;
		foreach ( $list_ids as $id ) {
			$count += ( isset( $list_counts[$id] ) ) ? $list_counts[$id] : 0;
		}

		return apply_filters( 'mc4wp_subscriber_count', $count );
	}

	/**
	 * Build the group array object which will be stored in cache
	 *
	 * @param object $group
	 * @return object
	 */
	public function strip_unnecessary_group_properties( $group ) {
		return (object) array(
			'name' => $group->name,
		);
	}

	/**
	 * Build the groupings array object which will be stored in cache
	 *
	 * @param object $grouping
	 * @return object
	 */
	public function strip_unnecessary_grouping_properties( $grouping ) {
		return (object) array(
			'id' => $grouping->id,
			'name' => $grouping->name,
			'groups' => array_map( array( $this, 'strip_unnecessary_group_properties' ), $grouping->groups ),
			'form_field' => $grouping->form_field,
		);
	}

	/**
	 * Build the merge_var array object which will be stored in cache
	 *
	 * @param object $merge_var
	 * @return object
	 */
	public function strip_unnecessary_merge_vars_properties( $merge_var ) {
		$array = array(
			'name' => $merge_var->name,
			'field_type' => $merge_var->field_type,
			'req' => $merge_var->req,
			'tag' => $merge_var->tag,
		);

		if ( isset( $merge_var->choices ) ) {
			$array['choices'] = $merge_var->choices;
		}

		return (object) $array;

	}

	/**
	 * Get the name of a list field by its merge tag
	 *
	 * @param $list_id
	 * @param $tag
	 *
	 * @return string
	 */
	public function get_list_field_name_by_tag( $list_id, $tag ) {
		// try default fields
		switch( $tag ) {
			case 'EMAIL':
				return __( 'Email address', 'mailchimp-for-wp' );
				break;
			case 'OPTIN_IP':
				return __( 'IP Address', 'mailchimp-for-wp' );
				break;
		}
		// try to find field in list
		$list = $this->get_list( $list_id, false, true );
		if( is_object( $list ) && isset( $list->merge_vars ) ) {
			// try list merge vars first
			foreach( $list->merge_vars as $field ) {
				if( $field->tag !== $tag ) {
					continue;
				}
				return $field->name;
			}
		}
		return '';
	}

}
