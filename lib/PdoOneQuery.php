<?php /** @noinspection UnknownInspectionInspection */
/** @noinspection DuplicatedCode */

/** @noinspection PhpUnused */

namespace eftec;

use Exception;
use PDO;
use PDOStatement;
use RuntimeException;

class PdoOneQuery
{
    /** @var PdoOne */
    public $parent;
    //<editor-fold desc="query builder fields">
    /** @var array parameters for the where. [paramvar,value,type,size] */
    public $whereParamAssoc = [];
    /** @var array parameters for the having. [paramvar,value,type,size] */
    public $havingParamAssoc = [];
    public $whereCounter = 1;
    /**
     * @var null|int $ttl If <b>0</b> then the cache never expires.<br>
     *                         If <b>false</b> then we don't use cache.<br>
     *                         If <b>int</b> then it is the duration of the
     *     cache
     *                         (in seconds)
     */
    protected $useCache = false;
    /** @var string|array [optional] It is the family or group of the cache */
    protected $cacheFamily = '';
    protected $select = '';
    protected $limit = '';
    protected $order = '';
    /** @var bool if true then builderReset will not reset (unless it is force), if false then it will reset */
    protected $noReset = false;
    protected $uid;
    /** @var array */
    protected $where = [];
    /** @var array parameters for the set. [paramvar,value,type,size] */
    protected $setParamAssoc = [];
    /** @var array */
    //private $whereParamValue = [];
    /** @var array */
    protected $set = [];
    protected $from = '';
    protected $group = '';
    protected $recursive = [];
    /** @var array */
    protected $having = [];
    protected $distinct = '';
    //<editor-fold desc="Query Builder DQL functions" defaultstate="collapsed" >

    /**
     * PdoOneQuery constructor.
     * @param PdoOne $parent
     */
    public function __construct(PdoOne $parent)
    {
        $this->parent = $parent;
    }

    /**
     * It returns an array with the metadata of each columns (i.e. name, type,
     * size, etc.) or false if error.
     *
     * @param null|string $sql     If null then it uses the generation of query
     *                             (if any).<br> if string then get the
     *                             statement of the query
     *
     * @param array       $args
     *
     * @return array|bool
     * @throws Exception
     */
    public function toMeta($sql = null, $args = [])
    {
        $uid = false;
        if ($sql === null) {
            $this->parent->beginTry();
            /** @var PDOStatement $stmt */
            $stmt = $this->runGen(false, PDO::FETCH_ASSOC, 'tometa', $this->parent->genError);
            if ($this->endtry() === false) {
                return false;
            }
        } else {
            if ($this->parent->useInternalCache) {
                $uid = hash($this->parent->encryption->hashType, 'meta:' . $sql . serialize($args));
                if (isset($this->internalCache[$uid])) {
                    // we have an internal cache, so we will return it.
                    $this->parent->internalCacheCounter++;
                    return $this->parent->internalCache[$uid];
                }
            }
            /** @var PDOStatement $stmt */
            $stmt = $this->parent->runRawQuery($sql, $args, false, $this->useCache, $this->cacheFamily);
        }
        if ($stmt === null || $stmt instanceof PDOStatement === false) {
            $stmt = null;

            return false;
        }
        $numCol = $stmt->columnCount();
        $rows = [];
        for ($i = 0; $i < $numCol; $i++) {
            $rows[] = $stmt->getColumnMeta($i);
        }
        $stmt = null;
        if ($uid !== false) {
            $this->parent->internalCache[$uid] = $rows;
        }
        return $rows;
    }

    /**
     * Run builder query and returns a PDOStatement.
     *
     * @param bool   $returnArray      true=return an array. False returns a
     *                                 PDOStatement
     * @param int    $extraMode        PDO::FETCH_ASSOC,PDO::FETCH_BOTH,PDO::FETCH_NUM,etc.
     *                                 By default it returns
     *                                 $extraMode=PDO::FETCH_ASSOC
     *
     * @param string $extraIdCache     [optional] if 'rungen' then cache is
     *                                 stored. If false the cache could be
     *                                 stored
     *
     * @param bool   $throwError
     *
     * @return bool|PDOStatement|array
     * @throws Exception
     */
    public function runGen(
        $returnArray = true,
        $extraMode = PDO::FETCH_ASSOC,
        $extraIdCache = 'rungen',
        $throwError = true
    )
    {
        $this->parent->errorText = '';
        $allparam = '';
        $uid = false;
        $sql = $this->sqlGen();
        $isSelect = PdoOne::queryCommand($sql, true) === 'dql';

        try {
            $allparam = array_merge($this->setParamAssoc, $this->whereParamAssoc, $this->havingParamAssoc);

            if ($isSelect && $this->parent->useInternalCache && $returnArray) {
                $uid = hash($this->parent->encryption->hashType, $sql . $extraMode . serialize($allparam));
                if (isset($this->internalCache[$uid])) {
                    // we have an internal cache, so we will return it.
                    $this->parent->internalCacheCounter++;
                    $this->builderReset();
                    return $this->parent->internalCache[$uid];
                }
            }

            /** @var PDOStatement $stmt */
            $stmt = $this->parent->prepare($sql);
        } catch (Exception $e) {
            $this->throwErrorChain('Error in prepare runGen', $extraIdCache, ['values' => $allparam], $throwError, $e);
            $this->builderReset();
            return false;
        }
        if ($stmt === null || $stmt === false) {
            $this->builderReset();
            return false;
        }
        $reval = true;
        if ($allparam) {
            try {
                foreach ($allparam as $k => $v) {
                    $reval = $reval && $stmt->bindParam($v[0], $allparam[$k][1], $v[2]);
                }
            } catch (Exception $ex) {
                if (is_object($allparam[$k][1])) {
                    $this->throwErrorChain("Error in bind. Parameter error."
                        , "Parameter {$v[0]} ($k) is an object of the class " . get_class($allparam[$k][1])
                        , ['values' => $allparam], $throwError);
                    $this->builderReset();
                    return false;
                }
                $this->throwErrorChain("Error in bind. Parameter error.", "Parameter {$v[0]} ($k)"
                    , ['values' => $allparam], $throwError);
                $this->builderReset();
                return false;
            }
            if (!$reval) {
                $this->throwErrorChain('Error in bind', $extraIdCache, ['values' => $allparam], $throwError);
                $this->builderReset();
                return false;
            }
        }
        $useCache = $this->useCache; // because builderReset cleans this value
        if ($useCache !== false && $returnArray) {
            $this->uid
                = hash($this->parent->encryption->hashType,
                $this->parent->lastQuery . $extraMode . serialize($allparam) . $extraIdCache);
            $result = $this->parent->cacheService->getCache($this->uid, $this->cacheFamily);
            if ($result !== false) {
                // it's found in the cache.
                $this->builderReset();
                if ($uid !== false) {
                    $this->parent->internalCache[$uid] = $result;
                }
                return $result;
            }
        } elseif ($extraIdCache === 'rungen') {
            $this->uid = null;
        }
        $this->parent->runQuery($stmt, null, false);
        if ($returnArray && $stmt instanceof PDOStatement) {
            $result = ($stmt->columnCount() > 0) ? $stmt->fetchAll($extraMode) : [];
            $this->parent->affected_rows = $stmt->rowCount();
            $stmt = null; // close
            if ($extraIdCache === 'rungen' && $this->uid) {
                // we store the information of the cache.
                $this->parent->setCache($this->uid, $this->cacheFamily, $result, $useCache);
            }
            $this->builderReset();
            if ($uid !== false) {
                $this->parent->internalCache[$uid] = $result;
            }
            return $result;
        }

        $this->builderReset();
        return $stmt;
    }

