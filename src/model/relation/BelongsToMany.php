<?php


namespace xyh\model\relation;

use Closure;
use xyh\Collection;
use xyh\db\BaseQuery as Query;
use xyh\db\exception\DbException as Exception;
use xyh\db\Raw;
use xyh\Model;
use xyh\model\Pivot;
use xyh\model\Relation;

/**
 * 多对多关联类
 */
class BelongsToMany extends Relation
{
    /**
     * 中间表表名
     * @var string
     */
    protected $middle;

    /**
     * 中间表模型名称
     * @var string
     */
    protected $pivotName;

    /**
     * 中间表模型对象
     * @var Pivot
     */
    protected $pivot;

    /**
     * 中间表数据名称
     * @var string
     */
    protected $pivotDataName = 'pivot';

    /**
     * 架构函数
     * @access public
     * @param  Model  $parent     上级模型对象
     * @param  string $model      模型名
     * @param  string $middle     中间表/模型名
     * @param  string $foreignKey 关联模型外键
     * @param  string $localKey   当前模型关联键
     */
    public function __construct(Model $parent, string $model, string $middle, string $foreignKey, string $localKey)
    {
        $this->parent     = $parent;
        $this->model      = $model;
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;

        if (false !== strpos($middle, '\\')) {
            $this->pivotName = $middle;
            $this->middle    = class_basename($middle);
        } else {
            $this->middle = $middle;
        }

        $this->query = (new $model)->db();
        $this->pivot = $this->newPivot();
    }

    /**
     * 设置中间表模型
     * @access public
     * @param  $pivot
     * @return $this
     */
    public function pivot(string $pivot)
    {
        $this->pivotName = $pivot;
        return $this;
    }

    /**
     * 设置中间表数据名称
     * @access public
     * @param  string $name
     * @return $this
     */
    public function name(string $name)
    {
        $this->pivotDataName = $name;
        return $this;
    }

    /**
     * 实例化中间表模型
     * @access public
     * @param  $data
     * @return Pivot
     * @throws Exception
     */
    protected function newPivot(array $data = []): Pivot
    {
        $class = $this->pivotName ?: Pivot::class;
        $pivot = new $class($data, $this->parent, $this->middle);

        if ($pivot instanceof Pivot) {
            return $pivot;
        } else {
            throw new Exception('pivot model must extends: \xyh\model\Pivot');
        }
    }

    /**
     * 延迟获取关联数据
     * @access public
     * @param  array    $subRelation 子关联名
     * @param  Closure  $closure     闭包查询条件
     * @return Collection
     */
    public function getRelation(array $subRelation = [], Closure $closure = null): Collection
    {
        if ($closure) {
            $closure($this->getClosureType($closure));
        }

        return $this->relation($subRelation)
            ->select()
            ->setParent(clone $this->parent);
    }

    /**
     * 组装Pivot模型
     * @access public
     * @param  Model    $result 模型对象
     * @return array
     */
    protected function matchPivot(Model $result): array
    {
        $pivot = [];
        foreach ($result->getData() as $key => $val) {
            if (strpos($key, '__')) {
                [$name, $attr] = explode('__', $key, 2);

                if ('pivot' == $name) {
                    $pivot[$attr] = $val;
                    unset($result->$key);
                }
            }
        }

        $pivotData = $this->pivot->newInstance($pivot, [
            [$this->localKey, '=', $this->parent->getKey(), null],
            [$this->foreignKey, '=', $result->getKey(), null],
        ]);

        $result->setRelation($this->pivotDataName, $pivotData);
        return $pivot;
    }

    /**
     * 根据关联条件查询当前模型
     * @access public
     * @param  string  $operator 比较操作符
     * @param  integer $count    个数
     * @param  string  $id       关联表的统计字段
     * @param  string  $joinType JOIN类型
     * @param  Query   $query    Query对象
     * @return Model
     */
    public function has(string $operator = '>=', $count = 1, $id = '*', string $joinType = 'INNER', Query $query = null)
    {
        return $this->parent;
    }

