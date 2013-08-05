<?php
namespace Spot\Adapter;

/**
 * Sqlite Database Adapter
 *
 * @package Spot
 * @link http://spot.os.ly
 */
class Sqlite extends PDO_Abstract implements AdapterInterface
{
    // Format for date columns, formatted for PHP's date() function
    protected $_format_date = "Y-m-d";
    protected $_format_time = " H:i:s";
    protected $_format_datetime = "Y-m-d H:i:s";

    // Driver-Specific settings
    protected $_engine = ''; // not supported
    protected $_charset = ''; // only UTF_8 supported
    protected $_collate = ''; // only UTF_8 supported

    // Map datamapper field types to actual database adapter types
    // @todo Have to improve this to allow custom types, callbacks, and validation
    protected $_fieldTypeMap = array(
        'string' => array(
            'adapter_type' => 'varchar',
            'length' => 255
            ),
        'email' => array(
            'adapter_type' => 'varchar',
            'length' => 255
            ),
        'url' => array(
            'adapter_type' => 'varchar',
            'length' => 255
            ),
        'tel' => array(
            'adapter_type' => 'varchar',
            'length' => 255
            ),
        'password' => array(
            'adapter_type' => 'varchar',
            'length' => 255
            ),
        'text' => array('adapter_type' => 'text'),
        'int' => array('adapter_type' => 'int'),
        'integer' => array('adapter_type' => 'int'),
        'bool' => array('adapter_type' => 'tinyint', 'length' => 1),
        'boolean' => array('adapter_type' => 'tinyint', 'length' => 1),
        'float' => array('adapter_type' => 'float'),
        'double' => array('adapter_type' => 'double'),
        'decimal' => array('adapter_type' => 'decimal'),
        'date' => array('adapter_type' => 'date'),
        'datetime' => array('adapter_type' => 'datetime'),
        'year' => array('adapter_type' => 'year', 'length' => 4),
        'month' => array('adapter_type' => 'month', 'length' => 2),
        'time' => array('adapter_type' => 'time'),
        'timestamp' => array('adapter_type' => 'int', 'length' => 11),
        'serialized' => array('adapter_type' => 'text'),
    );

    /**
     * Get database connection
     *
     * @return object PDO
     */
    public function connection()
    {
        if (!$this->_connection) {
            if ($this->_dsn instanceof \PDO) {
                $this->_connection = $this->_dsn;
            } else {
                // try to find the database name from the dsn string signified by the filename
                if (preg_match('/(\w+)\.\w+$/i', $this->_dsn, $matches)) {
            
                    $this->_database = $matches[1];
                    // Establish connection
                    try {
                        $this->_connection = new \PDO($this->_dsn);
                        
                        // Throw exceptions by default
                        $this->_connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                    } catch(Exception $e) {
                        throw new \Spot\Exception($e->getMessage());
                    }
                
                } else {
                    throw new \Spot\Exception('Database not found in Sqlite DSN');
                }
            }
        }
        
        return $this->_connection;
    }
    
    /**
     * Escape/quote direct user input
     *
     * @param string $string
     */
    public function escapeField($field)
    {
        return $field == '*' ? $field : '`' . $field . '`';
    }

    /**
     * Get columns for current table
     *
     * @param String $table Table name
     * @param string $source
     * @return Array
     */
    protected function getColumnsForTable($table, $source)
    {
        $tableColumns = array();
        $tblCols = $this->connection()->query("PRAGMA table_info(`$table`)");
        
        if ($tblCols) {
            while ($columnData = $tblCols->fetch(\PDO::FETCH_ASSOC)) {
                $tableColumns[$columnData['name']] = $columnData;
            }
            return $tableColumns;
        }
        
        return false;
    }