    /**
     * Generates the sql (script). It doesn't run or execute the query.
     *
     * @param bool $resetStack     if true then it reset all the values of the
     *                             stack, including parameters.
     *
     * @return string
     */
    public function sqlGen($resetStack = false)
    {
        if (stripos($this->select, 'select ') === 0) {
            // is it a full query? $this->select=select * ..." instead of $this->select=*
            $words = preg_split('#\s+#', strtolower($this->select));
        } else {
            $words = [];
        }
        if (!in_array('select', $words)) {
            $sql = 'select ' . $this->distinct . $this->select;
        } else {
            $sql = $this->select; // the query already constains "select", so we don't want "select select * from".
        }
        if (!in_array('from', $words)) {
            $sql .= ' from ' . $this->from;
        } else {
            $sql .= $this->from;
        }
        $where = $this->constructWhere();
        $having = $this->constructHaving();

        $sql .= $where . $this->group . $having . $this->order . $this->limit;

        if ($resetStack) {
            $this->builderReset();
        }

        return $sql;
    }

    /**
     * @return string
     */
    private function constructWhere()
    {
        return count($this->where) ? ' where ' . implode(' and ', $this->where) : '';
    }

    /**
     * @return string
     */
    private function constructHaving()
    {
        return count($this->having) ? ' having ' . implode(' and ', $this->having) : '';
    }

    /**
     * It reset the parameters used to Build Query.
     *
     * @param bool $forced if true then calling this method resets the stacks of variables<br>
     *                     if false then it only resets the stack if $this->noreset=false; (default is false)
     */
    public function builderReset($forced = false)
    {
        if ($this->noReset && !$forced) {
            return;
        }
        $this->select = '';
        $this->noReset = false;
        $this->useCache = false;
        $this->from = '';
        $this->parent->tables = [];
        $this->where = [];

        $this->whereParamAssoc = [];
        $this->setParamAssoc = [];
        $this->havingParamAssoc = [];

        $this->whereCounter = 1;
        //$this->whereParamValue = [];
        $this->set = [];
        $this->group = '';
        $this->recursive = [];
        $this->parent->genError = true;
        $this->having = [];
        $this->limit = '';
        $this->distinct = '';
        $this->order = '';
    }

    /**
     * Write a log line for debug, clean the command chain then throw an error
     * (if throwOnError==true)
     *
     * @param string                $txt        The message to show.
     * @param string                $txtExtra   It's only used if $logLevel>=2. It
     *                                          shows an extra message
     * @param string|array          $extraParam It's only used if $logLevel>=3  It
     *                                          shows parameters (if any)
     *
     * @param bool                  $throwError if true then it throw error (is enabled). Otherwise it store the error.
     *
     * @param null|RuntimeException $exception
     *
     * @see \eftec\PdoOne::$logLevel
     */
    public function throwErrorChain($txt, $txtExtra, $extraParam = '', $throwError = true, $exception = null)
    {
        if ($this->parent->logLevel === 0) {
            $txt = 'Error on database';
        }
        if ($this->parent->logLevel >= 2) {
            $txt .= "\n<br><b>extra:</b>[{$txtExtra}]";
        }
        if ($this->parent->logLevel >= 2) {
            $txt .= "\n<br><b>last query:</b>[{$this->parent->lastQuery}]";
        }
        if ($this->parent->logLevel >= 3) {
            $txt .= "\n<br><b>database:</b>" . $this->parent->server . ' - ' . $this->parent->db;
            if (is_array($extraParam)) {
                foreach ($extraParam as $k => $v) {
                    if (is_array($v) || is_object($v)) {
                        $v = json_encode($v);
                    }
                    $txt .= "\n<br><b>$k</b>:$v";
                }
            } else {
                $txt .= "\n<br><b>Params :</b>[" . $extraParam . "]\n<br>";
            }
            if ($exception !== null) {
                $txt .= "\n<br><b>message :</b>[" . str_replace("\n", "\n<br>", $exception->getMessage()) . "]";
                $txt .= "\n<br><b>trace :</b>[" . str_replace("\n", "\n<br>", $exception->getTraceAsString()) . "]";
                $txt .= "\n<br><b>code :</b>[" . str_replace("\n", "\n<br>", $exception->getCode()) . "]\n<br>";
            }
        }
        if ($this->parent->getMessages() === null) {
            $this->parent->debugFile($txt, 'ERROR');
        } else {
            $this->parent->getMessages()->addItem($this->parent->db, $txt);
            $this->parent->debugFile($txt, 'ERROR');
        }
        $this->parent->errorText = $txt;

        if ($throwError && $this->parent->throwOnError && $this->parent->genError) {
            throw new RuntimeException($txt);
        }
        $this->builderReset(true); // it resets the chain if any.
    }

    /**
     * It ends a try block and throws the error (if any)
     *
     * @return bool
     * @throws Exception
     */
    private function endTry()
    {
        $this->parent->throwOnError = $this->parent->throwOnErrorB;
        if ($this->parent->errorText) {
            $this->throwErrorChain('endtry:' . $this->parent->errorText, '', '', $this->parent->isThrow);
            return false;
        }
        return true;
    }

    /**
     * Executes the query, and returns the first column of the first row in the
     * result set returned by the query. Additional columns or rows are ignored.<br>
     * If value is not found then it returns null.<br>
     * * This method is an <b>end of the chain method</b>, so it clears the method stack<br>
     * <b>Example</b>:<br>
     * <pre>
     * $con->select('*')->from('table')->firstScalar(); // select * from table (first scalar value)
     * </pre>
     *
     * @param string|null $colName     If it's null then it uses the first
     *                                 column.
     *
     * @return mixed|null
     * @throws Exception
     */
    public function firstScalar($colName = null)
    {
        $rows = null;
        $useCache = $this->useCache; // because builderReset cleans this value
        if ($useCache !== false) {
            $sql = $this->sqlGen();
            $this->uid = hash($this->parent->encryption->hashType,
                $sql . PDO::FETCH_ASSOC . serialize($this->whereParamAssoc) . serialize($this->havingParamAssoc)
                . 'firstscalar');
            $rows = $this->parent->cacheService->getCache($this->uid, $this->cacheFamily);
            if ($rows !== false) {
                $this->builderReset();

                return $rows;
            }
        }
        $this->parent->beginTry();
        /** @var PDOStatement $statement */
        $statement = $this->runGen(false, PDO::FETCH_ASSOC, 'firstscalar', false);
        if ($this->endtry() === false) {
            return null;
        }
        $row = null;
        if ($statement === false) {
            $row = null;
        } elseif (!$statement->columnCount()) {
            $row = null;
        } else {
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            @$statement->closeCursor();
            $statement = null;
            if ($row !== false) {
                if ($colName === null) {
                    $row = reset($row); // first column of the first row
                } else {
                    $row = $row[$colName];
                }
            } else {
                $row = null;
            }
        }
        if ($this->uid && $useCache !== false) {
            // we store the information of the cache.
            $this->parent->setCache($this->uid, $this->cacheFamily, $row, $useCache);
        }

        return $row;
    }

