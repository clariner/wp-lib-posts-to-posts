<?php

class P2P_Side_Bpgroup extends P2P_Side {

    protected $item_type = 'P2P_Item_Bpgroup';

    function __construct( $query_vars ) {
        $this->query_vars = $query_vars;
    }

    function get_object_type() {
        return 'bpgroup';
    }

    function get_desc() {
        return __( 'Buddypress Group', P2P_TEXTDOMAIN );
    }

    function get_title() {
        return $this->get_desc();
    }

    function get_labels() {
        return (object) array(
            'singular_name' => __( 'Buddypress Group', P2P_TEXTDOMAIN ),
            'search_items' => __( 'Search Buddypress Groups', P2P_TEXTDOMAIN ),
            'not_found' => __( 'No Buddypress Groups found.', P2P_TEXTDOMAIN ),
        );
    }

    function can_edit_connections() {
        return true;
    }

    function can_create_item() {
        return false;
    }

    function translate_qv( $qv ) {
        if ( isset( $qv['p2p:include'] ) )
            $qv['include'] = _p2p_pluck( $qv, 'p2p:include' );

        if ( isset( $qv['p2p:exclude'] ) )
            $qv['exclude'] = _p2p_pluck( $qv, 'p2p:exclude' );

        if ( isset( $qv['p2p:search'] ) && $qv['p2p:search'] )
            $qv['search'] = '*' . _p2p_pluck( $qv, 'p2p:search' ) . '*';

        if ( isset( $qv['p2p:page'] ) && $qv['p2p:page'] > 0 ) {
            if ( isset( $qv['p2p:per_page'] ) && $qv['p2p:per_page'] > 0 ) {
                $qv['number'] = $qv['p2p:per_page'];
                $qv['offset'] = $qv['p2p:per_page'] * ( $qv['p2p:page'] - 1 );
            }
        }

        return $qv;
    }

    function do_query( $args ) {
		return new P2P_BP_Group_Query($args);
    }

    function capture_query( $args ) {
 
        $args['count_total'] = false;

        $uq = new P2P_BP_Group_Query;
        $uq->_p2p_capture = true; // needed by P2P_URL_Query

		// see http://core.trac.wordpress.org/ticket/21119
		$uq->query_vars = wp_parse_args( $args, array(
			'include' => array(),
			'exclude' => array(),
			'search' => '',
		) );

		$uq->prepare_query();

		return "SELECT $uq->query_fields $uq->query_from $uq->query_where $uq->query_orderby $uq->query_limit";
        //return 'SELECT * FROM '.$wpdb->prefix.'bp_groups bpg JOIN bp_groups_members bpgm ON bpg.id = bpgm.group_id WHERE bpgm.user_id IN ( '. $post->author .')';
    }

    function get_list( $query ) {
        $list = new P2P_List( $query->get_results(), $this->item_type );

        $qv = $query->query_vars;

        if ( isset( $qv['p2p:page'] ) ) {
            $list->current_page = $qv['p2p:page'];
            $list->total_pages = ceil( $query->get_total() / $qv['p2p:per_page'] );
        }

        return $list;
}

	function is_indeterminate( $side ) {
		return true;
	}

	function get_base_qv( $q ) {
		return array_merge( $this->query_vars, $q );
	}

	protected function recognize( $arg ) {
		if ( is_a( $arg, 'BP_Groups_Group' ) )
			return $arg;

		return false;
	}
}

class P2P_BP_Group_Query {

	/**
	 * Query vars, after parsing
	 *
	 * @since 3.5.0
	 * @access public
	 * @var array
	 */
	var $query_vars = array();

	/**
	 * List of found user ids
	 *
	 * @since 3.1.0
	 * @access private
	 * @var array
	 */
	var $results;

	/**
	 * Total number of found users for the current query
	 *
	 * @since 3.1.0
	 * @access private
	 * @var int
	 */
	var $total_bp_groups = 0;

	// SQL clauses
	var $query_fields;
	var $query_from;
	var $query_where;
	var $query_orderby;
	var $query_limit;

