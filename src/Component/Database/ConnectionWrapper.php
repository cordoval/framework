<?php

namespace Pagekit\Component\Database;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection as BaseConnection;
use Doctrine\DBAL\Driver;

class ConnectionWrapper extends BaseConnection
{
    const SINGLE_QUOTED_TEXT = '\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'';
    const DOUBLE_QUOTED_TEXT = '"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"';

    /**
     * The table prefix.
     *
     * @var string
     */
    protected $prefix;

    /**
     * The table prefix placeholder.
     *
     * @var string
     */
    protected $placeholder = '@';

    /**
     * The regex for parsing SQL query parts.
     *
     * @var array
     */
    protected $regex = [];

    /**
     * Initializes a new instance of the Connection class.
     *
     * @param array         $params
     * @param Driver        $driver
     * @param Configuration $config
     * @param EventManager  $eventManager
     */
    public function __construct(array $params, Driver $driver, Configuration $config = null, EventManager $eventManager = null)
    {
        parent::__construct($params, $driver, $config, $eventManager);

        if (isset($params['prefix'])) {
            $this->prefix = $params['prefix'];
        }

        $this->regex['quotes'] = "/([^'\"]+)(?:".self::DOUBLE_QUOTED_TEXT."|".self::SINGLE_QUOTED_TEXT.")?/As";
        $this->regex['placeholder'] = "/".preg_quote($this->placeholder)."([a-zA-Z_][a-zA-Z0-9_]*)/";
    }

    /**
     * Gets the table prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Replaces the table prefix placeholder with actual one.
     *
     * @param  string $query
     * @return string
     */
    public function replacePrefix($query)
    {
        foreach ($this->getUnquotedQueryParts($query) as $part) {

            if (strpos($part[0], $this->placeholder) === false) {
                continue;
            }

            $replace = preg_replace($this->regex['placeholder'], $this->prefix.'$1', $part[0], -1, $count);

            if ($count) {
                $query = substr_replace($query, $replace, $part[1], strlen($part[0]));
            }
        }

        return $query;
    }

    /**
     * Prepares and executes an SQL query and returns the first row of the result as an object.
     *
     * @param  string $statement
     * @param  array  $params
     * @param  string $class
     * @param  array  $args
     * @return mixed
     */
    public function fetchObject($statement, array $params = [], $class = 'stdClass', $args = [])
    {
        return $this->executeQuery($statement, $params)->fetchObject($class, $args);
    }

    /**
     * Prepares and executes an SQL query and returns the result as an array of objects.
     *
     * @param  string $statement
     * @param  array  $params
     * @param  string $class
     * @param  array  $args
     * @return array
     */
    public function fetchAllObjects($statement, array $params = [], $class = 'stdClass', $args = [])
    {
        return $this->executeQuery($statement, $params)->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, $class, $args);
    }

    /**
     * @{inheritdoc}
     */
    public function prepare($statement)
    {
        return parent::prepare($this->replacePrefix($statement));
    }

    /**
     * @{inheritdoc}
     */
    public function exec($statement)
    {
        return parent::exec($this->replacePrefix($statement));
    }

    /**
     * @{inheritdoc}
     */
    public function executeQuery($query, array $params = [], $types = [], QueryCacheProfile $qcp = null)
    {
        return parent::executeQuery($this->replacePrefix($query), $params, $types, $qcp);
    }

    /**
     * @{inheritdoc}
     */
    public function executeUpdate($query, array $params = [], array $types = [])
    {
        return parent::executeUpdate($this->replacePrefix($query), $params, $types);
    }

    /**
     * Parses the unquoted SQL query parts.
     *
     * @param  string $query
     * @return array
     */
    protected function getUnquotedQueryParts($query)
    {
        preg_match_all($this->regex['quotes'], $query, $parts, PREG_OFFSET_CAPTURE);

        return $parts[1];
    }
}