    /**
     * Returns the last row. It's not recommended. Use instead first() and change the order.<br>
     * This method is an <b>end of the chain method</b>, so it clears the method stack<br>
     * <b>Note</b>: This method could not be efficient because it reads all the values.
     * If you can, then use the methods sort()::first()<br>
     * <b>Example</b>:<br>
     * <pre>
     * $con->select('*')->from('table')->last(); // select * from table (last scalar value)
     * </pre>
     *
     * @return array|null
     * @throws Exception
     * @see \eftec\PdoOne::first
     */
    public function last()
    {
        $useCache = $this->useCache; // because builderReset cleans this value

        if ($useCache !== false) {
            $sql = $this->sqlGen();
            $this->uid = hash($this->parent->encryption->hashType,
                $sql . PDO::FETCH_ASSOC . serialize($this->whereParamAssoc) . serialize($this->havingParamAssoc)
                . 'last');
            $rows = $this->parent->cacheService->getCache($this->uid, $this->cacheFamily);
            if ($rows !== false) {
                $this->builderReset();

                return $rows;
            }
        }
        $this->parent->beginTry();
        /** @var PDOStatement $statement */
        $statement = $this->runGen(false, PDO::FETCH_ASSOC, 'last', false);
        if ($this->endtry() === false) {
            return null;
        }
        $row = null;
        if ($statement === false) {
            $row = null;
        } elseif (!$statement->columnCount()) {
            $row = null;
        } else {
            while ($dummy = $statement->fetch(PDO::FETCH_ASSOC)) {
                $row = $dummy;
            }
            @$statement->closeCursor();
            $statement = null;
        }

        if ($this->uid && $useCache !== false) {
            // we store the information of the cache.
            $this->parent->setCache($this->uid, $this->cacheFamily, $row, $useCache);
        }

        return $row;
    }

    /**
     * It returns an array of simple columns (not declarative). It uses the
     * first column<br>
     * <b>Example:</b><br>
     * <pre>
     * select('select id from table')->toListSimple() // ['1','2','3','4']
     * </pre>
     *
     * @return array|bool
     * @throws Exception
     */
    public function toListSimple()
    {
        $useCache = $this->useCache; // because builderReset cleans this value
        $this->parent->beginTry();
        $rows = $this->runGen(true, PDO::FETCH_COLUMN, 'tolistsimple', false);
        if ($this->endtry() === false) {
            return false;
        }
        if ($this->uid && $useCache !== false) {
            // we store the information of the cache.
            $this->parent->setCache($this->uid, $this->cacheFamily, $rows, $useCache);
        }

        return $rows;
    }

    /**
     * It adds a having to the query builder.
     * <br><b>Example</b>:<br>
     *      select('*')->from('table')->group('col')->having('field=2')
     *      having( ['field'=>20] ) // associative array with automatic type
     *      having( ['field'=>[20]] ) // associative array with type defined
     *      having( ['field',20] ) // array automatic type
     *      having(['field',[20]] ) // array type defined
     *      having('field=20') // literal value
     *      having('field=?',[20]) // automatic type
     *      having('field',[20]) // automatic type (it's the same than
     *      where('field=?',[20]) having('field=?', [20] ) // type(i,d,s,b)
     *      defined having('field=?,field2=?', [20,'hello'] )
     *
     * @param string|array $sql
     * @param array|mixed  $param
     *
     * @return PdoOneQuery
     * @see  http://php.net/manual/en/mysqli-stmt.bind-param.php for types
     * @test InstanceOf
     *       PdoOne::class,this('field1=?,field2=?',[20,'hello'])
     */
    public function having($sql, $param = PdoOne::NULL)
    {
        if ($sql === null) {
            return $this;
        }

        return $this->where($sql, $param, true);
    }

    /**
     * <b>Example:</b><br>
     *      where( ['field'=>20] ) // associative array with automatic type
     *      where( ['field'=>[20]] ) // associative array with type defined
     *      where( ['field',20] ) // array automatic type
     *      where (['field',[20]] ) // array type defined
     *      where('field=20') // literal value
     *      where('field=?',[20]) // automatic type
     *      where('field',[20]) // automatic type (it's the same than
     *      where('field=?',[20]) where('field=?', [20] ) // type(i,d,s,b)
     *      defined where('field=?,field2=?', [20,'hello'] )
     *      where('field=:field,field2=:field2',
     *      ['field'=>'hello','field2'=>'world'] ) // associative array as value
     *
     * @param string|array $sql          Input SQL query or associative/indexed
     *                                   array
     * @param array|mixed  $param        Associative or indexed array with the
     *                                   conditions.
     * @param bool         $isHaving     if true then it is a HAVING sql commando
     *                                   instead of a WHERE.
     *
     * @param null|string  $tablePrefix
     *
     * @return PdoOneQuery
     * @see  http://php.net/manual/en/mysqli-stmt.bind-param.php for types
     * @test InstanceOf
     *       PdoOne::class,this('field1=?,field2=?',[20,'hello'])
     */
    public function where($sql, $param = PdoOne::NULL, $isHaving = false, $tablePrefix = null)
    {
        if ($sql === null) {
            return $this;
        }
        $this->constructParam2($sql, $param, $isHaving ? 'having' : 'where', false, $tablePrefix);
        return $this;
    }

