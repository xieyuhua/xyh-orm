<?php

declare (strict_types = 1);

namespace xyh\db;

use Closure;
use PDO;
use PDOStatement;
use xyh\db\exception\BindParamException;
use xyh\db\exception\DbEventException;
use xyh\db\exception\DbException;
use xyh\db\exception\PDOException;
use xyh\Model;

/**
 * 数据库连接基础类
 * @property PDO[] $links
 * @property PDO   $linkID
 * @property PDO   $linkRead
 * @property PDO   $linkWrite
 */
abstract class PDOConnection extends Connection
{
    const PARAM_FLOAT = 21;

    /**
     * 数据库连接参数配置
     * @var array
     */
    protected $config = [
        // 数据库类型
        'type'            => '',
        // 服务器地址
        'hostname'        => '',
        // 数据库名
        'database'        => '',
        // 用户名
        'username'        => '',
        // 密码
        'password'        => '',
        // 端口
        'hostport'        => '',
        // 连接dsn
        'dsn'             => '',
        // 数据库连接参数
        'params'          => [],
        // 数据库编码默认采用utf8
        'charset'         => 'utf8',
        // 数据库表前缀
        'prefix'          => '',
        // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
        'deploy'          => 0,
        // 数据库读写是否分离 主从式有效
        'rw_separate'     => false,
        // 读写分离后 主服务器数量
        'master_num'      => 1,
        // 指定从服务器序号
        'slave_no'        => '',
        // 模型写入后自动读取主服务器
        'read_master'     => false,
        // 是否严格检查字段是否存在
        'fields_strict'   => true,
        // 开启字段缓存
        'fields_cache'    => false,
        // 监听SQL
        'trigger_sql'     => true,
        // Builder类
        'builder'         => '',
        // Query类
        'query'           => '',
        // 是否需要断线重连
        'break_reconnect' => false,
        // 断线标识字符串
        'break_match_str' => [],
    ];

    /**
     * PDO操作实例
     * @var PDOStatement
     */
    protected $PDOStatement;

    /**
     * 当前SQL指令
     * @var string
     */
    protected $queryStr = '';

    /**
     * 事务指令数
     * @var int
     */
    protected $transTimes = 0;

    /**
     * 重连次数
     * @var int
     */
    protected $reConnectTimes = 0;

    /**
     * 查询结果类型
     * @var int
     */
    protected $fetchType = PDO::FETCH_ASSOC;

    /**
     * 字段属性大小写
     * @var int
     */
    protected $attrCase = PDO::CASE_LOWER;

    /**
     * 数据表信息
     * @var array
     */
    protected $info = [];

    /**
     * 查询开始时间
     * @var float
     */
    protected $queryStartTime;

    /**
     * PDO连接参数
     * @var array
     */
    protected $params = [
        PDO::ATTR_CASE              => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS      => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES  => false,
    ];

    /**
     * 参数绑定类型映射
     * @var array
     */
    protected $bindType = [
        'string'    => PDO::PARAM_STR,
        'str'       => PDO::PARAM_STR,
        'integer'   => PDO::PARAM_INT,
        'int'       => PDO::PARAM_INT,
        'boolean'   => PDO::PARAM_BOOL,
        'bool'      => PDO::PARAM_BOOL,
        'float'     => self::PARAM_FLOAT,
        'datetime'  => PDO::PARAM_STR,
        'timestamp' => PDO::PARAM_STR,
    ];

    /**
     * 服务器断线标识字符
     * @var array
     */
    protected $breakMatchStr = [
        'server has gone away',
        'no connection to the server',
        'Lost connection',
        'is dead or not enabled',
        'Error while sending',
        'decryption failed or bad record mac',
        'server closed the connection unexpectedly',
        'SSL connection has been closed unexpectedly',
        'Error writing data to the connection',
        'Resource deadlock avoided',
        'failed with errno',
        'child connection forced to terminate due to client_idle_limit',
        'query_wait_timeout',
        'reset by peer',
        'Physical connection is not usable',
        'TCP Provider: Error code 0x68',
        'ORA-03114',
        'Packets out of order. Expected',
        'Adaptive Server connection failed',
        'Communication link failure',
        'connection is no longer usable',
        'Login timeout expired',
        'SQLSTATE[HY000] [2002] Connection refused',
        'running with the --read-only option so it cannot execute this statement',
        'The connection is broken and recovery is not possible. The connection is marked by the client driver as unrecoverable. No attempt was made to restore the connection.',
        'SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo failed: Try again',
        'SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo failed: Name or service not known',
        'SQLSTATE[HY000]: General error: 7 SSL SYSCALL error: EOF detected',
        'SQLSTATE[HY000] [2002] Connection timed out',
        'SSL: Connection timed out',
        'SQLSTATE[HY000]: General error: 1105 The last transaction was aborted due to Seamless Scaling. Please retry.',
    ];

    /**
     * 绑定参数
     * @var array
     */
    protected $bind = [];

    /**
     * 获取当前连接器类对应的Query类
     * @access public
     * @return string
     */
    public function getQueryClass(): string
    {
        return $this->getConfig('query') ?: Query::class;
    }

    /**
     * 获取当前连接器类对应的Builder类
     * @access public
     * @return string
     */
    public function getBuilderClass(): string
    {
        return $this->getConfig('builder') ?: '\\xyh\\db\\builder\\' . ucfirst($this->getConfig('type'));
    }

    /**
     * 解析pdo连接的dsn信息
     * @access protected
     * @param array $config 连接信息
     * @return string
     */
    abstract protected function parseDsn(array $config): string;

    /**
     * 取得数据表的字段信息
     * @access public
     * @param string $tableName 数据表名称
     * @return array
     */
    abstract public function getFields(string $tableName): array;