    /**
     * Syntax for each column in CREATE TABLE command
     *
     * @param string $fieldName Field name
     * @param array $fieldInfo Array of field settings
     * @return string SQL syntax
     */
    public function migrateSyntaxFieldCreate($fieldName, array $fieldInfo)
    {
        // Ensure field type exists
        if(!isset($this->_fieldTypeMap[$fieldInfo['type']])) {
            throw new \Spot\Exception("Field type '" . $fieldInfo['type'] . "' not supported");
        }
        //Ensure this class will choose adapter type
        unset($fieldInfo['adapter_type']);
        
        $fieldInfo = array_merge($this->_fieldTypeMap[$fieldInfo['type']],$fieldInfo);

        $syntax = $fieldName . ' ' . (($fieldInfo['unsigned']) ? 'unsigned ' : '') . $fieldInfo['adapter_type'];
        
        // Column type and length
        $syntax .= ($fieldInfo['length']) ? '(' . $fieldInfo['length'] . ')' : '';
        
        // Primary
        $syntax .= ($fieldInfo['primary']) ? ' PRIMARY KEY' : '';
        // Nullable
        $isNullable = true;
        if($fieldInfo['required'] || !$fieldInfo['null']) {
            $syntax .= ' NOT NULL';
            $isNullable = false;
        }
        // Default value
        if ($fieldInfo['default'] === null && $isNullable) {
            $syntax .= " DEFAULT NULL";
        } elseif ($fieldInfo['default'] !== null) {
            $default = $fieldInfo['default'];
            // If it's a boolean and $default is boolean then it should be 1 or 0
            if ( is_bool($default) && $fieldInfo['type'] == "boolean" ) {
                $default = $default ? 1 : 0;
            }

            if (is_scalar($default)) {
                $syntax .= " DEFAULT '" . $default . "'";
            }
        }
        
        $syntax .= ($fieldInfo['unique']) ? ' UNIQUE' : '';
        $syntax .= ($fieldInfo['index']) ? ' INDEX' : '';
        
        return $syntax;
    }


    /**
     * Syntax for CREATE TABLE with given fields and column syntax
     *
     * @param string $table Table name
     * @param array $formattedFields Array of fields with all settings
     * @param array $columnsSyntax Array of SQL syntax of columns produced by 'migrateSyntaxFieldCreate' function
     * @param Array $options Options that may affect migrations or how tables are setup
     * @return string SQL syntax
     */
    public function migrateSyntaxTableCreate($table, array $formattedFields, array $columnsSyntax, array $options)
    {
        // Begin syntax soup
        $syntax = "CREATE TABLE IF NOT EXISTS `" . $table . "` (\n";
        // Columns
        $syntax .= implode(",\n", $columnsSyntax);

        $syntax .= "\n);";

        return $syntax;
    }

    /**
     * Syntax for each column in CREATE TABLE command
     *
     * @param string $fieldName Field name
     * @param array $fieldInfo Array of field settings
     * @return string SQL syntax
     */
    public function migrateSyntaxFieldUpdate($fieldName, array $fieldInfo, $add = false)
    {
        if ($add) {
            return "ADD COLUMN " . $this->migrateSyntaxFieldCreate($fieldName, $fieldInfo);
        }
        
        return null; // sqlite doesnt support modifing a column
    }

    /**
     * Syntax for ALTER TABLE with given fields and column syntax
     *
     * @param string $table Table name
     * @param array $formattedFields Array of fields with all settings
     * @param array $columnsSyntax Array of SQL syntax of columns produced by 'migrateSyntaxFieldUpdate' function
     * @return string SQL syntax
     */
    public function migrateSyntaxTableUpdate($table, array $formattedFields, array $columnsSyntax, array $options)
    {
        /*
          Example:
            ALTER TABLE `posts`
            CHANGE `title` `title` VARCHAR( 255 ) NOT NULL ,
            CHANGE `status` `status` VARCHAR( 40 ) NULL DEFAULT 'draft'
        */

        // Begin syntax soup
        $syntax = "ALTER TABLE `" . $table . "` \n";

        // Columns
        $syntax .= implode(",\n", $columnsSyntax);

        return $syntax;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function shouldUpdateMigrateField($formattedField, $columnInfo)
    {
        return ($this->_fieldTypeMap[$formattedField['type']] != strtolower($columnInfo['type']) ||
                        $formattedField['default'] !== $columnInfo['dflt_value']);
    }
}