    /**
     * <b>Example:</b><br>
     * <pre>
     * where( ['field'=>20] ) // associative array (named)
     * where( ['field=?'=>20] ) // associative array (numeric)
     * where( ['field=:name'=>20] ) // associative array (named)
     * where( ['field=:name and field2=:name'=>20] ) // IT DOESN'T WORK
     * where( ['field'=>[20]] ) // associative array with type defined
     * where( ['field',20] ) // indexed array automatic type
     * where (['field',[20]] ) // indexed array type defined
     * where('field=20') // literal value
     * where('field=?',[20]) // automatic type
     * where('field',[20]) // automatic type (it's the same than
     * where('field=?',[20]) where('field=?', [20] ) // type(i,d,s,b)
     *      defined where('field=?,field2=?', [20,'hello'] )
     * where('field=:field,field2=:field2',
     *      ['field'=>'hello','field2'=>'world'] ) // associative array as value
     * </pre>
     *
     * @param array|string     $where
     * @param string|array|int $params
     * @param string           $type
     * @param bool             $return
     * @param null|string      $tablePrefix
     *
     * @return array|null
     */
    public function constructParam2(
        $where,
        $params = PdoOne::NULL,
        $type = 'where',
        $return = false,
        $tablePrefix = null
    )
    {
        $queryEnd = [];
        $named = [];
        $pars = [];

        if ($params === PdoOne::NULL || $params === null) {
            if (is_array($where)) {
                $numeric = isset($where[0]);
                if ($numeric) {
                    // numeric
                    $c = count($where) - 1;
                    for ($i = 0; $i < $c; $i += 2) {
                        $v = $where[$i + 1];
                        // constructParam2(['field',20]])
                        $param = [$this->whereCounter, $v, $this->parent->getType($v), null];
                        $queryEnd[] = $where[$i];
                        $named[] = '?';
                        $this->whereCounter++;
                        $pars[] = $param;
                    }
                } else {
                    // named
                    foreach ($where as $k => $v) {
                        if (strpos($k, '?') === false) {
                            if (strpos($k, ':') !== false) {
                                // "aaa=:aaa"

                                $parts = explode(':', $k, 2);
                                $paramName = ':' . str_replace('.', '_', $parts[1]);
                                $named[] = $paramName;
                            } else {
                                // "aaa"

                                $paramName = ':' . str_replace('.', '_', $k);
                                $named[] = $paramName;
                            }
                        } else {
                            // "aa=?"
                            $paramName = $this->whereCounter;
                            $this->whereCounter++;
                            $named[] = '?';
                        }
                        // constructParam2(['field'=>20])
                        $param = [$paramName, $v, $this->parent->getType($v), null];
                        $pars[] = $param;
                        if ($tablePrefix !== null && strpos($k, '.') === false) {
                            $queryEnd[] = $tablePrefix . '.' . $k;
                        } else {
                            $queryEnd[] = $k;
                        }
                    }
                }
            } else {
                // constructParam2('query=xxx')
                $named[] = '';
                $queryEnd[] = $where;
            }
        } else {
            // where and params are not empty
            if (!is_array($params)) {
                $params = [$params];
            }
            if (!is_array($where)) {
                $queryEnd[] = $where;
                $numeric = isset($params[0]);
                if ($numeric) {
                    foreach ($params as $k => $v) {
                        // constructParam2('name=? and type>?', ['Coca-Cola',12345]);
                        $named[] = '?';
                        $pars[] = [
                            $this->whereCounter,
                            $v,
                            $this->parent->getType($v),
                            null
                        ];
                        $this->whereCounter++;
                    }
                } else {
                    foreach ($params as $k => $v) {
                        $named[] = $k;
                        // constructParam2('name=:name and type<:type', ['name'=>'Coca-Cola','type'=>987]);;
                        $pars[] = [$k, $v, $this->parent->getType($v), null];
                        //$paramEnd[]=$param;
                    }
                }
                if (count($named) === 0) {
                    $named[] = '?'; // at least one argument.
                }
            } else {
                // constructParam2([],..);
                $numeric = isset($where[0]);

                if ($numeric) {
                    foreach ($where as $k => $v) {
                        //$named[] = '?';
                        $queryEnd[] = $v;
                    }
                } else {
                    trigger_error('parameteres not correctly defined');
                    /*foreach ($where as $k => $v) {
                        $named[] = '?';
                        $queryEnd[] = $k;
                    }*/
                }
                $numeric = isset($params[0]);
                if ($numeric) {
                    foreach ($params as $k => $v) {
                        //$paramEnd[]=$param;
                        // constructParam2(['name','type'], ['Coca-Cola',123]);
                        $named[] = '?';
                        $pars[] = [$this->whereCounter, $v, $this->parent->getType($v), null];
                        $this->whereCounter++;
                        //$paramEnd[]=$param;
                    }
                } else {
                    foreach ($params as $k => $v) {
                        $named[] = $k;
                        // constructParam2(['name=:name','type<:type'], ['name'=>'Coca-Cola','type'=>987]);;
                        $pars[] = [$k, $v, $this->parent->getType($v), null];
                        //$paramEnd[]=$param;
                    }
                }
            }
        }
        //echo "<br>where:";

        $i = -1;

        foreach ($queryEnd as $k => $v) {
            $i++;

            if ($named[$i] !== '' && strpos($v, '?') === false && strpos($v, $named[$i]) === false) {
                $v .= '=' . $named[$i];
                $queryEnd[$k] = $v;
            }
            switch ($type) {
                case 'where':
                    $this->where[] = $v;
                    break;
                case 'having':
                    $this->having[] = $v;
                    break;
                case 'set':
                    $this->set[] = $v;
                    break;
            }
        }

        switch ($type) {
            case 'where':
                $this->whereParamAssoc = array_merge($this->whereParamAssoc, $pars);
                break;
            case 'having':
                $this->havingParamAssoc = array_merge($this->havingParamAssoc, $pars);
                break;
            case 'set':
                $this->setParamAssoc = array_merge($this->setParamAssoc, $pars);
                break;
        }

        if ($return) {
            return [$queryEnd, $pars];
        }
        return null;
    }

    //</editor-fold>

    //<editor-fold desc="Query Builder functions" defaultstate="collapsed" >

    /**
     * Returns true if the current query has a "having" or "where"
     *
     * @param bool $having <b>true</b> it return the number of where<br>
     *                     <b>false</b> it returns the number of having
     *
     * @return bool
     */
    public function hasWhere($having = false)
    {
        if ($having) {
            return count($this->having) > 0;
        }

        return count($this->where) > 0;
    }

    /**
     * It adds an "limit" in a query. It depends on the type of database<br>
     * <b>Example:</b><br>
     * <pre>
     *      ->select("")->limit("10,20")->toList();
     * </pre>
     *
     * @param string $sql Input SQL query
     *
     * @return PdoOneQuery
     * @throws Exception
     * @test InstanceOf PdoOne::class,this('1,10')
     */
    public function limit($sql)
    {
        if ($sql === null) {
            return $this;
        }
        $this->limit = $this->parent->service->limit($sql);

        return $this;
    }

    /**
     * Adds a distinct to the query. The value is ignored if the select() is
     * written complete.<br>
     * <pre>
     *      ->select("*")->distinct() // works
     *      ->select("select *")->distinct() // distinct is ignored.
     *</pre>
     *
     * @param string $sql Input SQL query
     *
     * @return PdoOneQuery
     * @test InstanceOf PdoOne::class,this()
     */
    public function distinct($sql = 'distinct')
    {
        if ($sql === null) {
            return $this;
        }
        $this->distinct = ($sql) ? $sql . ' ' : '';

        return $this;
    }

    /**
     * It returns an associative array where the first value is the key and the
     * second is the value<br> If the second value does not exist then it uses
     * the index as value (first value)<br>
     * <b>Example:</b><br>
     * <pre>
     * select('select cod,name from table')->toListKeyValue() //
     * ['cod1'=>'name1','cod2'=>'name2'] select('select cod,name,ext from
     * table')->toListKeyValue('|') //
     * ['cod1'=>'name1|ext1','cod2'=>'name2|ext2']
     * </pre>
     *
     * @param string|null $extraValueSeparator     (optional) It allows to read a
     *                                             third value and returns it
     *                                             concatenated with the value.
     *                                             Example '|'
     *
     * @return array|bool|null
     * @throws Exception
     */
    public function toListKeyValue($extraValueSeparator = null)
    {
        $list = $this->toList(PDO::FETCH_NUM);
        if (!is_array($list)) {
            return null;
        }
        $result = [];
        foreach ($list as $item) {
            if ($extraValueSeparator === null) {
                $result[$item[0]] = isset($item[1]) ? $item[1] : $item[0];
            } else {
                $result[$item[0]] = (isset($item[1]) ? $item[1] : $item[0]) . $extraValueSeparator . @$item[2];
            }
        }

        return $result;
    }