    /**
     * 根据关联条件查询当前模型
     * @access public
     * @param  mixed  $where 查询条件（数组或者闭包）
     * @param  mixed  $fields 字段
     * @param  string $joinType JOIN类型
     * @param  Query  $query    Query对象
     * @return Query
     * @throws Exception
     */
    public function hasWhere($where = [], $fields = null, string $joinType = '', Query $query = null)
    {
        throw new Exception('relation not support: hasWhere');
    }

    /**
     * 设置中间表的查询条件
     * @access public
     * @param  string $field
     * @param  string $op
     * @param  mixed  $condition
     * @return $this
     */
    public function wherePivot($field, $op = null, $condition = null)
    {
        $this->query->where('pivot.' . $field, $op, $condition);
        return $this;
    }

    /**
     * 预载入关联查询（数据集）
     * @access public
     * @param  array   $resultSet   数据集
     * @param  string  $relation    当前关联名
     * @param  array   $subRelation 子关联名
     * @param  Closure $closure     闭包
     * @param  array   $cache       关联缓存
     * @return void
     */
    public function eagerlyResultSet(array &$resultSet, string $relation, array $subRelation, Closure $closure = null, array $cache = []): void
    {
        $localKey = $this->localKey;
        $pk       = $resultSet[0]->getPk();
        $range    = [];

        foreach ($resultSet as $result) {
            // 获取关联外键列表
            if (isset($result->$pk)) {
                $range[] = $result->$pk;
            }
        }

        if (!empty($range)) {
            // 查询关联数据
            $data = $this->eagerlyManyToMany([
                ['pivot.' . $localKey, 'in', $range],
            ], $subRelation, $closure, $cache);

            // 关联数据封装
            foreach ($resultSet as $result) {
                if (!isset($data[$result->$pk])) {
                    $data[$result->$pk] = [];
                }

                $result->setRelation($relation, $this->resultSetBuild($data[$result->$pk], clone $this->parent));
            }
        }
    }

    /**
     * 预载入关联查询（单个数据）
     * @access public
     * @param  Model   $result      数据对象
     * @param  string  $relation    当前关联名
     * @param  array   $subRelation 子关联名
     * @param  Closure $closure     闭包
     * @param  array   $cache       关联缓存
     * @return void
     */
    public function eagerlyResult(Model $result, string $relation, array $subRelation, Closure $closure = null, array $cache = []): void
    {
        $pk = $result->getPk();

        if (isset($result->$pk)) {
            $pk = $result->$pk;
            // 查询管理数据
            $data = $this->eagerlyManyToMany([
                ['pivot.' . $this->localKey, '=', $pk],
            ], $subRelation, $closure, $cache);

            // 关联数据封装
            if (!isset($data[$pk])) {
                $data[$pk] = [];
            }

            $result->setRelation($relation, $this->resultSetBuild($data[$pk], clone $this->parent));
        }
    }

    /**
     * 关联统计
     * @access public
     * @param  Model   $result  数据对象
     * @param  Closure $closure 闭包
     * @param  string  $aggregate 聚合查询方法
     * @param  string  $field 字段
     * @param  string  $name 统计字段别名
     * @return integer
     */
    public function relationCount(Model $result, Closure $closure = null, string $aggregate = 'count', string $field = '*', string &$name = null): float
    {
        $pk = $result->getPk();

        if (!isset($result->$pk)) {
            return 0;
        }

        $pk = $result->$pk;

        if ($closure) {
            $closure($this->getClosureType($closure), $name);
        }

        return $this->belongsToManyQuery($this->foreignKey, $this->localKey, [
            ['pivot.' . $this->localKey, '=', $pk],
        ])->$aggregate($field);
    }

    /**
     * 获取关联统计子查询
     * @access public
     * @param  Closure $closure 闭包
     * @param  string  $aggregate 聚合查询方法
     * @param  string  $field 字段
     * @param  string  $name 统计字段别名
     * @return string
     */
    public function getRelationCountQuery(Closure $closure = null, string $aggregate = 'count', string $field = '*', string &$name = null): string
    {
        if ($closure) {
            $closure($this->getClosureType($closure), $name);
        }

        return $this->belongsToManyQuery($this->foreignKey, $this->localKey, [
            [
                'pivot.' . $this->localKey, 'exp', new Raw('=' . $this->parent->db(false)->getTable() . '.' . $this->parent->getPk()),
            ],
        ])->fetchSql()->$aggregate($field);
    }

