<?php
namespace Spot;

/**
 * Query Object - Used to build adapter-independent queries PHP-style
 *
 * @package Spot
 * @author Vance Lucas <vance@vancelucas.com>
 */
class Query implements \Countable, \IteratorAggregate
{
    protected $_mapper;
    protected $_entityName;
    protected $_tableName;
    protected $_queryBuilder;
    protected $_noQuote;

    // Storage for query properties
    public $with = [];
    protected $_data = [];

    // Custom methods added by extensions or plugins
    protected static $_customMethods = [];

    /**
     *  Constructor Method
     *
     *  @param Spot_Mapper
     *  @param string $entityName Name of the entity to query on/for
     */
    public function __construct(\Spot\Mapper $mapper)
    {
        $this->_mapper = $mapper;
        $this->_entityName = $mapper->entity();
        $this->_tableName = $mapper->table();

        // Create Doctrine DBAL query builder from Doctrine\DBAL\Connection
        $this->_queryBuilder = $mapper->connection()->createQueryBuilder();
    }

    /**
     * Get current Doctrine DBAL query builder object
     *
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    public function builder()
    {
        return $this->_queryBuilder;
    }

    /**
     * Set field and value quoting on/off - maily used for testing output SQL since quoting is different per platform
     */
    public function noQuote($noQuote = true)
    {
        $this->_noQuote = $noQuote;
        return $this;
    }

    /**
     * Add a custom user method via closure or PHP callback
     *
     * @param string $method Method name to add
     * @param callable $callback Callback or closure that will be executed when missing method call matching $method is made
     * @throws InvalidArgumentException
     */
    public static function addMethod($method, callable $callback)
    {
        if(method_exists(__CLASS__, $method)) {
            throw new \InvalidArgumentException("Method '" . $method . "' already exists on " . __CLASS__);
        }
        self::$_customMethods[$method] = $callback;
    }

    /**
     * Run user-added callback
     *
     * @param string $method Method name called
     * @param array $args Array of arguments used in missing method call
     * @throws BadMethodCallException
     */
    public function __call($method, $args)
    {
        if(isset(self::$_customMethods[$method]) && is_callable(self::$_customMethods[$method])) {
            $callback = self::$_customMethods[$method];
            // Pass the current query object as the first parameter
            array_unshift($args, $this);
            return call_user_func_array($callback, $args);
        } else if (method_exists('\\Spot\\Entity\\Collection', $method)) {
            return $this->execute()->$method($args[0]);
        } else {
            throw new \BadMethodCallException("Method '" . __CLASS__ . "::" . $method . "' not found");
        }
    }

    /**
     * Get current adapter object
     */
    public function mapper()
    {
        return $this->_mapper;
    }

    /**
     * Get current entity name query is to be performed on
     */
    public function entityName()
    {
        return $this->_entityName;
    }

    /**
     * Select (passthrough to DBAL QueryBuilder)
     */
    public function select()
    {
        call_user_func_array([$this->builder(), 'select'], func_get_args());
        return $this;
    }

    /**
     * Delete (passthrough to DBAL QueryBuilder)
     */
    public function delete()
    {
        call_user_func_array([$this->builder(), 'delete'], func_get_args());
        return $this;
    }

    /**
     * From (passthrough to DBAL QueryBuilder)
     */
    public function from()
    {
        call_user_func_array([$this->builder(), 'from'], func_get_args());
        return $this;
    }

    /**
     * WHERE conditions
     *
     * @param array $conditions Array of conditions for this clause
     * @param string $type Keyword that will separate each condition - "AND", "OR"
     */
    public function where(array $where, $type = 'AND')
    {
        $whereClause = implode(' ' . $type . ' ', $this->parseWhereToSQLFragments($where));
        $this->builder()->andWhere($whereClause);
        return $this;
    }

    /**
     * WHERE OR conditions
     *
     * @param array $conditions Array of conditions for this clause
     * @param string $type Keyword that will separate each condition - "AND", "OR"
     */
    public function orWhere(array $where, $type = 'AND')
    {
        $whereClause = implode(' ' . $type . ' ', $this->parseWhereToSQLFragments($where));
        $this->builder()->orWhere($whereClause);
        return $this;
    }

    /**
     * WHERE AND conditions
     *
     * @param array $conditions Array of conditions for this clause
     * @param string $type Keyword that will separate each condition - "AND", "OR"
     */
    public function andWhere(array $where, $type = 'AND')
    {
        return $this->where($where, $type);
    }