    /**
     * It returns an declarative array of rows.<br>
     * If not data is found, then it returns an empty array<br>
     * This method is an <b>end of the chain method</b>, so it clears the method stack<br>
     * <b>Example</b>:<br>
     * <pre>
     * $this->select('select id,name from table')->toList() // [['id'=>'1','name'='john'],['id'=>'2','name'=>'anna']]
     * $this->select('id,name')
     *      ->from('table')
     *      ->where('condition=?',[20])
     *      ->toList();
     * </pre>
     *
     * @param int $pdoMode (optional) By default is PDO::FETCH_ASSOC
     *
     * @return array|bool
     * @throws Exception
     */
    public function toList($pdoMode = PDO::FETCH_ASSOC)
    {
        $useCache = $this->useCache; // because builderReset cleans this value
        $this->parent->beginTry();
        $rows = $this->runGen(true, $pdoMode, 'tolist', false);
        if ($this->endtry() === false) {
            return false;
        }
        if ($this->uid && $useCache !== false) {
            // we store the information of the cache.
            $this->parent->setCache($this->uid, $this->cacheFamily, $rows, $useCache);
        }
        return $rows;
    }

    /**
     * It returns a PDOStatement.<br>
     * <b>Note:</b> The result is not cached.
     *
     * @return PDOStatement
     * @throws Exception
     */
    public function toResult()
    {
        return $this->runGen(false);
    }

    /**
     * It returns the first row.  If there is not row then it returns false.<br>
     * This method is an <b>end of the chain method</b>, so it clears the method stack<br>
     * <b>Example</b>:<br>
     * <pre>
     *      $con->select('*')->from('table')->first(); // select * from table
     *      (first value)
     * </pre>
     *
     * @return array|null|false
     * @throws Exception
     */
    public function first()
    {
        $useCache = $this->useCache; // because builderReset cleans this value
        $uid = false;
        if ($useCache !== false) {
            $sql = $this->sqlGen();
            $this->uid = hash($this->parent->encryption->hashType,
                $sql . PDO::FETCH_ASSOC . serialize($this->whereParamAssoc) . serialize($this->havingParamAssoc)
                . 'firstscalar');
            $rows = $this->parent->cacheService->getCache($this->uid, $this->cacheFamily);
            if ($rows !== false) {
                $this->builderReset();

                return $rows;
            }
        }
        if ($this->parent->useInternalCache) {
            $sql = (!isset($sql)) ? $this->sqlGen() : $sql;
            $allparam = array_merge($this->setParamAssoc, $this->whereParamAssoc, $this->havingParamAssoc);
            $uid = hash($this->parent->encryption->hashType, 'first' . $sql . serialize($allparam));
            if (isset($this->parent->internalCache[$uid])) {
                // we have an internal cache, so we will return it.
                $this->parent->internalCacheCounter++;
                $this->builderReset();
                return $this->parent->internalCache[$uid];
            }
        }
        $this->parent->beginTry();
        /** @var PDOStatement $statement */
        $statement = $this->runGen(false, PDO::FETCH_ASSOC, 'first', false);
        if ($this->endtry() === false) {
            return null;
        }
        $row = null;
        if ($statement === false) {
            $row = null;
        } elseif (!$statement->columnCount()) {
            $row = null;
        } else {
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            @$statement->closeCursor();
            $statement = null;
        }

        if ($this->uid && $useCache !== false) {
            // we store the information of the cache.
            $this->parent->setCache($this->uid, $this->cacheFamily, $row, $useCache);
        }
        if ($uid !== false) {
            $this->parent->internalCache[$uid] = $row;
        }

        return $row;
    }

    /**
     * If true, then on error, the code thrown an error.<br>>
     * If false, then on error, the the code returns false and logs the errors ($this->parent->errorText).
     *
     * @param bool $value
     *
     * @return $this
     */
    public function setThrowOnError($value = false)
    {
        $this->parent->throwOnError = $value;
        return $this;
    }

    /**
     * If true then the stack/query builder will not reset the stack (but on error) when it is finished<br>
     * <b>Example:</b><br>
     * <pre>
     * $this->parent->pdoOne->select('*')->from('missintable')->setNoReset(true)->toList();
     * // we do something with the stack
     * $this->parent->pdoOne->builderReset(true); // reset the stack manually
     * </pre>
     *
     * @param bool $noReset
     *
     * @return $this
     */
    public function setNoReset($noReset = true)
    {
        $this->noReset = $noReset;
        return $this;
    }

    /**
     * It returns an uniqued uid ('sha256' or the value defined in PdoOneEncryption::$hashType) based in all the
     * parameters of the query (select,from,where,parameters,group,recursive,having,limit,distinct,order,etc.) and
     * optionally in an extra value
     *
     * @param mixed|null $extra  [optional] If we want to add an extra value to the UID generated
     * @param string     $prefix A prefix added to the UNID generated.
     *
     * @return string
     * @see \eftec\PdoOneEncryption::$hashType
     */
    public function buildUniqueID($extra = null, $prefix = '')
    {
        // set and setparam are not counted
        $all = [
            $this->select,
            $this->from,
            $this->where,
            $this->whereParamAssoc,
            $this->havingParamAssoc,
            // $this->setParamAssoc,
            //$this->whereParamValue,
            $this->group,
            $this->recursive,
            $this->having,
            $this->limit,
            $this->distinct,
            $this->order,
            $extra
        ];
        return $prefix . hash($this->parent->encryption->hashType, json_encode($all));
    }

    /**
     * It generates a query for "count". It is a macro of select()
     * <br><b>Example</b>:<br>
     * <pre>
     * ->count('')->from('table')->firstScalar() // select count(*) from
     * table<br>
     * ->count('from table')->firstScalar() // select count(*) from table<br>
     * ->count('from table where condition=1')->firstScalar() // select count(*)
     * from table where condition=1<br>
     * ->count('from table','col')->firstScalar() // select count(col) from
     * table<br>
     * </pre>
     *
     * @param string|null $sql [optional]
     * @param string      $arg [optional]
     *
     * @return PdoOneQuery
     */
    public function count($sql = '', $arg = '*')
    {
        return $this->_aggFn('count', $sql, $arg);
    }

    public function _aggFn($method, $sql = '', $arg = '')
    {
        if ($arg === '') {
            $arg = $sql; // if the argument is empty then it uses sql as argument
            $sql = ''; // and it lefts sql as empty
        }
        if ($arg === '*' || $this->parent->databaseType !== 'sqlsrv') {
            return $this->select("select $method($arg) $sql");
        }

        return $this->select("select $method(cast($arg as decimal)) $sql");
    }