    /**
     * 取得数据库的表信息
     * @access public
     * @param string $dbName 数据库名称
     * @return array
     */
    abstract public function getTables(string $dbName = ''): array;

    /**
     * 对返数据表字段信息进行大小写转换出来
     * @access public
     * @param array $info 字段信息
     * @return array
     */
    public function fieldCase(array $info): array
    {
        // 字段大小写转换
        switch ($this->attrCase) {
            case PDO::CASE_LOWER:
                $info = array_change_key_case($info);
                break;
            case PDO::CASE_UPPER:
                $info = array_change_key_case($info, CASE_UPPER);
                break;
            case PDO::CASE_NATURAL:
            default:
                // 不做转换
        }

        return $info;
    }

    /**
     * 获取字段类型
     * @access protected
     * @param string $type 字段类型
     * @return string
     */
    protected function getFieldType(string $type): string
    {
        if (0 === stripos($type, 'set') || 0 === stripos($type, 'enum')) {
            $result = 'string';
        } elseif (preg_match('/(double|float|decimal|real|numeric)/is', $type)) {
            $result = 'float';
        } elseif (preg_match('/(int|serial|bit)/is', $type)) {
            $result = 'int';
        } elseif (preg_match('/bool/is', $type)) {
            $result = 'bool';
        } elseif (0 === stripos($type, 'timestamp')) {
            $result = 'timestamp';
        } elseif (0 === stripos($type, 'datetime')) {
            $result = 'datetime';
        } elseif (0 === stripos($type, 'date')) {
            $result = 'date';
        } else {
            $result = 'string';
        }

        return $result;
    }

    /**
     * 获取字段绑定类型
     * @access public
     * @param string $type 字段类型
     * @return integer
     */
    public function getFieldBindType(string $type): int
    {
        if (in_array($type, ['integer', 'string', 'float', 'boolean', 'bool', 'int', 'str'])) {
            $bind = $this->bindType[$type];
        } elseif (0 === strpos($type, 'set') || 0 === strpos($type, 'enum')) {
            $bind = PDO::PARAM_STR;
        } elseif (preg_match('/(double|float|decimal|real|numeric)/is', $type)) {
            $bind = self::PARAM_FLOAT;
        } elseif (preg_match('/(int|serial|bit)/is', $type)) {
            $bind = PDO::PARAM_INT;
        } elseif (preg_match('/bool/is', $type)) {
            $bind = PDO::PARAM_BOOL;
        } else {
            $bind = PDO::PARAM_STR;
        }

        return $bind;
    }

    /**
     * 获取数据表信息缓存key
     * @access protected
     * @param string $schema 数据表名称
     * @return string
     */
    protected function getSchemaCacheKey(string $schema): string
    {
        return $this->getConfig('hostname') . ':' . $this->getConfig('hostport') . '@' . $schema;
    }

    /**
     * @param string $tableName 数据表名称
     * @param bool $force 强制从数据库获取
     * @return array
     */
    public function getSchemaInfo(string $tableName, $force = false)
    {
        if (!strpos($tableName, '.')) {
            $schema = $this->getConfig('database') . '.' . $tableName;
        } else {
            $schema = $tableName;
        }

        if (!isset($this->info[$schema]) || $force) {
            // 读取字段缓存
            $cacheKey   = $this->getSchemaCacheKey($schema);
            $cacheField = $this->config['fields_cache'] && !empty($this->cache);

            if ($cacheField && !$force) {
                $info = $this->cache->get($cacheKey);
            }

            if (empty($info)) {
                $info = $this->getTableFieldsInfo($tableName);
                if ($cacheField) {
                    $this->cache->set($cacheKey, $info);
                }
            }

            $pk      = $info['_pk'] ?? null;
            $autoinc = $info['_autoinc'] ?? null;
            unset($info['_pk'], $info['_autoinc']);

            $bind = [];
            foreach ($info as $name => $val) {
                $bind[$name] = $this->getFieldBindType($val);
            }

            $this->info[$schema] = [
                'fields'  => array_keys($info),
                'type'    => $info,
                'bind'    => $bind,
                'pk'      => $pk,
                'autoinc' => $autoinc,
            ];
        }

        return $this->info[$schema];
    }

    /**
     * 获取数据表信息
     * @access public
     * @param mixed  $tableName 数据表名 留空自动获取
     * @param string $fetch     获取信息类型 包括 fields type bind pk
     * @return mixed
     */
    public function getTableInfo($tableName, string $fetch = '')
    {
        if (is_array($tableName)) {
            $tableName = key($tableName) ?: current($tableName);
        }

        if (strpos($tableName, ',') || strpos($tableName, ')')) {
            // 多表不获取字段信息
            return [];
        }

        [$tableName] = explode(' ', $tableName);

        $info = $this->getSchemaInfo($tableName);

        return $fetch ? $info[$fetch] : $info;
    }

    /**
     * 获取数据表的字段信息
     * @access public
     * @param string $tableName 数据表名
     * @return array
     */
    public function getTableFieldsInfo(string $tableName): array
    {
        $fields = $this->getFields($tableName);
        $info   = [];

        foreach ($fields as $key => $val) {
            // 记录字段类型
            $info[$key] = $this->getFieldType($val['type']);

            if (!empty($val['primary'])) {
                $pk[] = $key;
            }

            if (!empty($val['autoinc'])) {
                $autoinc = $key;
            }
        }

        if (isset($pk)) {
            // 设置主键
            $pk          = count($pk) > 1 ? $pk : $pk[0];
            $info['_pk'] = $pk;
        }

        if (isset($autoinc)) {
            $info['_autoinc'] = $autoinc;
        }

        return $info;
    }