    /**
     * Parse array-syntax WHERE conditions and translate them to DBAL QueryBuilder syntax
     *
     * @param array $where Array of conditions for this clause
     * @return array SQL fragment strings for WHERE clause
     */
    private function parseWhereToSQLFragments(array $where, $useAlias = true)
    {
        $builder = $this->builder();

        $sqlFragments = [];
        foreach($where as $column => $value) {
            $whereClause = "";
            // Column name with comparison operator
            $colData = explode(' ', $column);
            $operator = isset($colData[1]) ? $colData[1] : '=';
            if(count($colData) > 2) {
                $operator = array_pop($colData);
                $colData = [implode(' ', $colData), $operator];
            }
            $col = $colData[0];

            // Prefix column name with alias
            if ($useAlias === true) {
                $col = $this->_tableName . '.' . $col;
            }

            // Determine which operator to use based on custom and standard syntax
            switch(strtolower($operator)) {
                case '<':
                case ':lt':
                    $operator = '<';
                break;
                case '<=':
                case ':lte':
                    $operator = '<=';
                break;
                case '>':
                case ':gt':
                    $operator = '>';
                break;
                case '>=':
                case ':gte':
                    $operator = '>=';
                break;
                // REGEX matching
                case '~=':
                case '=~':
                case ':regex':
                    $operator = "REGEX";
                break;
                // LIKE
                case ':like':
                    $operator = "LIKE";
                break;
                // FULLTEXT search
                // MATCH(col) AGAINST(search)
                case ':fulltext':
                    $whereClause = "MATCH(" . $this->escapeField($col) . ") AGAINST(" . $builder->createPositionalParameter($value) . ")";
                break;
                case ':fulltext_boolean':
                    $whereClause = "MATCH(" . $this->escapeField($col) . ") AGAINST(" . $builder->createPositionalParameter($value) . " IN BOOLEAN MODE)";
                break;
                // In
                case 'in':
                case ':in':
                    $operator = 'IN';
                    if(!is_array($value)) {
                        throw new Exception("Use of IN operator expects value to be array. Got " . gettype($value) . ".");
                    }
                break;
                // Not equal
                case '<>':
                case '!=':
                case ':ne':
                case ':not':
                    $operator = '!=';
                    if(is_array($value)) {
                        $operator = "NOT IN";
                    } elseif(is_null($value)) {
                        $operator = "IS NOT NULL";
                    }
                break;
                // Equals
                case '=':
                case ':eq':
                    $operator = '=';
                    if(is_array($value)) {
                        $operator = "IN";
                    } elseif(is_null($value)) {
                        $operator = "IS NULL";
                    }
                break;
                // Unsupported operator
                default:
                    throw new Exception("Unsupported operator '" . $operator . "' in WHERE clause");
                break;
            }

            // If WHERE clause not already set by the code above...
            if(empty($whereClause)) {
                if(is_array($value)) {
                    if(empty($value)) {
                        $whereClause = $this->escapeField($col) . " IS NULL";
                    } else {
                        $valueIn = "";
                        foreach($value as $val) {
                            $valueIn .= $builder->createPositionalParameter($val) . ",";
                        }
                        $value = "(" . trim($valueIn, ',') . ")";
                        $whereClause = $this->escapeField($col) . " " . $operator . " " . $value;
                    }
                } elseif(is_null($value)) {
                    $whereClause = $this->escapeField($col) . " " . $operator;
                }
            }

            if(empty($whereClause)) {
                // Add to binds array and add to WHERE clause
                $whereClause = $this->escapeField($col) . " " . $operator . " " . $builder->createPositionalParameter($value) . "";
            }

            $sqlFragments[] = $whereClause;
        }

        return $sqlFragments;
    }

    /**
     * Relations to be loaded non-lazily
     *
     * @param mixed $relations Array/string of relation(s) to be loaded.  False to erase all withs.  Null to return existing $with value
     */
    public function with($relations = null)
    {
        if (is_null($relations)) {
            return $this->with;
        } else if (is_bool($relations) && !$relations) {
            $this->with = [];
        }

        $entityName = $this->entityName();
        $entityRelations = array_keys($entityName::relations());
        foreach((array)$relations as $idx => $relation) {
            $add = true;
            if (!is_numeric($idx) && isset($this->with[$idx])) {
                $add = $relation;
                $relation = $idx;
            }
            if ($add && in_array($relation, $entityRelations)) {
                $this->with[] = $relation;
            } else if (!$add) {
                foreach (array_keys($this->with, $relation, true) as $key) {
                    unset($this->with[$key]);
                }
            }
        }
        $this->with = array_unique($this->with);
        return $this;
    }