    /**
     * It adds a select to the query builder.
     * <br><b>Example</b>:<br>
     * <pre>
     * ->select("\*")->from('table') = <i>"select * from table"</i><br>
     * ->select(['col1','col2'])->from('table') = <i>"select col1,col2 from
     * table"</i><br>
     * ->select('col1,col2')->from('table') = <i>"select col1,col2 from
     * table"</i><br>
     * ->select('select *')->from('table') = <i>"select * from table"</i><br>
     * ->select('select * from table') = <i>"select * from table"</i><br>
     * ->select('select * from table where id=1') = <i>"select * from table
     * where id=1"</i><br>
     * </pre>
     *
     * @param string|array $sql
     *
     * @return PdoOneQuery
     * @test InstanceOf PdoOne::class,this('select 1 from DUAL')
     */
    public function select($sql)
    {
        if (is_array($sql)) {
            $this->select .= implode(', ', $sql);
        } elseif ($this->select === '') {
            $this->select = $sql;
        } else {
            $this->select .= ', ' . $sql;
        }

        return $this;
    }

    /**
     * It generates a query for "sum". It is a macro of select()
     * <br><b>Example</b>:<br>
     * ->sum('from table','col')->firstScalar() // select sum(col) from
     * table<br>
     * ->sum('col')->from('table')->firstScalar() // select sum(col) from
     * table<br>
     * ->sum('','col')->from('table')->firstScalar() // select sum(col) from
     * table<br>
     *
     * @param string $sql     [optional] it could be the name of column or part
     *                        of the query ("from table..")
     * @param string $arg     [optiona] it could be the name of the column
     *
     * @return PdoOneQuery
     */
    public function sum($sql = '', $arg = '')
    {
        return $this->_aggFn('sum', $sql, $arg);
    }

    /**
     * It generates a query for "min". It is a macro of select()
     * <br><b>Example</b>:<br>
     * ->min('from table','col')->firstScalar() // select min(col) from
     * table<br>
     * ->min('col')->from('table')->firstScalar() // select min(col) from
     * table<br>
     * ->min('','col')->from('table')->firstScalar() // select min(col) from
     * table<br>
     *
     * @param string $sql
     * @param string $arg
     *
     * @return PdoOneQuery
     */
    public function min($sql = '', $arg = '')
    {
        return $this->_aggFn('min', $sql, $arg);
    }

    /**
     * It generates a query for "max". It is a macro of select()
     * <br><b>Example</b>:<br>
     * ->max('from table','col')->firstScalar() // select max(col) from
     * table<br>
     * ->max('col')->from('table')->firstScalar() // select max(col) from
     * table<br>
     * ->max('','col')->from('table')->firstScalar() // select max(col) from
     * table<br>
     *
     * @param string $sql
     * @param string $arg
     *
     * @return PdoOneQuery
     */
    public function max($sql = '', $arg = '')
    {
        return $this->_aggFn('max', $sql, $arg);
    }

    /**
     * It generates a query for "avg". It is a macro of select()
     * <br><b>Example</b>:<br>
     * ->avg('from table','col')->firstScalar() // select avg(col) from
     * table<br>
     * ->avg('col')->from('table')->firstScalar() // select avg(col) from
     * table<br>
     * ->avg('','col')->from('table')->firstScalar() // select avg(col) from
     * table<br>
     *
     * @param string $sql
     * @param string $arg
     *
     * @return PdoOneQuery
     */
    public function avg($sql = '', $arg = '')
    {
        return $this->_aggFn('avg', $sql, $arg);
    }

    /**
     * Adds a left join to the pipeline. It is possible to chain more than one
     * join<br>
     * <b>Example:</b><br>
     * <pre>
     *      left('table on t1.c1=t2.c2')
     *      left('table on table.c1=t2.c2').left('table2 on
     * table1.c1=table2.c2')
     * </pre>
     *
     * @param string $sql Input SQL query
     *
     * @return PdoOneQuery
     * @test InstanceOf PdoOne::class,this('table2 on table1.t1=table2.t2')
     */
    public function left($sql)
    {
        if ($sql === null) {
            return $this;
        }
        $this->from .= ($sql) ? " left join $sql" : '';
        $this->parent->tables[] = explode(' ', $sql)[0];
        return $this;
    }

    /**
     * Adds a right join to the pipeline. It is possible to chain more than one
     * join<br>
     * <b>Example:</b><br>
     *      right('table on t1.c1=t2.c2')<br>
     *      right('table on table.c1=t2.c2').right('table2 on
     *      table1.c1=table2.c2')<br>
     *
     * @param string $sql Input SQL query
     *
     * @return PdoOneQuery
     * @test InstanceOf PdoOne::class,this('table2 on table1.t1=table2.t2')
     */
    public function right($sql)
    {
        if ($sql === null) {
            return $this;
        }
        $this->from .= ($sql) ? " right join $sql" : '';
        $this->parent->tables[] = explode(' ', $sql)[0];
        return $this;
    }

    /**
     * It sets a value into the query (insert or update)<br>
     * <b>Example:</b><br>
     *      ->from("table")->set('field1=?',20),set('field2=?','hello')->insert()<br>
     *      ->from("table")->set("type=?",[6])->where("i=1")->update()<br>
     *      set("type=?",6) // automatic<br>
     *
     * @param string|array $sqlOrArray
     * @param array|mixed  $param
     *
     *
     * @return PdoOneQuery
     * @test InstanceOf
     *       PdoOne::class,this('field1=?,field2=?',[20,'hello'])
     */
    public function set($sqlOrArray, $param = PdoOne::NULL)
    {
        if ($sqlOrArray === null) {
            return $this;
        }
        if (count($this->where)) {
            $this->throwErrorChain('method set() must be before where()', 'set');
            return $this;
        }

        $this->constructParam2($sqlOrArray, $param, 'set');
        return $this;
    }

    /**
     * It groups by a condition.<br>
     * <b>Example:</b><br>
     * ->select('col1,count(*)')->from('table')->group('col1')->toList();
     *
     * @param string $sql Input SQL query
     *
     * @return PdoOneQuery
     * @test InstanceOf PdoOne::class,this('fieldgroup')
     */
    public function group($sql)
    {
        if ($sql === null) {
            return $this;
        }
        $this->group = ($sql) ? ' group by ' . $sql : '';

        return $this;
    }

    /**
     * Alias of recursive()
     *
     * @param array|mixed $fields The fields to load recursively.
     * @return $this
     * @see \eftec\PdoOne::recursive
     */
    public function include($fields)
    {
        return $this->recursive($fields);
    }

    //</editor-fold>

    /**
     * It sets a recursive array.<br>
     * <b>example:</b>:<br>
     * <pre>
     * $this->recursive(['field1','field2']);
     * </pre>
     *
     * @param array|mixed $rec The fields to load recursively.
     *
     * @return $this
     */
    public function recursive($rec)
    {
        if (is_array($rec)) {
            $this->recursive = $rec;
        } else {
            $this->recursive = [$rec];
        }
        return $this;
    }

    /**
     * It gets the recursive array.
     *
     * @return array
     */
    public function getRecursive()
    {
        return $this->recursive;
    }