    /**
     * 多对多 关联模型预查询
     * @access protected
     * @param  array   $where       关联预查询条件
     * @param  array   $subRelation 子关联
     * @param  Closure $closure     闭包
     * @param  array   $cache       关联缓存
     * @return array
     */
    protected function eagerlyManyToMany(array $where, array $subRelation = [], Closure $closure = null, array $cache = []): array
    {
        if ($closure) {
            $closure($this->getClosureType($closure));
        }

        // 预载入关联查询 支持嵌套预载入
        $list = $this->belongsToManyQuery($this->foreignKey, $this->localKey, $where)
            ->with($subRelation)
            ->cache($cache[0] ?? false, $cache[1] ?? null, $cache[2] ?? null)
            ->select();

        // 组装模型数据
        $data = [];
        foreach ($list as $set) {
            $pivot = $this->matchPivot($set);
            $key   = $pivot[$this->localKey];

            if ($this->withLimit && isset($data[$key]) && count($data[$key]) >= $this->withLimit) {
                continue;
            }

            $data[$key][] = $set;
        }

        return $data;
    }

    /**
     * BELONGS TO MANY 关联查询
     * @access protected
     * @param  string $foreignKey 关联模型关联键
     * @param  string $localKey   当前模型关联键
     * @param  array  $condition  关联查询条件
     * @return Query
     */
    protected function belongsToManyQuery(string $foreignKey, string $localKey, array $condition = []): Query
    {
        // 关联查询封装
        if (empty($this->baseQuery)) {
            $tableName = $this->query->getTable();
            $table     = $this->pivot->db()->getTable();

            if ($this->withoutField) {
                $this->query->withoutField($this->withoutField);
            }

            $fields = $this->getQueryFields($tableName);

            if ($this->withLimit) {
                $this->query->limit($this->withLimit);
            }

            $this->query
                ->field($fields)
                ->tableField(true, $table, 'pivot', 'pivot__')
                ->join([$table => 'pivot'], 'pivot.' . $foreignKey . '=' . $tableName . '.' . $this->query->getPk())
                ->where($condition);

        }

        return $this->query;
    }

    /**
     * 保存（新增）当前关联数据对象
     * @access public
     * @param  mixed $data  数据 可以使用数组 关联模型对象 和 关联对象的主键
     * @param  array $pivot 中间表额外数据
     * @return array|Pivot
     */
    public function save($data, array $pivot = [])
    {
        // 保存关联表/中间表数据
        return $this->attach($data, $pivot);
    }

    /**
     * 批量保存当前关联数据对象
     * @access public
     * @param  iterable $dataSet   数据集
     * @param  array    $pivot     中间表额外数据
     * @param  bool     $samePivot 额外数据是否相同
     * @return array|false
     */
    public function saveAll(iterable $dataSet, array $pivot = [], bool $samePivot = false)
    {
        $result = [];

        foreach ($dataSet as $key => $data) {
            if (!$samePivot) {
                $pivotData = $pivot[$key] ?? [];
            } else {
                $pivotData = $pivot;
            }

            $result[] = $this->attach($data, $pivotData);
        }

        return empty($result) ? false : $result;
    }

    /**
     * 附加关联的一个中间表数据
     * @access public
     * @param  mixed $data  数据 可以使用数组、关联模型对象 或者 关联对象的主键
     * @param  array $pivot 中间表额外数据
     * @return array|Pivot
     * @throws Exception
     */
    public function attach($data, array $pivot = [])
    {
        if (is_array($data)) {
            if (key($data) === 0) {
                $id = $data;
            } else {
                // 保存关联表数据
                $model = new $this->model;
                $id    = $model->insertGetId($data);
            }
        } elseif (is_numeric($data) || is_string($data)) {
            // 根据关联表主键直接写入中间表
            $id = $data;
        } elseif ($data instanceof Model) {
            // 根据关联表主键直接写入中间表
            $id = $data->getKey();
        }

        if (!empty($id)) {
            // 保存中间表数据
            $pivot[$this->localKey] = $this->parent->getKey();

            $ids = (array) $id;
            foreach ($ids as $id) {
                $pivot[$this->foreignKey] = $id;
                $this->pivot->replace()
                    ->exists(false)
                    ->data([])
                    ->save($pivot);
                $result[] = $this->newPivot($pivot);
            }

            if (count($result) == 1) {
                // 返回中间表模型对象
                $result = $result[0];
            }

            return $result;
        } else {
            throw new Exception('miss relation data');
        }
    }