    /**
     * 获取数据表的主键
     * @access public
     * @param mixed $tableName 数据表名
     * @return string|array
     */
    public function getPk($tableName)
    {
        return $this->getTableInfo($tableName, 'pk');
    }

    /**
     * 获取数据表的自增主键
     * @access public
     * @param mixed $tableName 数据表名
     * @return string
     */
    public function getAutoInc($tableName)
    {
        return $this->getTableInfo($tableName, 'autoinc');
    }

    /**
     * 获取数据表字段信息
     * @access public
     * @param mixed $tableName 数据表名
     * @return array
     */
    public function getTableFields($tableName): array
    {
        return $this->getTableInfo($tableName, 'fields');
    }

    /**
     * 获取数据表字段类型
     * @access public
     * @param mixed  $tableName 数据表名
     * @param string $field     字段名
     * @return array|string
     */
    public function getFieldsType($tableName, string $field = null)
    {
        $result = $this->getTableInfo($tableName, 'type');

        if ($field && isset($result[$field])) {
            return $result[$field];
        }

        return $result;
    }

    /**
     * 获取数据表绑定信息
     * @access public
     * @param mixed $tableName 数据表名
     * @return array
     */
    public function getFieldsBind($tableName): array
    {
        return $this->getTableInfo($tableName, 'bind');
    }

    /**
     * 连接数据库方法
     * @access public
     * @param array      $config         连接参数
     * @param integer    $linkNum        连接序号
     * @param array|bool $autoConnection 是否自动连接主数据库（用于分布式）
     * @return PDO
     * @throws PDOException
     */
    public function connect(array $config = [], $linkNum = 0, $autoConnection = false): PDO
    {
        if (isset($this->links[$linkNum])) {
            return $this->links[$linkNum];
        }

        if (empty($config)) {
            $config = $this->config;
        } else {
            $config = array_merge($this->config, $config);
        }

        // 连接参数
        if (isset($config['params']) && is_array($config['params'])) {
            $params = $config['params'] + $this->params;
        } else {
            $params = $this->params;
        }

        // 记录当前字段属性大小写设置
        $this->attrCase = $params[PDO::ATTR_CASE];

        if (!empty($config['break_match_str'])) {
            $this->breakMatchStr = array_merge($this->breakMatchStr, (array) $config['break_match_str']);
        }

        try {
            if (empty($config['dsn'])) {
                $config['dsn'] = $this->parseDsn($config);
            }

            $startTime = microtime(true);

            $this->links[$linkNum] = $this->createPdo($config['dsn'], $config['username'], $config['password'], $params);

            // SQL监控
            if (!empty($config['trigger_sql'])) {
                $this->trigger('CONNECT:[ UseTime:' . number_format(microtime(true) - $startTime, 6) . 's ] ' . $config['dsn']);
            }

            return $this->links[$linkNum];
        } catch (\PDOException $e) {
            if ($autoConnection) {
                $this->db->log($e->getMessage(), 'error');
                return $this->connect($autoConnection, $linkNum);
            } else {
                throw $e;
            }
        }
    }

    /**
     * 视图查询
     * @access public
     * @param array $args
     * @return BaseQuery
     */
    public function view(...$args)
    {
        return $this->newQuery()->view(...$args);
    }

    /**
     * 创建PDO实例
     * @param $dsn
     * @param $username
     * @param $password
     * @param $params
     * @return PDO
     */
    protected function createPdo($dsn, $username, $password, $params)
    {
        return new PDO($dsn, $username, $password, $params);
    }

    /**
     * 释放查询结果
     * @access public
     */
    public function free(): void
    {
        $this->PDOStatement = null;
    }

    /**
     * 获取PDO对象
     * @access public
     * @return PDO|false
     */
    public function getPdo()
    {
        if (!$this->linkID) {
            return false;
        }

        return $this->linkID;
    }

    /**
     * 执行查询 使用生成器返回数据
     * @access public
     * @param BaseQuery  $query     查询对象
     * @param string     $sql       sql指令
     * @param array      $bind      参数绑定
     * @param Model|null $model     模型对象实例
     * @param null       $condition 查询条件
     * @return \Generator
     * @throws DbException
     */
    public function getCursor(BaseQuery $query, string $sql, array $bind = [], $model = null, $condition = null)
    {
        $this->queryPDOStatement($query, $sql, $bind);

        // 返回结果集
        while ($result = $this->PDOStatement->fetch($this->fetchType)) {
            if ($model) {
                yield $model->newInstance($result, $condition);
            } else {
                yield $result;
            }
        }
    }

    /**
     * 执行查询 返回数据集
     * @access public
     * @param string $sql    sql指令
     * @param array  $bind   参数绑定
     * @param bool   $master 主库读取
     * @return array
     * @throws DbException
     */
    public function query(string $sql, array $bind = [], bool $master = false): array
    {
        return $this->pdoQuery($this->newQuery(), $sql, $bind, $master);
    }

    /**
     * 执行语句
     * @access public
     * @param string $sql  sql指令
     * @param array  $bind 参数绑定
     * @return int
     * @throws DbException
     */
    public function execute(string $sql, array $bind = []): int
    {
        return $this->pdoExecute($this->newQuery(), $sql, $bind, true);
    }

