<?php /** @noinspection SyntaxError */

    /*
     * Shopclass - Free and open source theme for Osclass with premium features
     * Maintained and supported by Mindstellar Community
     * https://github.com/mindstellar/shopclass
     * Copyright (c) 2021.  Mindstellar
     *
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or
     * (at your option) any later version.
     *
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
     *
     *                     GNU GENERAL PUBLIC LICENSE
     *                        Version 3, 29 June 2007
     *
     *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
     *  Everyone is permitted to copy and distribute verbatim copies
     *  of this license document, but changing it is not allowed.
     *
     *  You should have received a copy of the GNU Affero General Public
     *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
     *
     */

    namespace shopclass\includes\sphinx;
    /**
     * Class SphinxQLQuery
     */
    class SphinxQLQuery {

        const QUERY_SELECT = 1;
        const QUERY_UPDATE = 2;
        const QUERY_INSERT = 3;
        const QUERY_DELETE = 4;
        const QUERY_SHOW = 5;
        const QUERY_SET = 6;
        const QUERY_CALL_KEYWORDS = 7;
        const QUERY_FROM_STRING = 8;
        const QUERY_CALL_SNIPPETS = 9;

        protected $_types
            = array (
                self::QUERY_UPDATE ,
                self::QUERY_DELETE ,
                self::QUERY_INSERT ,
                self::QUERY_SELECT ,
                self::QUERY_SET ,
                self::QUERY_SHOW ,
                self::QUERY_CALL_KEYWORDS ,
                self::QUERY_FROM_STRING ,
                self::QUERY_CALL_SNIPPETS
            );
        /**
         * @var array The indexes that are to be searched
         */
        protected $_indexes = array ();
        /**
         * @var array The fields that are to be returned in the result set
         */
        protected $_fields = array ();
        /**
         * @var string A string to be searched for in the indexes
         */
        protected $_search = null;
        /**
         * @var array A set of WHERE conditions
         */
        protected $_wheres = array ();
        /**
         * @var array The GROUP BY field
         */
        protected $_group = null;
        /**
         * @var array The IN GROUP ORDER BY options
         */
        protected $_groupOrder = null;
        /**
         * @var array A set of ORDER clauses
         */
        protected $_orders = array ();
        /**
         * @var integer The offset to start returning results from
         */
        protected $_offset = 0;
        /**
         * @var integer The maximum number of results to return
         */
        protected $_limit = 20;
        /**
         * @var array A set of OPTION clauses
         */
        protected $_options = array ();

        /**
         * @var int Type of query
         */
        protected $_typeQuery = self::QUERY_SELECT;

        /**
         * @var string Index
         */
        protected $_index = null;
        /**
         * @var int
         */
        protected $_hits = null;
        /**
         * @var string
         */
        protected $_typeShow = null;
        /**
         * @var int
         */
        protected $_id = null;

        protected $_query = null;

        protected $_text = null;

        protected $before_match = '<mark>';

        protected $after_match = '</mark>';


        /**
         * @static
         *
         * @param string $queryString
         *
         * @return SphinxQLQuery
         *
         */
        public static function fromString( $queryString ) {

            $query = new self();
            $query->setQuery( $queryString );
            $query->setType( self::QUERY_FROM_STRING );

            return $query;
        }

        /**
         * @param $query
         */
        public function setQuery( $query ) {

            $this->_query = $query;
        }

        /**
         * @param $type
         *
         * @return $this
         */
        public function setType( $type ) {

            $this->_typeQuery = $type;

            return $this;
        }

        /**
         * Magic method, returns the result of build().
         *
         * @return string
         */
        public function __toString() {
            return $this->toString();
        }

        /**
         * @return null|string
         */
        public function toString() {
            switch ( $this->_typeQuery ) {
                case self::QUERY_CALL_KEYWORDS:
                    return $this->_buildCallKeywords();
                case self::QUERY_CALL_SNIPPETS:
                    return $this->_buildCallSnippets();
                case self::QUERY_SHOW:
                    return $this->_buildShow();
                case self::QUERY_SET:
                    return $this->_buildSet();
                case self::QUERY_DELETE:
                    return $this->_buildDelete();
                case self::QUERY_UPDATE:
                    return $this->_buildUpdate();
                case self::QUERY_FROM_STRING:
                    return $this->_query;
                case self::QUERY_INSERT:
                    return $this->_buildInsert();
                default:
                    return $this->_buildSelect();
            }
        }

        /**
         * @return string
         */
        protected function _buildCallKeywords() {
            $index = substr( $this->_buildIndexes() , 0 , - 1 );
            if ( $this->_hits ) {
                return "CALL KEYWORDS(" . $this->_search . ", " . $index . ", " . $this->_hits . ");";
            }

            return "CALL KEYWORDS(" . $this->_search . ", " . $index . ");";

        }

        /**
         * @return string
         */
        protected function _buildIndexes() {
            return sprintf( '%s ' , implode( ',' , $this->_indexes ) );
        }

        /**
         * @param $search
         * @param $text
         * @param $index
         * @param string $before_match
         * @param string $after_match
         *
         * @return string
         * @internal param $index
         */
        public function _buildCallSnippets( $search , $text , $index , $before_match = '<mark>' , $after_match = '</mark>' ) {
            //$index = substr( $this->_buildIndexes() , 0 , - 1 );
            return "CALL SNIPPETS('" . $text . "', '" . $index . "' ,'" . $search . "', 150 AS around, 250 AS limit_words, '" . $before_match . "' AS before_match, '" . $after_match . "' AS after_match)";

        }

        /**
         * @return string
         */
        protected function _buildShow() {
            return "SHOW " . $this->_typeShow . "";

        }

        /**
         * @return string
         */
        protected function _buildSet() {
            $option = $this->_options[ 0 ];

            return "SET " . $option[ 'name' ] . ' = ' . $option[ 'value' ] . "";
        }

        /**
         * @return string
         */
        protected function _buildDelete() {
            return "DELETE FROM " . $this->_buildIndexes() . "WHERE id = " . $this->_id;
        }

        /**
         * @return string
         */
        protected function _buildUpdate() {
            $wheres = array ();
            $fields = array ();


            $query = 'UPDATE ';
            $query .= $this->_buildIndexes();

            foreach ( $this->_fields as $field => $value ) {
                $fields[] = sprintf( "%s=%s" , $field , $value );
            }
            $query .= sprintf( ' %s ' , implode( ',' , $fields ) );

            if ( is_string( $this->_search ) ) {
                $wheres[] = sprintf( "MATCH('%s')" , $this->_search );
            }

            foreach ( $this->_wheres as $where ) {
                $wheres[] = sprintf( "%s %s %s" , $where[ 'field' ] , $where[ 'operator' ] , $where[ 'value' ] );
            }

            if ( count( $wheres ) > 0 ) {
                $query .= sprintf( 'WHERE %s ' , implode( ' AND ' , $wheres ) );
            }

            return $query;
        }

        /**
         * @return string
         */
        protected function _buildInsert() {

            $fields = '';

            $query = 'INSERT INTO ';
            $query .= $this->_buildIndexes();

            foreach ( $this->_fields as $field => $value ) {
                $fields[] = $field;
                $values[] = "'" . $value . "'";
            }

            $query .= ' (' . sprintf( ' %s ' , implode( ',' , $fields ) ) . ") ";
            if ( isset( $values ) ) {
                $query .= ' VALUES (' . sprintf( ' %s ' , implode( ',' , $values ) ) . ")";
            }

            return $query;
        }

        /**
         * Builds the query string from the information you've given.
         *
         * @return string The resulting query
         */
        protected function _buildSelect() {
            $fields  = array ();
            $wheres  = array ();
            $orders  = array ();
            $options = array ();
            $query   = '';

            foreach ( $this->_fields as $field ) {
                if ( ! isset( $field[ 'field' ] ) or ! is_string( $field[ 'field' ] ) ) {
                    continue;
                }
                if ( isset( $field[ 'alias' ] ) and is_string( $field[ 'alias' ] ) ) {
                    $fields[] = sprintf( "%s AS %s" , $field[ 'field' ] , $field[ 'alias' ] );
                } else {
                    $fields[] = sprintf( "%s" , $field[ 'field' ] );
                }
            }

            unset( $field );

            if ( is_string( $this->_search ) ) {
                $wheres[] = sprintf( "MATCH('%s')" , $this->_search );
            }

            foreach ( $this->_wheres as $where ) {
                $wheres[] = sprintf( "%s %s %s" , $where[ 'field' ] , $where[ 'operator' ] , $where[ 'value' ] );
            }

            unset( $where );

            foreach ( $this->_orders as $order ) {
                $orders[] = sprintf( "%s %s" , $order[ 'field' ] , $order[ 'sort' ] );
            }

            unset( $order );

            foreach ( $this->_options as $option ) {
                $options[] = sprintf( "%s=%s" , $option[ 'name' ] , $option[ 'value' ] );
            }

            unset( $option );

            $query .= sprintf( 'SELECT %s ' , count( $fields ) ? implode( ', ' , $fields ) : '*' );

            $query .= 'FROM ' . $this->_buildIndexes();

            if ( count( $wheres ) > 0 ) {
                $query .= sprintf( 'WHERE %s ' , implode( ' AND ' , $wheres ) );
            }

            if ( is_string( $this->_group ) ) {
                $query .= sprintf( 'GROUP BY %s ' , $this->_group );
            }

            if ( is_array( $this->_groupOrder ) ) {
                $query .= sprintf( 'WITHIN GROUP ORDER BY %s %s ' , $this->_groupOrder[ 'field' ] , $this->_groupOrder[ 'sort' ] );
            }

            if ( count( $orders ) > 0 ) {
                $query .= sprintf( 'ORDER BY %s ' , implode( ', ' , $orders ) );
            }

            $query .= sprintf( 'LIMIT %d, %d ' , $this->_offset , $this->_limit );

            if ( count( $options ) > 0 ) {
                $query .= sprintf( 'OPTION %s ' , implode( ', ' , $options ) );
            }

            while ( substr( $query , - 1 , 1 ) == ' ' ) {
                $query = substr( $query , 0 , - 1 );
            }

            return $query;
        }

        /**
         * @param $type
         *
         * @return $this
         */
        public function setTypeShow( $type ) {
            $type = strtoupper( $type );

            $this->_typeQuery = self::QUERY_SHOW;
            $this->_typeShow  = $type;

            return $this;
        }

        /**
         * @return $this
         */
        public function setTypeCallSnippet() {
            $this->_typeQuery = self::QUERY_CALL_SNIPPETS;

            return $this;
        }

        /**
         * @param $id
         */
        public function setId( $id ) {
            $id        = $this->_escapeQuery( $id );
            $this->_id = $id;
        }

        /**
         * @param $string
         *
         * @return mixed
         */
        protected function _escapeQuery( $string ) {
            $qoute_count = substr_count( $string , '"' );
            if ( $qoute_count % 2 ) {
                $string = str_replace( '"' , '' , $string );
            }
            $from = array (
                '\\' ,
                '(' ,
                ')' ,
                '!' ,
                '~' ,
                '&' ,
                '/' ,
                '^' ,
                '$' ,
                '=' ,
                "'" ,
                "\x00" ,
                "\n" ,
                "\r" ,
                "\x1a"
            );
            $to   = array (
                '\\\\' ,
                '\\\(' ,
                '\\\)' ,
                '\\\!' ,
                '\\\@' ,
                '\\\~' ,
                '\\\&' ,
                '\\\/' ,
                '\\\^' ,
                '\\\$' ,
                '\\\=' ,
                "\\'" ,
                "\\x00" ,
                "\\n" ,
                "\\r" ,
                "\\x1a"
            );

            return str_replace( $from , $to , $string );
        }

        /**
         * Adds an entry to the list of indexes to be searched.
         *
         * @param string $index The index to add
         *
         * @return SphinxQLQuery $this
         */
        public function addIndex( $index ) {

            $index = $this->_escapeQuery( $index );

            $this->_indexes[] = $index;

            return $this;
        }

        /**
         * Removes an entry from the list of indexes to be searched.
         *
         * @param string $index The index to remove
         *
         * @return SphinxQLQuery $this
         */
        public function removeIndex( $index ) {

            $pos = array_search( $index , $this->_indexes );
            unset( $this->_indexes[ $pos ] );

            return $this;
        }

        /**
         * @param $field
         * @param $value
         *
         * @return $this
         */
        public function addUpdateField( $field , $value ) {
            $field = $this->_escapeQuery( $field );
            $value = $this->_escapeQuery( $value );

            $this->_fields[ $field ] = $value;

            return $this;
        }

        /**
         * @param $fields
         *
         * @return $this
         */
        public function addInsertFields( $fields ) {

            foreach ( $fields as $field => $value ) {

                $this->_fields[ $field ] = $value;
            }

            return $this;
        }

        /**
         * Adds multiple entries at once to the list of fields to return.
         * Takes an array structured as so:
         * array(array('field' => 'user_id', 'alias' => 'user')), ...)
         * The alias is optional.
         *
         * @param array $fields Array of fields to add
         *
         * @return SphinxQLQuery $this
         */
        public function addFields( $fields ) {

            foreach ( $fields as $entry ) {

                if ( ! isset( $entry[ 'alias' ] ) or ! is_string( $entry[ 'alias' ] ) ) {
                    $entry[ 'alias' ] = null;
                }

                $this->addField( $entry[ 'field' ] , $entry[ 'alias' ] );
            }

            return $this;
        }

        /**
         * Adds a entry to the list of fields to return from the query.
         *
         * @param string $field Field to add
         * @param string| null $alias Alias for that field, optional
         *
         * @return SphinxQLQuery $this
         */
        public function addField( $field , $alias = null ) {

            if ( ! is_string( $alias ) ) {
                $alias = null;
            }


            $this->_fields[] = array ( 'field' => $field , 'alias' => $alias );

            return $this;
        }

        /**
         * Removes multiple fields at once from the list of fields to search.
         *
         * @param array $array List of aliases of fields to remove
         *
         * @return SphinxQLQuery $this
         */
        public function removeFields( $array ) {

            foreach ( $array as $alias ) {
                $this->removeField( $alias );
            }

            return $this;
        }

        /**
         * Removes a field from the list of fields to search.
         *
         * @param string $alias Alias of the field to remove
         *
         * @return SphinxQLQuery $this
         */
        public function removeField( $alias ) {

            foreach ( $this->_fields as $key => $value ) {
                if ( in_array( $alias , $value ) ) {
                    unset( $this->_fields[ $key ] );
                }
            }

            return $this;
        }

        /**
         * Sets the text to be matched against the index(es)
         *
         * @param string $search Text to be searched
         *
         * @return SphinxQLQuery $this
         */
        public function setSearch( $search ) {

            $this->_search = $search;

            return $this;
        }

        /**
         * Removes the search text from the query.
         *
         * @return SphinxQLQuery $this
         */
        public function removeSearch() {
            $this->_search = null;

            return $this;
        }

        /**
         * Sets the offset for the query
         *
         * @param int $offset Offset
         *
         *
         * @return SphinxQLQuery $this
         */
        public function setOffset( $offset ) {

            $this->_offset = $offset;

            return $this;
        }

        /**
         * Sets the limit for the query
         *
         * @param int $limit Limit
         *
         * @return SphinxQLQuery $this
         */
        public function setLimit( $limit ) {

            $this->_limit = $limit;

            return $this;
        }

        /**
         * @param $hits
         *
         * @return $this
         */
        public function setHits( $hits ) {

            $this->_hits = $hits;

            return $this;
        }

        /**
         * Adds a WHERE condition to the query.
         * '=', '!=', '>', '<', '>=', '<=', 'AND', 'NOT IN', 'IN', 'BETWEEN'
         *
         * @param string $field The field/expression for the condition
         * @param mixed $value The field/expression/value to compare the field to
         * @param string $operator The operator (=, <, >, etc)
         *
         * @return SphinxQLQuery $this
         */
        public function addWhere( $field , $value , $operator = null ) {

            if ( ! $operator ) {
                $operator = "=";
            }

            if ( is_array( $value ) ) {
                foreach ( $value as $key => $vl ) {
                    $value[ $key ] = $this->_escapeQuery( $value[ $key ] );
                }
            } else {
                $value = $this->_escapeQuery( $value );
            }


            if ( in_array( $operator , array ( 'NOT IN' , 'IN' ) ) ) {

                $value = "(" . implode( "," , $value ) . ")";
            }

            if ( $operator == 'BETWEEN' ) {

                $value = implode( " AND " , $value );
            }

            if ( ! is_string( $value ) ) {
                $value = (string) $value;
            }

            $field = $this->_escapeQuery( $field );

            $this->_wheres[ md5( $field . $operator ) ] = array (
                'field'    => $field ,
                'operator' => $operator ,
                'value'    => $value
            );

            return $this;
        }

        /**
         * Removes a WHERE condition from the list of conditions
         *
         * @param string $field condition to remove
         * @param string $operator the operator
         *
         * @return SphinxQLQuery $this
         */
        public function removeWhere( $field , $operator = '=' ) {
            unset( $this->_wheres[ md5( $field . $operator ) ] );

            return $this;
        }

        /**
         * Sets the GROUP BY condition for the query.
         *
         * @param string $field The field/expression for the condition
         *
         * @return SphinxQLQuery $this$this
         */
        public function addGroupBy( $field ) {

            $field        = $this->_escapeQuery( $field );
            $this->_group = $field;

            return $this;
        }

        /**
         * Removes the GROUP BY condition from the query.
         *
         * @return SphinxQLQuery $this
         */
        public function removeGroupBy() {
            $this->_group = null;

            return $this;
        }

        /**
         * Sets the WITHIN GROUP ORDER BY condition for the query. This is a
         * Sphinx-specific extension to SQL.
         *
         * @param string $field The field/expression for the condition
         * @param string $sort The sort type (can be 'asc' or 'desc', capitals are also OK)
         *
         * @return SphinxQLQuery $this
         */
        public function groupOrder( $field , $sort ) {

            $field = $this->_escapeQuery( $field );
            $sort  = $this->_escapeQuery( $sort );

            $this->_groupOrder = array ( 'field' => $field , 'sort' => $sort );

            return $this;
        }

        /**
         * Removes the WITHIN GROUP ORDER BY condition for the query. This is a
         * Sphinx-specific extension to SQL.
         *
         * @return SphinxQLQuery $this
         */
        public function removeGroupOrder() {
            $this->_groupOrder = null;

            return $this;
        }

        /**
         * Adds an OPTION to the query. This is a Sphinx-specific extension to SQL.
         *
         * @param string $name The option name
         * @param string $value The option value
         *
         * @return SphinxQLQuery $this
         */
        public function addOption( $name , $value ) {

            $this->_options[] = array ( 'name' => $name , 'value' => $value );

            return $this;
        }

        /**
         * Removes an OPTION from the query.
         *
         * @param string $name The option name, optional
         * @param string $value The option value, optional
         *
         * @return SphinxQLQuery $this
         */
        public function removeOption( $name = null , $value = null ) {
            if ( ! $name ) {
                $this->_options = array ();

                return $this;
            }

            foreach ( $this->_options as $key => $option ) {
                if ( $option[ 'name' ] == $name and ( ! $value or $value == $option[ 'value' ] ) ) {
                    unset( $this->_options[ $key ] );
                }
            }

            return $this;
        }

        /**
         * Adds an ORDER condition to the query.
         *
         * @param string $field The field/expression for the condition
         * @param string $sort The sort type (can be 'asc' or 'desc', capitals are also OK)
         *
         * @param bool $esc
         *
         * @return SphinxQLQuery $this
         */
        public function addOrderBy( $field , $sort = "asc" , $esc = true ) {

            if ( strtolower( $sort ) == 'asc' ) {
                $sort = 'asc';
            } else if ( strtolower( $sort ) == 'none' ) {
                $sort = '';
            } else {
                $sort = 'desc';
            }
            if ( $esc ) {
                $field = $this->_escapeQuery( $field );
            }

            $this->_orders[] = array ( 'field' => $field , 'sort' => $sort );

            return $this;
        }

        /**
         * Removes an ORDER from the query.
         *
         * @param string $field The option name
         *
         * @return SphinxQLQuery $this
         */
        public function removeOrderBy( $field = null ) {
            if ( ! $field ) {
                $this->_orders = array ();

                return $this;
            }

            foreach ( $this->_orders as $key => $orders ) {
                if ( $orders[ 'field' ] == $field ) {
                    unset( $this->_orders[ $key ] );
                }
            }

            return $this;
        }

        /**
         * @param null $text
         *
         * @return $this
         */
        public function setText( $text ) {
            $this->_text = $text;

            return $this;
        }

        /**
         * @param string $before_match
         */
        public function setBeforeMatch( $before_match ) {
            $this->before_match = $before_match;
        }

        /**
         * @param string $after_match
         */
        public function setAfterMatch( $after_match ) {
            $this->after_match = $after_match;
        }
    }