    /**
     * It returns true if recursive has some needle.<br>
     * If $this->recursive is '*' then it always returns true.
     *
     * @param string     $needle
     * @param null|array $recursiveArray If null then it uses the recursive array specified by
     *                                   $this->parent->>recursive();
     *
     * @return bool
     */
    public function hasRecursive($needle, $recursiveArray = null)
    {
        if (count($this->recursive) === 1 && $this->recursive[0] === '*') {
            return true;
        }
        if ($recursiveArray) {
            return in_array($needle, $recursiveArray, true);
        }
        return in_array($needle, $this->recursive, true);
    }

    /**
     * If false then it wont generate an error.<br>
     * If true (default), then on error, it behave normally<br>
     * If false, then the error is captured and store in $this::$errorText<br>
     * This command is specific for generation of query and its reseted when the query is executed.
     *
     * @param bool $error
     *
     * @return PdoOneQuery
     * @see \eftec\PdoOne::$errorText
     */
    public function genError($error = false)
    {
        $this->parent->genError = $error;
        return $this;
    }

    /**
     * It allows to insert a declarative array. It uses "s" (string) as
     * filetype.
     * <p>Example: ->insertObject('table',['field1'=>1,'field2'=>'aaa']);
     *
     * @param string       $tableName     The name of the table.
     * @param array|object $object        associative array with the colums and
     *                                    values. If the insert returns an identity then it changes the value
     * @param array        $excludeColumn (optional) columns to exclude. Example
     *                                    ['col1','col2']
     *
     * @return mixed
     * @throws Exception
     */
    public function insertObject($tableName, &$object, $excludeColumn = [])
    {
        $objectCopy = (array)$object;
        foreach ($excludeColumn as $ex) {
            unset($objectCopy[$ex]);
        }

        $id = $this->insert($tableName, $objectCopy);
        /** id could be 0,false or null (when it is not generated */
        if ($id) {
            $pks = $this->parent->service->getDefTableKeys($tableName, true, 'PRIMARY KEY');
            if ($pks > 0) {
                // we update the object because it returned an identity.
                $k = array_keys($pks)[0]; // first primary key
                if (is_array($object)) {
                    $object[$k] = $id;
                } else {
                    $object->$k = $id;
                }
            }
        }
        return $id;
    }

    /**
     * Generates and execute an insert command.<br>
     * <b>Example:</b><br>
     * <pre>
     * insert('table',['col1',10,'col2','hello world']); // simple array: name1,value1,name2,value2..
     * insert('table',null,['col1'=>10,'col2'=>'hello world']); // definition is obtained from the values
     * insert('table',['col1'=>10,'col2'=>'hello world']); // definition is obtained from the values
     * insert('table',['col1','col2'],[10,'hello world']); // definition (binary) and value
     * insert('table',['col1','col2'],['col1'=>10,'col2'=>'hello world']); // definition declarative array)
     *      ->set(['col1',10,'col2','hello world'])
     *      ->from('table')
     *      ->insert();
     *</pre>
     *
     * @param null|string       $tableName
     * @param string[]|null     $tableDef
     * @param string[]|int|null $values
     *
     * @return mixed Returns the identity (if any) or false if the operation fails.
     * @throws Exception
     */
    public function insert(
        $tableName = null,
        $tableDef = null,
        $values = PdoOne::NULL
    )
    {
        if ($tableName === null) {
            $tableName = $this->from;
        } else {
            $this->parent->tables[] = $tableName;
        }
        if ($this->useCache === true) {
            $this->parent->invalidateCache('', $this->cacheFamily);
        }
        if ($tableDef !== null) {
            $this->constructParam2($tableDef, $values, 'set');
        }
        // using builder. from()->set()->insert()
        $errorCause = '';
        if (!$tableName) {
            $errorCause = "you can't execute an empty insert() without a from()";
        }
        if (count($this->set) === 0) {
            $errorCause = "you can't execute an empty insert() without a set()";
        }
        if ($errorCause) {
            $this->throwErrorChain('Insert:' . $errorCause, 'insert');
            return false;
        }
        //$sql = 'insert into ' . $this->parent->addDelimiter($tableName) . '  (' . implode(',', $col) . ') values('
        //    . implode(',', $colT) . ')';
        $sql
            = /** @lang text */
            'insert into ' . $this->parent->addDelimiter($tableName) . '  ' . $this->constructInsert();
        $param = $this->setParamAssoc;
        $this->parent->beginTry();
        $this->parent->runRawQuery($sql, $param, true, $this->useCache, $this->cacheFamily);
        $this->builderReset(true);
        if ($this->endtry() === false) {
            return false;
        }

        return $this->parent->insert_id();
    }

    /**
     * @return string
     */
    private function constructInsert()
    {
        if (count($this->set)) {
            $arr = [];
            $val = [];
            $first = $this->set[0];
            if (strpos($first, '=') !== false) {
                // set([])
                foreach ($this->set as $v) {
                    $tmp = explode('=', $v);
                    $arr[] = $tmp[0];
                    $val[] = $tmp[1];
                }
                $set = '(' . implode(',', $arr) . ') values (' . implode(',', $val) . ')';
            } else {
                // set('(a,b,c) values(?,?,?)',[])
                foreach ($this->setParamAssoc as $v) {
                    $vn = $v[0];
                    if ($vn[0] !== ':') {
                        $vn = ':' . $vn;
                    }
                    $val[] = $vn;
                }
                $set = '(' . implode(',', $this->set) . ') values (' . implode(',', $val) . ')';
            }
        } else {
            $set = '';
        }

        return $set;
    }

    /**
     * Delete a row(s) if they exists.
     * Example:
     *      delete('table',['col1',10,'col2','hello world']);
     *      delete('table',['col1','col2'],[10,'hello world']);
     *      $db->from('table')
     *          ->where('..')
     *          ->delete() // running on a chain
     *      delete('table where condition=1');
     *
     * @param string|null   $tableName
     * @param string[]|null $tableDefWhere
     * @param string[]|int  $valueWhere
     *
     * @return mixed
     * @throws Exception
     */
    public function delete(
        $tableName = null,
        $tableDefWhere = null,
        $valueWhere = PdoOne::NULL
    )
    {
        if ($tableName === null) {
            $tableName = $this->from;
        } else {
            $this->parent->tables[] = $tableName;
        }
        if ($this->useCache === true) {
            $this->parent->invalidateCache('', $this->cacheFamily);
        }
        // using builder. from()->set()->where()->update()
        $errorCause = '';
        if (!$tableName) {
            $errorCause = "you can't execute an empty delete() without a from()";
        }
        if ($errorCause) {
            $this->throwErrorChain('Delete:' . $errorCause, '');
            return false;
        }

        if ($tableDefWhere !== null) {
            $this->constructParam2($tableDefWhere, $valueWhere);
        }

        /** @noinspection SqlWithoutWhere */
        $sql = 'delete from ' . $this->parent->addDelimiter($tableName);
        $sql .= $this->constructWhere();
        $param = $this->whereParamAssoc;

        $this->parent->beginTry();
        $stmt = $this->parent->runRawQuery($sql, $param, false, $this->useCache, $this->cacheFamily);
        $this->builderReset(true);
        if ($this->endtry() === false) {
            return false;
        }

        return $this->parent->affected_rows($stmt);
    }