    /**
     * 执行查询 返回数据集
     * @access protected
     * @param BaseQuery $query  查询对象
     * @param mixed     $sql    sql指令
     * @param array     $bind   参数绑定
     * @param bool      $master 主库读取
     * @return array
     * @throws DbException
     */
    protected function pdoQuery(BaseQuery $query, $sql, array $bind = [], bool $master = null): array
    {
        // 分析查询表达式
        $query->parseOptions();

        if ($query->getOptions('cache')) {
            // 检查查询缓存
            $cacheItem = $this->parseCache($query, $query->getOptions('cache'));
            $key       = $cacheItem->getKey();

            $data = $this->cache->get($key);

            if (null !== $data) {
                return $data;
            }
        }

        if ($sql instanceof Closure) {
            $sql  = $sql($query);
            $bind = $query->getBind();
        }

        if (!isset($master)) {
            $master = $query->getOptions('master') ? true : false;
        }

        $procedure = $query->getOptions('procedure') ? true : in_array(strtolower(substr(trim($sql), 0, 4)), ['call', 'exec']);

        $this->getPDOStatement($sql, $bind, $master, $procedure);

        $resultSet    = $this->getResult($procedure);
        $requireCache = $query->getOptions('cache_always') || !empty($resultSet);

        if (isset($cacheItem) && $requireCache) {
            // 缓存数据集
            $cacheItem->set($resultSet);
            $this->cacheData($cacheItem);
        }

        return $resultSet;
    }

    /**
     * 执行查询但只返回PDOStatement对象
     * @access public
     * @param BaseQuery $query 查询对象
     * @return \PDOStatement
     * @throws DbException
     */
    public function pdo(BaseQuery $query): PDOStatement
    {
        $bind = $query->getBind();
        // 生成查询SQL
        $sql = $this->builder->select($query);

        return $this->queryPDOStatement($query, $sql, $bind);
    }

    /**
     * 执行查询但只返回PDOStatement对象
     * @access public
     * @param string $sql       sql指令
     * @param array  $bind      参数绑定
     * @param bool   $master    是否在主服务器读操作
     * @param bool   $procedure 是否为存储过程调用
     * @return PDOStatement
     * @throws DbException
     */
    public function getPDOStatement(string $sql, array $bind = [], bool $master = false, bool $procedure = false): PDOStatement
    {
        try {
            $this->initConnect($this->readMaster ?: $master);
            // 记录SQL语句
            $this->queryStr = $sql;
            $this->bind     = $bind;

            $this->db->updateQueryTimes();
            $this->queryStartTime = microtime(true);

            // 预处理
            $this->PDOStatement = $this->linkID->prepare($sql);

            // 参数绑定
            if ($procedure) {
                $this->bindParam($bind);
            } else {
                $this->bindValue($bind);
            }

            // 执行查询
            $this->PDOStatement->execute();

            // SQL监控
            if (!empty($this->config['trigger_sql'])) {
                $this->trigger('', $master);
            }

            $this->reConnectTimes = 0;

            return $this->PDOStatement;
        } catch (\Throwable | \Exception $e) {
            if ($this->transTimes > 0) {
                // 事务活动中时不应该进行重试，应直接中断执行，防止造成污染。
                if ($this->isBreak($e)) {
                    // 尝试对事务计数进行重置
                    $this->transTimes = 0;
                }
            } else {
                if ($this->reConnectTimes < 4 && $this->isBreak($e)) {
                    ++$this->reConnectTimes;
                    return $this->close()->getPDOStatement($sql, $bind, $master, $procedure);
                }
            }

            if ($e instanceof \PDOException) {
                throw new PDOException($e, $this->config, $this->getLastsql());
            } else {
                throw $e;
            }
        }
    }

    /**
     * 执行语句
     * @access protected
     * @param BaseQuery $query  查询对象
     * @param string    $sql    sql指令
     * @param array     $bind   参数绑定
     * @param bool      $origin 是否原生查询
     * @return int
     * @throws DbException
     */
    protected function pdoExecute(BaseQuery $query, string $sql, array $bind = [], bool $origin = false): int
    {
        if ($origin) {
            $query->parseOptions();
        }

        $this->queryPDOStatement($query->master(true), $sql, $bind);

        if (!$origin && !empty($this->config['deploy']) && !empty($this->config['read_master'])) {
            $this->readMaster = true;
        }

        $this->numRows = $this->PDOStatement->rowCount();

        if ($query->getOptions('cache')) {
            // 清理缓存数据
            $cacheItem = $this->parseCache($query, $query->getOptions('cache'));
            $key       = $cacheItem->getKey();
            $tag       = $cacheItem->getTag();

            if (isset($key) && $this->cache->has($key)) {
                $this->cache->delete($key);
            } elseif (!empty($tag) && method_exists($this->cache, 'tag')) {
                $this->cache->tag($tag)->clear();
            }
        }

        return $this->numRows;
    }

    /**
     * @param BaseQuery $query
     * @param string    $sql
     * @param array     $bind
     * @return PDOStatement
     * @throws DbException
     */
    protected function queryPDOStatement(BaseQuery $query, string $sql, array $bind = []): PDOStatement
    {
        $options   = $query->getOptions();
        $master    = !empty($options['master']) ? true : false;
        $procedure = !empty($options['procedure']) ? true : in_array(strtolower(substr(trim($sql), 0, 4)), ['call', 'exec']);

        return $this->getPDOStatement($sql, $bind, $master, $procedure);
    }

    /**
     * 查找单条记录
     * @access public
     * @param BaseQuery $query 查询对象
     * @return array
     * @throws DbException
     */
    public function find(BaseQuery $query): array
    {
        // 事件回调
        try {
            $this->db->trigger('before_find', $query);
        } catch (DbEventException $e) {
            return [];
        }

        // 执行查询
        $resultSet = $this->pdoQuery($query, function ($query) {
            return $this->builder->select($query, true);
        });

        return $resultSet[0] ?? [];
    }

    /**
     * 使用游标查询记录
     * @access public
     * @param BaseQuery $query 查询对象
     * @return \Generator
     */
    public function cursor(BaseQuery $query)
    {
        // 分析查询表达式
        $options = $query->parseOptions();

        // 生成查询SQL
        $sql = $this->builder->select($query);

        $condition = $options['where']['AND'] ?? null;

        // 执行查询操作
        return $this->getCursor($query, $sql, $query->getBind(), $query->getModel(), $condition);
    }