	/**
	 * PHP5 constructor.
	 *
	 * @since 3.1.0
	 *
	 * @param string|array $args Optional. The query variables.
	 * @return P2P_BP_Group_Query
	 */
	function __construct( $query = null ) {
		if ( ! empty( $query ) ) {
			$this->prepare_query( $query );
			$this->query();
		}
	}

	/**
	 * Prepare the query variables.
	 *
	 * @since 3.1.0
	 *
	 * @param string|array $args Optional. The query variables.
	 */
	function prepare_query( $query = array() ) {
		global $wpdb,$bp;

		$table = $bp->groups->table_name;
		if ( empty( $this->query_vars ) || ! empty( $query ) ) {
			$this->query_limit = null;
			$this->query_vars = wp_parse_args( $query, array(
				'include' => array(),
				'exclude' => array(),
				'search' => '',
			) );
		}

		$qv =& $this->query_vars;
		
		$this->query_fields = "$table.id, $table.name, $table.status, $table.slug";
		$this->query_from = "FROM $table";
		$this->query_where = "WHERE 1=1";
		$this->query_orderby = "ORDER BY name ASC";

		// limit
		if ( isset( $qv['number'] ) && $qv['number'] ) {
			if ( $qv['offset'] )
				$this->query_limit = $wpdb->prepare("LIMIT %d, %d", $qv['offset'], $qv['number']);
			else
				$this->query_limit = $wpdb->prepare("LIMIT %d", $qv['number']);
		}

		$search = '';
		if ( isset( $qv['search'] ) )
			$search = trim( $qv['search'] );

		if ( $search ) {
			$leading_wild = ( ltrim($search, '*') != $search );
			$trailing_wild = ( rtrim($search, '*') != $search );
			if ( $leading_wild && $trailing_wild )
				$wild = 'both';
			elseif ( $leading_wild )
				$wild = 'leading';
			elseif ( $trailing_wild )
				$wild = 'trailing';
			else
				$wild = false;
			if ( $wild )
				$search = trim($search, '*');

			$search_columns = array('name');

			$this->query_where .= $this->get_search_sql( $search, $search_columns, $wild );
		}

		if ( ! empty( $qv['include'] ) ) {
			$ids = implode( ',', wp_parse_id_list( $qv['include'] ) );
			$this->query_where .= " AND $table.id IN ($ids)";
		} elseif ( ! empty( $qv['exclude'] ) ) {
			$ids = implode( ',', wp_parse_id_list( $qv['exclude'] ) );
			$this->query_where .= " AND $table.id NOT IN ($ids)";
		}
	}

	/**
	 * Execute the query, with the current variables.
	 *
	 * @since 3.1.0
	 *
	 * @global wpdb $wpdb WordPress database object for queries.
	 */
	function query() {
		global $wpdb;

		$qv =& $this->query_vars;

		$query = "SELECT $this->query_fields $this->query_from $this->query_where $this->query_orderby $this->query_limit";

		$this->results = $wpdb->get_results( $query );

		if ( isset( $qv['count_total'] ) && $qv['count_total'] )
			$this->total_bp_groups = $wpdb->get_var( 'SELECT FOUND_ROWS()' );

		if ( !$this->results )
			return;
	}

	function get( $query_var ) {
		if ( isset( $this->query_vars[$query_var] ) )
			return $this->query_vars[$query_var];

		return null;
	}

	function set( $query_var, $value ) {
		$this->query_vars[$query_var] = $value;
	}

	function get_search_sql( $string, $cols, $wild = false ) {
		$string = esc_sql( $string );

		$searches = array();
		$leading_wild = ( 'leading' == $wild || 'both' == $wild ) ? '%' : '';
		$trailing_wild = ( 'trailing' == $wild || 'both' == $wild ) ? '%' : '';
		foreach ( $cols as $col ) {
			if ( 'ID' == $col )
				$searches[] = "$col = '$string'";
			else
				$searches[] = "$col LIKE '$leading_wild" . like_escape($string) . "$trailing_wild'";
		}

		return ' AND (' . implode(' OR ', $searches) . ')';
	}

	function get_results() {
		return $this->results;
	}

	function get_total() {
		return $this->total_bp_groups;
	}
}