    //<editor-fold desc="Encryption functions" defaultstate="collapsed" >

    /**
     * Generate and run an update in the database.
     * <br><b>Example</b>:<br>
     * <pre>
     *      update('table',['col1',10,'col2','hello world'],['wherecol',10]);
     *      update('table',['col1','col2'],[10,'hello world'],['wherecol'],[10]);
     *      $this->from("producttype")
     *          ->set("name=?",['Captain-Crunch'])
     *          ->where('idproducttype=?',[6])
     *          ->update();
     *      update('product_category set col1=10 where idproducttype=1')
     * </pre>
     *
     * @param string|null       $tableName The name of the table or the whole
     *                                     query.
     * @param string[]|null     $tableDef
     * @param string[]|int|null $values
     * @param string[]|null     $tableDefWhere
     * @param string[]|int|null $valueWhere
     *
     * @return mixed
     * @throws Exception
     */
    public function update(
        $tableName = null,
        $tableDef = null,
        $values = PdoOne::NULL,
        $tableDefWhere = null,
        $valueWhere = PdoOne::NULL
    )
    {
        if ($tableName === null) {
            // using builder. from()->set()->where()->update()
            $tableName = $this->from;
        } else {
            $this->parent->tables[] = $tableName;
        }
        if ($this->useCache === true) {
            $this->parent->invalidateCache('', $this->cacheFamily);
        }

        if ($tableDef !== null) {
            $this->constructParam2($tableDef, $values, 'set');
        }

        if ($tableDefWhere !== null) {
            $this->constructParam2($tableDefWhere, $valueWhere);
        }

        $errorCause = '';

        if (!$tableName) {
            $errorCause = "you can't execute an empty update() without a from()";
        }
        if (count($this->set) === 0) {
            $errorCause = "you can't execute an empty update() without a set()";
        }
        if ($errorCause) {
            $this->throwErrorChain('Update:' . $errorCause, 'update');
            return false;
        }

        $sql = 'update ' . $this->parent->addDelimiter($tableName);
        $sql .= $this->constructSet();
        $sql .= $this->constructWhere();
        $param = array_merge($this->setParamAssoc, $this->whereParamAssoc); // the order matters.

        // $this->builderReset();
        $this->parent->beginTry();
        $stmt = $this->parent->runRawQuery($sql, $param, false, $this->useCache, $this->cacheFamily);
        $this->builderReset(true);
        if ($this->endtry() === false) {
            return false;
        }
        return $this->parent->affected_rows($stmt);
    }

    /**
     * @return string
     */
    private function constructSet()
    {
        return count($this->set) ? ' set ' . implode(',', $this->set) : '';
    }

    /**
     * @return array
     */
    public function getSetParamAssoc()
    {
        return $this->setParamAssoc;
    }

    /**
     * @return array
     */
    public function getWhereParamAssoc()
    {
        return $this->whereParamAssoc;
    }

    /**
     * @return array
     */
    public function getHavingParamAssoc()
    {
        return $this->havingParamAssoc;
    }


    /**
     * It adds an "order by" in a query.<br>
     * <b>Example:</b><br>
     * <pre>
     *      ->select("")->order("column")->toList();
     *      ->select("")->order("col1,col2")->toList();
     * </pre>
     *
     * @param string $sql Input SQL query
     *
     * @return PdoOneQuery
     * @test InstanceOf PdoOne::class,this('name desc')
     */
    public function order($sql)
    {
        if ($sql === null) {
            return $this;
        }
        $this->order = ($sql) ? ' order by ' . $sql : '';

        return $this;
    }

    /**
     * Macro of join.<br>
     * <b>Example</b>:<br>
     * <pre>
     *          innerjoin('tablejoin on t1.field=t2.field')
     *          innerjoin('tablejoin tj on t1.field=t2.field')
     *          innerjoin('tablejoin','t1.field=t2.field')
     * </pre>
     *
     * @param string $sql
     * @param string $condition
     *
     * @return PdoOneQuery
     * @see \eftec\PdoOne::join
     */
    public function innerjoin($sql, $condition = '')
    {
        return $this->join($sql, $condition);
    }

    /**
     * It generates an inner join<br>
     * <b>Example:</b><br>
     * <pre>
     *          join('tablejoin on t1.field=t2.field')<br>
     *          join('tablejoin','t1.field=t2.field')<br>
     * </pre>
     *
     * @param string $sql Example "tablejoin on table1.field=tablejoin.field"
     * @param string $condition
     *
     * @return PdoOneQuery
     * @test InstanceOf PdoOne::class,this('tablejoin on t1.field=t2.field')
     */
    public function join($sql, $condition = '')
    {
        if ($condition !== '') {
            $sql = "$sql on $condition";
        }
        $this->from .= ($sql) ? " inner join $sql " : '';
        $this->parent->tables[] = explode(' ', $sql)[0];

        return $this;
    }

    /**
     * Adds a from for a query. It could be used by select,insert,update and
     * delete.<br>
     * <b>Example:</b><br>
     * <pre>
     *      from('table')
     *      from('table alias')
     *      from('table1,table2')
     *      from('table1 inner join table2 on table1.c=table2.c')
     * </pre>
     *
     * @param string $sql Input SQL query
     *
     * @return PdoOneQuery
     * @test InstanceOf PdoOne::class,this('table t1')
     */
    public function from($sql)
    {
        if ($sql === null) {
            return $this;
        }
        $this->from = ($sql) ? $sql . $this->from : $this->from;
        $this->parent->tables[] = explode(' ', $sql)[0];

        return $this;
    }

    /**
     * It sets to use cache for the current pipelines. It is disabled at the end of the pipeline<br>
     * It only works if we set the cacheservice<br>
     * <b>Example</b><br>
     * <pre>
     * $this->setCacheService($instanceCache);
     * $this->useCache()->select()..; // The cache never expires
     * $this->useCache(60)->select()..; // The cache lasts 60 seconds.
     * $this->useCache(60,'customers')
     *        ->select()..; // cache associated with customers
     *                      // it could be invalidated by invalidateCache()
     * $this->useCache(60,['customers','invoices'])
     *        ->select()..; // cache associated with customers
     *                      // it could be invalidated by invalidateCache()
     * $this->useCache(60,'*')->select('col')
     *      ->from('table')->toList(); // '*' uses all the table assigned.
     * </pre>
     *
     * @param null|bool|int $ttl        <b>null</b> then the cache never expires.<br>
     *                                  <b>false</b> then we don't use cache.<br>
     *                                  <b>int</b> then it is the duration of the cache (in seconds)
     * @param string|array  $family     [optional] It is the family or group of the cache. It could be used to
     *                                  identify a group of cache to invalidate the whole group (for example
     *                                  ,invalidate all cache from a specific table).<br>
     *                                  <b>*</b> If "*" then it uses the tables assigned by from() and join()
     *
     * @return $this
     * @see \eftec\PdoOne::invalidateCache
     */
    public function useCache($ttl = 0, $family = '')
    {
        if ($this->parent->cacheService === null) {
            $ttl = false;
        }
        $this->cacheFamily = $family;
        $this->useCache = $ttl;
        return $this;
    }
}