    /**
     * 查找记录
     * @access public
     * @param BaseQuery $query 查询对象
     * @return array
     * @throws DbException
     */
    public function select(BaseQuery $query): array
    {
        try {
            $this->db->trigger('before_select', $query);
        } catch (DbEventException $e) {
            return [];
        }

        // 执行查询操作
        return $this->pdoQuery($query, function ($query) {
            return $this->builder->select($query);
        });
    }

    /**
     * 插入记录
     * @access public
     * @param BaseQuery $query        查询对象
     * @param boolean   $getLastInsID 返回自增主键
     * @return mixed
     */
    public function insert(BaseQuery $query, bool $getLastInsID = false)
    {
        // 分析查询表达式
        $options = $query->parseOptions();

        // 生成SQL语句
        $sql = $this->builder->insert($query);

        // 执行操作
        $result = '' == $sql ? 0 : $this->pdoExecute($query, $sql, $query->getBind());

        if ($result) {
            $sequence  = $options['sequence'] ?? null;
            $lastInsId = $this->getLastInsID($query, $sequence);

            $data = $options['data'];

            if ($lastInsId) {
                $pk = $query->getAutoInc();
                if ($pk) {
                    $data[$pk] = $lastInsId;
                }
            }

            $query->setOption('data', $data);

            $this->db->trigger('after_insert', $query);

            if ($getLastInsID && $lastInsId) {
                return $lastInsId;
            }
        }

        return $result;
    }

    /**
     * 批量插入记录
     * @access public
     * @param BaseQuery $query   查询对象
     * @param mixed     $dataSet 数据集
     * @param integer   $limit   每次写入数据限制
     * @return integer
     * @throws \Exception
     * @throws \Throwable
     */
    public function insertAll(BaseQuery $query, array $dataSet = [], int $limit = 0): int
    {
        if (!is_array(reset($dataSet))) {
            return 0;
        }

        $options = $query->parseOptions();
        $replace = !empty($options['replace']);

        if (0 === $limit && count($dataSet) >= 5000) {
            $limit = 1000;
        }

        if ($limit) {
            // 分批写入 自动启动事务支持
            $this->startTrans();

            try {
                $array = array_chunk($dataSet, $limit, true);
                $count = 0;

                foreach ($array as $item) {
                    $sql = $this->builder->insertAll($query, $item, $replace);
                    $count += $this->pdoExecute($query, $sql, $query->getBind());
                }

                // 提交事务
                $this->commit();
            } catch (\Exception | \Throwable $e) {
                $this->rollback();
                throw $e;
            }

            return $count;
        }

        $sql = $this->builder->insertAll($query, $dataSet, $replace);

        return $this->pdoExecute($query, $sql, $query->getBind());
    }

    /**
     * 通过Select方式插入记录
     * @access public
     * @param BaseQuery $query  查询对象
     * @param array     $fields 要插入的数据表字段名
     * @param string    $table  要插入的数据表名
     * @return integer
     * @throws PDOException
     */
    public function selectInsert(BaseQuery $query, array $fields, string $table): int
    {
        // 分析查询表达式
        $query->parseOptions();

        $sql = $this->builder->selectInsert($query, $fields, $table);

        return $this->pdoExecute($query, $sql, $query->getBind());
    }

    /**
     * 更新记录
     * @access public
     * @param BaseQuery $query 查询对象
     * @return integer
     * @throws PDOException
     */
    public function update(BaseQuery $query): int
    {
        $query->parseOptions();

        // 生成UPDATE SQL语句
        $sql = $this->builder->update($query);

        // 执行操作
        $result = '' == $sql ? 0 : $this->pdoExecute($query, $sql, $query->getBind());

        if ($result) {
            $this->db->trigger('after_update', $query);
        }

        return $result;
    }

    /**
     * 删除记录
     * @access public
     * @param BaseQuery $query 查询对象
     * @return int
     * @throws PDOException
     */
    public function delete(BaseQuery $query): int
    {
        // 分析查询表达式
        $query->parseOptions();

        // 生成删除SQL语句
        $sql = $this->builder->delete($query);

        // 执行操作
        $result = $this->pdoExecute($query, $sql, $query->getBind());

        if ($result) {
            $this->db->trigger('after_delete', $query);
        }

        return $result;
    }

    /**
     * 得到某个字段的值
     * @access public
     * @param BaseQuery $query   查询对象
     * @param string    $field   字段名
     * @param mixed     $default 默认值
     * @param bool      $one     返回一个值
     * @return mixed
     */
    public function value(BaseQuery $query, string $field, $default = null, bool $one = true)
    {
        $options = $query->parseOptions();

        if (isset($options['field'])) {
            $query->removeOption('field');
        }

        if (isset($options['group'])) {
            $query->group('');
        }

        $query->setOption('field', (array) $field);

        if (!empty($options['cache'])) {
            $cacheItem = $this->parseCache($query, $options['cache'], 'value');
            $key       = $cacheItem->getKey();

            if ($this->cache->has($key)) {
                return $this->cache->get($key);
            }
        }

        // 生成查询SQL
        $sql = $this->builder->select($query, $one);

        if (isset($options['field'])) {
            $query->setOption('field', $options['field']);
        } else {
            $query->removeOption('field');
        }

        if (isset($options['group'])) {
            $query->setOption('group', $options['group']);
        }

        // 执行查询操作
        $pdo = $this->getPDOStatement($sql, $query->getBind(), $options['master']);

        $result = $pdo->fetchColumn();

        if (isset($cacheItem)) {
            // 缓存数据
            $cacheItem->set($result);
            $this->cacheData($cacheItem);
        }

        return false !== $result ? $result : $default;
    }