    /**
     * Search criteria (FULLTEXT, LIKE, or REGEX, depending on storage engine and driver)
     *
     * @param mixed $fields Single string field or array of field names to use for searching
     * @param string $query Search keywords or query
     * @param array $options Array of options for search
     * @return $this
     */
    public function search($fields, $query, array $options = [])
    {
        $fields = (array) $fields;
        $entityDatasourceOptions = $this->mapper()->entityManager()->datasourceOptions($this->entityName());
        $fieldString = '`' . implode('`, `', $fields) . '`';
        $fieldTypes = $this->mapper()->fields($this->entityName());

        // See if we can use FULLTEXT search
        $whereType = ':like';
        $connection = $this->mapper()->connection($this->entityName());
        // Only on MySQL
        if($connection instanceof \Spot\Adapter\Mysql) {
            // Only for MyISAM engine
            if(isset($entityDatasourceOptions['engine'])) {
                $engine = $entityDatasourceOptions['engine'];
                if('myisam' == strtolower($engine)) {
                    $whereType = ':fulltext';
                    // Only if ALL included columns allow fulltext according to entity definition
                    if(in_array($fields, array_keys($this->mapper()->fields($this->entityName())))) {
                        // FULLTEXT
                        $whereType = ':fulltext';
                    }

                    // Boolean mode option
                    if(isset($options['boolean']) && $options['boolean'] === true) {
                        $whereType = ':fulltext_boolean';
                    }
                }
            }
        }

        // @todo Normal queries can't search mutliple fields, so make them separate searches instead of stringing them together

        // Resolve search criteria
        return $this->where([$fieldString . ' ' . $whereType => $query]);
    }

    /**
     * ORDER BY columns
     *
     * @param array $fields Array of field names to use for sorting
     * @return $this
     */
    public function order($fields = [])
    {
        $orderBy = [];
        $defaultSort = "ASC";
        if (is_array($fields)) {
            foreach($fields as $field => $sort) {
                // Numeric index - field as array entry, not key/value pair
                if(is_numeric($field)) {
                    $field = $sort;
                    $sort = $defaultSort;
                }

                $this->order[$field] = strtoupper($sort);
            }
        } else {
            $this->order[$fields] = $defaultSort;
        }
        return $this;
    }

    /**
     * GROUP BY clause
     *
     * @param array $fields Array of field names to use for grouping
     * @return $this
     */
    public function group(array $fields = [])
    {
        foreach($fields as $field) {
            $this->group[] = $field;
        }
        return $this;
    }

    /**
     * Having clause to filter results by a calculated value
     *
     * @param array $having Array (like where) for HAVING statement for filter records by
     * @param string $type
     */
    public function having(array $having, $type ='AND')
    {
        $this->builder()->having(implode(' ' . $type . ' ', $this->parseWhereToSQLFragments($having, false)));
        return $this;
    }

    /**
     * Limit executed query to specified amount of records
     * Implemented at adapter-level for databases that support it
     *
     * @param int $limit Number of records to return
     * @param int $offset Record to start at for limited result set
     */
    public function limit($limit, $offset = null)
    {
        $this->builder()->setMaxResults($limit);
        if($offset !== null) {
            $this->offset($offset);
        }
        return $this;
    }

    /**
     * Offset executed query to skip specified amount of records
     * Implemented at adapter-level for databases that support it
     *
     * @param int $offset Record to start at for limited result set
     */
    public function offset($offset)
    {
        $this->builder()->setFirstResult($offset);
        return $this;
    }

    // ===================================================================

    /**
     * SPL Countable function
     * Called automatically when attribute is used in a 'count()' function call
     *
     * @return int
     */
    public function count()
    {
        $stmt = $this->builder()->select('COUNT(*)')->execute();
        return $stmt->fetchColumn(0);
    }

    /**
     * SPL IteratorAggregate function
     * Called automatically when attribute is used in a 'foreach' loop
     *
     * @return \Spot\Entity\Collection
     */
    public function getIterator()
    {
        // Execute query and return result set for iteration
        $result = $this->execute();
        return ($result !== false) ? $result : [];
    }

    /**
     * Convenience function passthrough for Collection
     *
     * @return array
     */
    public function toArray($keyColumn = null, $valueColumn = null)
    {
        $result = $this->execute();
        return ($result !== false) ? $result->toArray($keyColumn, $valueColumn) : [];
    }

    /**
     * Return the first entity matched by the query
     *
     * @return mixed Spot_Entity on success, boolean false on failure
     */
    public function first()
    {
        $result = $this->limit(1)->execute();
        return ($result !== false) ? $result->first() : false;
    }

    /**
     * Execute and return query as a collection
     *
     * @return mixed Collection object on success, boolean false on failure
     */
    public function execute()
    {
        // @TODO Add caching to execute based on resulting SQL+data so we don't execute same query w/same data multiple times
        return $this->mapper()->resolver()->read($this);
    }

    /**
     * Get raw SQL string from built query
     *
     * @param string $string
     */
    public function toSql()
    {
        return $this->builder()->getSQL();
    }

    /**
     * Escape/quote direct user input
     *
     * @param string $string
     */
    public function escape($string)
    {
        if($this->_noQuote) {
            return $string;
        }
        return $this->mapper()->connection()->quote($string);
    }

    /**
     * Escape/quote direct user input
     *
     * @param string $string
     */
    public function escapeField($field)
    {
        if($this->_noQuote) {
            return $field;
        }
        return $this->mapper()->connection()->quoteIdentifier($field);
    }
}