    /**
     * 判断是否存在关联数据
     * @access public
     * @param  mixed $data 数据 可以使用关联模型对象 或者 关联对象的主键
     * @return Pivot|false
     */
    public function attached($data)
    {
        if ($data instanceof Model) {
            $id = $data->getKey();
        } else {
            $id = $data;
        }

        $pivot = $this->pivot
            ->where($this->localKey, $this->parent->getKey())
            ->where($this->foreignKey, $id)
            ->find();

        return $pivot ?: false;
    }

    /**
     * 解除关联的一个中间表数据
     * @access public
     * @param  integer|array $data        数据 可以使用关联对象的主键
     * @param  bool          $relationDel 是否同时删除关联表数据
     * @return integer
     */
    public function detach($data = null, bool $relationDel = false): int
    {
        if (is_array($data)) {
            $id = $data;
        } elseif (is_numeric($data) || is_string($data)) {
            // 根据关联表主键直接写入中间表
            $id = $data;
        } elseif ($data instanceof Model) {
            // 根据关联表主键直接写入中间表
            $id = $data->getKey();
        }

        // 删除中间表数据
        $pivot   = [];
        $pivot[] = [$this->localKey, '=', $this->parent->getKey()];

        if (isset($id)) {
            $pivot[] = [$this->foreignKey, is_array($id) ? 'in' : '=', $id];
        }

        $result = $this->pivot->where($pivot)->delete();

        // 删除关联表数据
        if (isset($id) && $relationDel) {
            $model = $this->model;
            $model::destroy($id);
        }

        return $result;
    }

    /**
     * 数据同步
     * @access public
     * @param  array $ids
     * @param  bool  $detaching
     * @return array
     */
    public function sync(array $ids, bool $detaching = true): array
    {
        $changes = [
            'attached' => [],
            'detached' => [],
            'updated'  => [],
        ];

        $current = $this->pivot
            ->where($this->localKey, $this->parent->getKey())
            ->column($this->foreignKey);

        $records = [];

        foreach ($ids as $key => $value) {
            if (!is_array($value)) {
                $records[$value] = [];
            } else {
                $records[$key] = $value;
            }
        }

        $detach = array_diff($current, array_keys($records));

        if ($detaching && count($detach) > 0) {
            $this->detach($detach);
            $changes['detached'] = $detach;
        }

        foreach ($records as $id => $attributes) {
            if (!in_array($id, $current)) {
                $this->attach($id, $attributes);
                $changes['attached'][] = $id;
            } elseif (count($attributes) > 0 && $this->attach($id, $attributes)) {
                $changes['updated'][] = $id;
            }
        }

        return $changes;
    }

    /**
     * 执行基础查询（仅执行一次）
     * @access protected
     * @return void
     */
    protected function baseQuery(): void
    {
        if (empty($this->baseQuery)) {
            $foreignKey = $this->foreignKey;
            $localKey   = $this->localKey;

            $this->query->filter(function ($result, $options) {
                $this->matchPivot($result);
            });

            // 关联查询
            if (null === $this->parent->getKey()) {
                $condition = ['pivot.' . $localKey, 'exp', new Raw('=' . $this->parent->getTable() . '.' . $this->parent->getPk())];
            } else {
                $condition = ['pivot.' . $localKey, '=', $this->parent->getKey()];
            }

            $this->belongsToManyQuery($foreignKey, $localKey, [$condition]);

            $this->baseQuery = true;
        }
    }

}