    /**
     * 得到某个字段的值
     * @access public
     * @param BaseQuery $query     查询对象
     * @param string    $aggregate 聚合方法
     * @param mixed     $field     字段名
     * @param bool      $force     强制转为数字类型
     * @return mixed
     */
    public function aggregate(BaseQuery $query, string $aggregate, $field, bool $force = false)
    {
        if (is_string($field) && 0 === stripos($field, 'DISTINCT ')) {
            [$distinct, $field] = explode(' ', $field);
        }

        $field = $aggregate . '(' . (!empty($distinct) ? 'DISTINCT ' : '') . $this->builder->parseKey($query, $field, true) . ') AS xyh_' . strtolower($aggregate);

        $result = $this->value($query, $field, 0);

        return $force ? (float) $result : $result;
    }

    /**
     * 得到某个列的数组
     * @access public
     * @param BaseQuery     $query  查询对象
     * @param string|array  $column 字段名 多个字段用逗号分隔
     * @param string        $key    索引
     * @return array
     */
    public function column(BaseQuery $query, $column, string $key = ''): array
    {
        $options = $query->parseOptions();

        if (isset($options['field'])) {
            $query->removeOption('field');
        }

        if (empty($key) || trim($key) === '') {
            $key = null;
        }

        if (\is_string($column)) {
            $column = \trim($column);
            if ('*' !== $column) {
                $column = \array_map('\trim', \explode(',', $column));
            }
        } elseif (\is_array($column)) {
            if (\in_array('*', $column)) {
                $column = '*';
            }
        } else {
            throw new DbException('not support type');
        }

        $field = $column;
        if ('*' !== $column && $key && !\in_array($key, $column)) {
            $field[] = $key;
        }

        $query->setOption('field', $field);

        if (!empty($options['cache'])) {
            // 判断查询缓存
            $cacheItem = $this->parseCache($query, $options['cache'], 'column');
            $name      = $cacheItem->getKey();

            if ($this->cache->has($name)) {
                return $this->cache->get($name);
            }
        }

        // 生成查询SQL
        $sql = $this->builder->select($query);

        if (isset($options['field'])) {
            $query->setOption('field', $options['field']);
        } else {
            $query->removeOption('field');
        }

        // 执行查询操作
        $pdo       = $this->getPDOStatement($sql, $query->getBind(), $options['master']);
        $resultSet = $pdo->fetchAll(PDO::FETCH_ASSOC);

        if (is_string($key) && strpos($key, '.')) {
            [$alias, $key] = explode('.', $key);
        }

        if (empty($resultSet)) {
            $result = [];
        } elseif ('*' !== $column && \count($column) === 1) {
            $column = \array_shift($column);
            if (\strpos($column, ' ')) {
                $column = \substr(\strrchr(\trim($column), ' '), 1);
            }

            if (\strpos($column, '.')) {
                [$alias, $column] = \explode('.', $column);
            }

            $result = \array_column($resultSet, $column, $key);
        } elseif ($key) {
            $result = \array_column($resultSet, null, $key);
        } else {
            $result = $resultSet;
        }

        if (isset($cacheItem)) {
            // 缓存数据
            $cacheItem->set($result);
            $this->cacheData($cacheItem);
        }

        return $result;
    }

    /**
     * 根据参数绑定组装最终的SQL语句 便于调试
     * @access public
     * @param string $sql  带参数绑定的sql语句
     * @param array  $bind 参数绑定列表
     * @return string
     */
    public function getRealSql(string $sql, array $bind = []): string
    {
        foreach ($bind as $key => $val) {
            $value = strval(is_array($val) ? $val[0] : $val);
            $type  = is_array($val) ? $val[1] : PDO::PARAM_STR;

            if (self::PARAM_FLOAT == $type || PDO::PARAM_STR == $type) {
                $value = '\'' . addslashes($value) . '\'';
            } elseif (PDO::PARAM_INT == $type && '' === $value) {
                $value = '0';
            }

            // 判断占位符
            $sql = is_numeric($key) ?
            substr_replace($sql, $value, strpos($sql, '?'), 1) :
            substr_replace($sql, $value, strpos($sql, ':' . $key), strlen(':' . $key));
        }

        return rtrim($sql);
    }

    /**
     * 参数绑定
     * 支持 ['name'=>'value','id'=>123] 对应命名占位符
     * 或者 ['value',123] 对应问号占位符
     * @access public
     * @param array $bind 要绑定的参数列表
     * @return void
     * @throws BindParamException
     */
    protected function bindValue(array $bind = []): void
    {
        foreach ($bind as $key => $val) {
            // 占位符
            $param = is_numeric($key) ? $key + 1 : ':' . $key;

            if (is_array($val)) {
                if (PDO::PARAM_INT == $val[1] && '' === $val[0]) {
                    $val[0] = 0;
                } elseif (self::PARAM_FLOAT == $val[1]) {
                    $val[0] = is_string($val[0]) ? (float) $val[0] : $val[0];
                    $val[1] = PDO::PARAM_STR;
                }

                $result = $this->PDOStatement->bindValue($param, $val[0], $val[1]);
            } else {
                $result = $this->PDOStatement->bindValue($param, $val);
            }

            if (!$result) {
                throw new BindParamException(
                    "Error occurred  when binding parameters '{$param}'",
                    $this->config,
                    $this->getLastsql(),
                    $bind
                );
            }
        }
    }

    /**
     * 存储过程的输入输出参数绑定
     * @access public
     * @param array $bind 要绑定的参数列表
     * @return void
     * @throws BindParamException
     */
    protected function bindParam(array $bind): void
    {
        foreach ($bind as $key => $val) {
            $param = is_numeric($key) ? $key + 1 : ':' . $key;

            if (is_array($val)) {
                array_unshift($val, $param);
                $result = call_user_func_array([$this->PDOStatement, 'bindParam'], $val);
            } else {
                $result = $this->PDOStatement->bindValue($param, $val);
            }

            if (!$result) {
                $param = array_shift($val);

                throw new BindParamException(
                    "Error occurred  when binding parameters '{$param}'",
                    $this->config,
                    $this->getLastsql(),
                    $bind
                );
            }
        }
    }

    /**
     * 获得数据集数组
     * @access protected
     * @param bool $procedure 是否存储过程
     * @return array
     */
    protected function getResult(bool $procedure = false): array
    {
        if ($procedure) {
            // 存储过程返回结果
            return $this->procedure();
        }

        $result = $this->PDOStatement->fetchAll($this->fetchType);

        $this->numRows = count($result);

        return $result;
    }

    /**
     * 获得存储过程数据集
     * @access protected
     * @return array
     */
    protected function procedure(): array
    {
        $item = [];

        do {
            $result = $this->getResult();
            if (!empty($result)) {
                $item[] = $result;
            }
        } while ($this->PDOStatement->nextRowset());

        $this->numRows = count($item);

        return $item;
    }

    /**
     * 执行数据库事务
     * @access public
     * @param  callable $callback 数据操作方法回调
     * @return mixed
     * @throws PDOException
     * @throws \Exception
     * @throws \Throwable
     */
    public function transaction(callable $callback)
    {
        $this->startTrans();

        try {
            $result = null;
            if (is_callable($callback)) {
                $result = $callback($this);
            }

            $this->commit();
            return $result;
        } catch (\Exception | \Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * 启动事务
     * @access public
     * @return void
     * @throws \PDOException
     * @throws \Exception
     */
    public function startTrans(): void
    {
        try {
            $this->initConnect(true);

            ++$this->transTimes;

            if (1 == $this->transTimes) {
                $this->linkID->beginTransaction();
            } elseif ($this->transTimes > 1 && $this->supportSavepoint() && $this->linkID->inTransaction()) {
                $this->linkID->exec(
                    $this->parseSavepoint('trans' . $this->transTimes)
                );
            }
            $this->reConnectTimes = 0;
        } catch (\Throwable | \Exception $e) {
            if (1 === $this->transTimes && $this->reConnectTimes < 4 && $this->isBreak($e)) {
                --$this->transTimes;
                ++$this->reConnectTimes;
                $this->close()->startTrans();
            } else {
                if ($this->isBreak($e)) {
                    // 尝试对事务计数进行重置
                    $this->transTimes = 0;
                }
                throw $e;
            }
        }
    }

    /**
     * 用于非自动提交状态下面的查询提交
     * @access public
     * @return void
     * @throws \PDOException
     */
    public function commit(): void
    {
        $this->initConnect(true);

        if (1 == $this->transTimes && $this->linkID->inTransaction()) {
            $this->linkID->commit();
        }

        --$this->transTimes;
    }

    /**
     * 事务回滚
     * @access public
     * @return void
     * @throws \PDOException
     */
    public function rollback(): void
    {
        $this->initConnect(true);

        if ($this->linkID->inTransaction()) {
            if (1 == $this->transTimes) {
                $this->linkID->rollBack();
            } elseif ($this->transTimes > 1 && $this->supportSavepoint()) {
                $this->linkID->exec(
                    $this->parseSavepointRollBack('trans' . $this->transTimes)
                );
            }
        }

        $this->transTimes = max(0, $this->transTimes - 1);
    }

    /**
     * 是否支持事务嵌套
     * @return bool
     */
    protected function supportSavepoint(): bool
    {
        return false;
    }

    /**
     * 生成定义保存点的SQL
     * @access protected
     * @param string $name 标识
     * @return string
     */
    protected function parseSavepoint(string $name): string
    {
        return 'SAVEPOINT ' . $name;
    }

    /**
     * 生成回滚到保存点的SQL
     * @access protected
     * @param string $name 标识
     * @return string
     */
    protected function parseSavepointRollBack(string $name): string
    {
        return 'ROLLBACK TO SAVEPOINT ' . $name;
    }

    /**
     * 批处理执行SQL语句
     * 批处理的指令都认为是execute操作
     * @access public
     * @param BaseQuery $query    查询对象
     * @param array     $sqlArray SQL批处理指令
     * @param array     $bind     参数绑定
     * @return bool
     */
    public function batchQuery(BaseQuery $query, array $sqlArray = [], array $bind = []): bool
    {
        // 自动启动事务支持
        $this->startTrans();

        try {
            foreach ($sqlArray as $sql) {
                $this->pdoExecute($query, $sql, $bind);
            }
            // 提交事务
            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }

        return true;
    }

    /**
     * 关闭数据库（或者重新连接）
     * @access public
     * @return $this
     */
    public function close()
    {
        $this->linkID     = null;
        $this->linkWrite  = null;
        $this->linkRead   = null;
        $this->links      = [];
        $this->transTimes = 0;

        $this->free();

        return $this;
    }

    /**
     * 是否断线
     * @access protected
     * @param \PDOException|\Exception $e 异常对象
     * @return bool
     */
    protected function isBreak($e): bool
    {
        if (!$this->config['break_reconnect']) {
            return false;
        }

        $error = $e->getMessage();

        foreach ($this->breakMatchStr as $msg) {
            if (false !== stripos($error, $msg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取最近一次查询的sql语句
     * @access public
     * @return string
     */
    public function getLastSql(): string
    {
        return $this->getRealSql($this->queryStr, $this->bind);
    }

    /**
     * 获取最近插入的ID
     * @access public
     * @param BaseQuery $query    查询对象
     * @param string    $sequence 自增序列名
     * @return mixed
     */
    public function getLastInsID(BaseQuery $query, string $sequence = null)
    {
        try {
            $insertId = $this->linkID->lastInsertId($sequence);
        } catch (\Exception $e) {
            $insertId = '';
        }

        return $this->autoInsIDType($query, $insertId);
    }

    /**
     * 获取最近插入的ID
     * @access public
     * @param BaseQuery $query    查询对象
     * @param string    $insertId 自增ID
     * @return mixed
     */
    protected function autoInsIDType(BaseQuery $query, string $insertId)
    {
        $pk = $query->getAutoInc();

        if ($pk) {
            $type = $this->getFieldBindType($pk);

            if (PDO::PARAM_INT == $type) {
                $insertId = (int) $insertId;
            } elseif (self::PARAM_FLOAT == $type) {
                $insertId = (float) $insertId;
            }
        }

        return $insertId;
    }

    /**
     * 获取最近的错误信息
     * @access public
     * @return string
     */
    public function getError(): string
    {
        if ($this->PDOStatement) {
            $error = $this->PDOStatement->errorInfo();
            $error = $error[1] . ':' . $error[2];
        } else {
            $error = '';
        }

        if ('' != $this->queryStr) {
            $error .= "\n [ SQL语句 ] : " . $this->getLastsql();
        }

        return $error;
    }

    /**
     * 初始化数据库连接
     * @access protected
     * @param boolean $master 是否主服务器
     * @return void
     */
    protected function initConnect(bool $master = true): void
    {
        if (!empty($this->config['deploy'])) {
            // 采用分布式数据库
            if ($master || $this->transTimes) {
                if (!$this->linkWrite) {
                    $this->linkWrite = $this->multiConnect(true);
                }

                $this->linkID = $this->linkWrite;
            } else {
                if (!$this->linkRead) {
                    $this->linkRead = $this->multiConnect(false);
                }

                $this->linkID = $this->linkRead;
            }
        } elseif (!$this->linkID) {
            // 默认单数据库
            $this->linkID = $this->connect();
        }
    }

    /**
     * 连接分布式服务器
     * @access protected
     * @param boolean $master 主服务器
     * @return PDO
     */
    protected function multiConnect(bool $master = false): PDO
    {
        $config = [];

        // 分布式数据库配置解析
        foreach (['username', 'password', 'hostname', 'hostport', 'database', 'dsn', 'charset'] as $name) {
            $config[$name] = is_string($this->config[$name]) ? explode(',', $this->config[$name]) : $this->config[$name];
        }

        // 主服务器序号
        $m = floor(mt_rand(0, $this->config['master_num'] - 1));

        if ($this->config['rw_separate']) {
            // 主从式采用读写分离
            if ($master) // 主服务器写入
            {
                $r = $m;
            } elseif (is_numeric($this->config['slave_no'])) {
                // 指定服务器读
                $r = $this->config['slave_no'];
            } else {
                // 读操作连接从服务器 每次随机连接的数据库
                $r = floor(mt_rand($this->config['master_num'], count($config['hostname']) - 1));
            }
        } else {
            // 读写操作不区分服务器 每次随机连接的数据库
            $r = floor(mt_rand(0, count($config['hostname']) - 1));
        }
        $dbMaster = false;

        if ($m != $r) {
            $dbMaster = [];
            foreach (['username', 'password', 'hostname', 'hostport', 'database', 'dsn', 'charset'] as $name) {
                $dbMaster[$name] = $config[$name][$m] ?? $config[$name][0];
            }
        }

        $dbConfig = [];

        foreach (['username', 'password', 'hostname', 'hostport', 'database', 'dsn', 'charset'] as $name) {
            $dbConfig[$name] = $config[$name][$r] ?? $config[$name][0];
        }

        return $this->connect($dbConfig, $r, $r == $m ? false : $dbMaster);
    }

    /**
     * 执行数据库Xa事务
     * @access public
     * @param  callable $callback 数据操作方法回调
     * @param  array    $dbs      多个查询对象或者连接对象
     * @return mixed
     * @throws PDOException
     * @throws \Exception
     * @throws \Throwable
     */
    public function transactionXa(callable $callback, array $dbs = [])
    {
        $xid = uniqid('xa');

        if (empty($dbs)) {
            $dbs[] = $this;
        }

        foreach ($dbs as $key => $db) {
            if ($db instanceof BaseQuery) {
                $db = $db->getConnection();

                $dbs[$key] = $db;
            }

            $db->startTransXa($xid);
        }

        try {
            $result = null;
            if (is_callable($callback)) {
                $result = $callback($this);
            }

            foreach ($dbs as $db) {
                $db->prepareXa($xid);
            }

            foreach ($dbs as $db) {
                $db->commitXa($xid);
            }

            return $result;
        } catch (\Exception | \Throwable $e) {
            foreach ($dbs as $db) {
                $db->rollbackXa($xid);
            }
            throw $e;
        }
    }

    /**
     * 启动XA事务
     * @access public
     * @param  string $xid XA事务id
     * @return void
     */
    public function startTransXa(string $xid): void
    {}

    /**
     * 预编译XA事务
     * @access public
     * @param  string $xid XA事务id
     * @return void
     */
    public function prepareXa(string $xid): void
    {}

    /**
     * 提交XA事务
     * @access public
     * @param  string $xid XA事务id
     * @return void
     */
    public function commitXa(string $xid): void
    {}

    /**
     * 回滚XA事务
     * @access public
     * @param  string $xid XA事务id
     * @return void
     */
    public function rollbackXa(string $xid): void
    {}
}
