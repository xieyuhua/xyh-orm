<?php

declare (strict_types = 1);

namespace xyh\model\concern;

use Closure;
use xyh\Collection;
use xyh\db\BaseQuery as Query;
use xyh\db\exception\DbException as Exception;
use xyh\helper\Str;
use xyh\Model;
use xyh\model\Relation;
use xyh\model\relation\BelongsTo;
use xyh\model\relation\BelongsToMany;
use xyh\model\relation\HasMany;
use xyh\model\relation\HasManyThrough;
use xyh\model\relation\HasOne;
use xyh\model\relation\HasOneThrough;
use xyh\model\relation\MorphMany;
use xyh\model\relation\MorphOne;
use xyh\model\relation\MorphTo;
use xyh\model\relation\MorphToMany;
use xyh\model\relation\OneToOne;

/**
 * 模型关联处理
 */
trait RelationShip
{
    /**
     * 父关联模型对象
     * @var object
     */
    private $parent;

    /**
     * 模型关联数据
     * @var array
     */
    private $relation = [];

    /**
     * 关联写入定义信息
     * @var array
     */
    private $together = [];

    /**
     * 关联自动写入信息
     * @var array
     */
    protected $relationWrite = [];

    /**
     * 设置父关联对象
     * @access public
     * @param  Model $model  模型对象
     * @return $this
     */
    public function setParent(Model $model)
    {
        $this->parent = $model;

        return $this;
    }

    /**
     * 获取父关联对象
     * @access public
     * @return Model
     */
    public function getParent(): Model
    {
        return $this->parent;
    }

    /**
     * 获取当前模型的关联模型数据
     * @access public
     * @param  string $name 关联方法名
     * @param  bool   $auto 不存在是否自动获取
     * @return mixed
     */
    public function getRelation(string $name = null, bool $auto = false)
    {
        if (is_null($name)) {
            return $this->relation;
        }

        if (array_key_exists($name, $this->relation)) {
            return $this->relation[$name];
        } elseif ($auto) {
            $relation = Str::camel($name);
            return $this->getRelationValue($relation);
        }
    }

    /**
     * 设置关联数据对象值
     * @access public
     * @param  string $name  属性名
     * @param  mixed  $value 属性值
     * @param  array  $data  数据
     * @return $this
     */
    public function setRelation(string $name, $value, array $data = [])
    {
        // 检测修改器
        $method = 'set' . Str::studly($name) . 'Attr';

        if (method_exists($this, $method)) {
            $value = $this->$method($value, array_merge($this->data, $data));
        }

        $this->relation[$this->getRealFieldName($name)] = $value;

        return $this;
    }

    /**
     * 查询当前模型的关联数据
     * @access public
     * @param  array $relations 关联名
     * @param  array $withRelationAttr   关联获取器
     * @return void
     */
    public function relationQuery(array $relations, array $withRelationAttr = []): void
    {
        foreach ($relations as $key => $relation) {
            $subRelation = [];
            $closure     = null;

            if ($relation instanceof Closure) {
                // 支持闭包查询过滤关联条件
                $closure  = $relation;
                $relation = $key;
            }

            if (is_array($relation)) {
                $subRelation = $relation;
                $relation    = $key;
            } elseif (strpos($relation, '.')) {
                [$relation, $subRelation] = explode('.', $relation, 2);
            }

            $method       = Str::camel($relation);
            $relationName = Str::snake($relation);

            $relationResult = $this->$method();

            if (isset($withRelationAttr[$relationName])) {
                $relationResult->withAttr($withRelationAttr[$relationName]);
            }

            $this->relation[$relation] = $relationResult->getRelation((array) $subRelation, $closure);
        }
    }

    /**
     * 关联数据写入
     * @access public
     * @param  array $relation 关联
     * @return $this
     */
    public function together(array $relation)
    {
        $this->together = $relation;

        $this->checkAutoRelationWrite();

        return $this;
    }

    /**
     * 根据关联条件查询当前模型
     * @access public
     * @param  string  $relation 关联方法名
     * @param  mixed   $operator 比较操作符
     * @param  integer $count    个数
     * @param  string  $id       关联表的统计字段
     * @param  string  $joinType JOIN类型
     * @param  Query   $query    Query对象
     * @return Query
     */
    public static function has(string $relation, string $operator = '>=', int $count = 1, string $id = '*', string $joinType = '', Query $query = null): Query
    {
        return (new static())
            ->$relation()
            ->has($operator, $count, $id, $joinType, $query);
    }

    /**
     * 根据关联条件查询当前模型
     * @access public
     * @param  string $relation 关联方法名
     * @param  mixed  $where    查询条件（数组或者闭包）
     * @param  mixed  $fields   字段
     * @param  string $joinType JOIN类型
     * @param  Query  $query    Query对象
     * @return Query
     */
    public static function hasWhere(string $relation, $where = [], string $fields = '*', string $joinType = '', Query $query = null): Query
    {
        return (new static())
            ->$relation()
            ->hasWhere($where, $fields, $joinType, $query);
    }

    /**
     * 预载入关联查询 JOIN方式
     * @access public
     * @param  Query   $query    Query对象
     * @param  string  $relation 关联方法名
     * @param  mixed   $field    字段
     * @param  string  $joinType JOIN类型
     * @param  Closure $closure  闭包
     * @param  bool    $first
     * @return bool
     */
    public function eagerly(Query $query, string $relation, $field, string $joinType = '', Closure $closure = null, bool $first = false): bool
    {
        $relation = Str::camel($relation);
        $class    = $this->$relation();

        if ($class instanceof OneToOne) {
            $class->eagerly($query, $relation, $field, $joinType, $closure, $first);
            return true;
        } else {
            return false;
        }
    }

    /**
     * 预载入关联查询 返回数据集
     * @access public
     * @param  array  $resultSet 数据集
     * @param  string $relation  关联名
     * @param  array  $withRelationAttr 关联获取器
     * @param  bool   $join      是否为JOIN方式
     * @param  mixed  $cache     关联缓存
     * @return void
     */
    public function eagerlyResultSet(array &$resultSet, array $relations, array $withRelationAttr = [], bool $join = false, $cache = false): void
    {
        foreach ($relations as $key => $relation) {
            $subRelation = [];
            $closure     = null;

            if ($relation instanceof Closure) {
                $closure  = $relation;
                $relation = $key;
            }

            if (is_array($relation)) {
                $subRelation = $relation;
                $relation    = $key;
            } elseif (strpos($relation, '.')) {
                [$relation, $subRelation] = explode('.', $relation, 2);

                $subRelation = [$subRelation];
            }

            $relationName = $relation;
            $relation     = Str::camel($relation);

            $relationResult = $this->$relation();

            if (isset($withRelationAttr[$relationName])) {
                $relationResult->withAttr($withRelationAttr[$relationName]);
            }

            if (is_scalar($cache)) {
                $relationCache = [$cache];
            } else {
                $relationCache = $cache[$relationName] ?? $cache;
            }

            $relationResult->eagerlyResultSet($resultSet, $relationName, $subRelation, $closure, $relationCache, $join);
        }
    }

    /**
     * 预载入关联查询 返回模型对象
     * @access public
     * @param  array $relations 关联
     * @param  array $withRelationAttr 关联获取器
     * @param  bool  $join      是否为JOIN方式
     * @param  mixed $cache     关联缓存
     * @return void
     */
    public function eagerlyResult(array $relations, array $withRelationAttr = [], bool $join = false, $cache = false): void
    {
        foreach ($relations as $key => $relation) {
            $subRelation = [];
            $closure     = null;

            if ($relation instanceof Closure) {
                $closure  = $relation;
                $relation = $key;
            }

            if (is_array($relation)) {
                $subRelation = $relation;
                $relation    = $key;
            } elseif (strpos($relation, '.')) {
                [$relation, $subRelation] = explode('.', $relation, 2);

                $subRelation = [$subRelation];
            }

            $relationName = $relation;
            $relation     = Str::camel($relation);

            $relationResult = $this->$relation();

            if (isset($withRelationAttr[$relationName])) {
                $relationResult->withAttr($withRelationAttr[$relationName]);
            }

            if (is_scalar($cache)) {
                $relationCache = [$cache];
            } else {
                $relationCache = $cache[$relationName] ?? [];
            }

            $relationResult->eagerlyResult($this, $relationName, $subRelation, $closure, $relationCache, $join);
        }
    }

    /**
     * 绑定（一对一）关联属性到当前模型
     * @access protected
     * @param  string   $relation    关联名称
     * @param  array    $attrs       绑定属性
     * @return $this
     * @throws Exception
     */
    public function bindAttr(string $relation, array $attrs = [])
    {
        $relation = $this->getRelation($relation);

        foreach ($attrs as $key => $attr) {
            $key   = is_numeric($key) ? $attr : $key;
            $value = $this->getOrigin($key);

            if (!is_null($value)) {
                throw new Exception('bind attr has exists:' . $key);
            }

            $this->set($key, $relation ? $relation->$attr : null);
        }

        return $this;
    }

    /**
     * 关联统计
     * @access public
     * @param  Query  $query       查询对象
     * @param  array  $relations   关联名
     * @param  string $aggregate   聚合查询方法
     * @param  string $field       字段
     * @param  bool   $useSubQuery 子查询
     * @return void
     */
    public function relationCount(Query $query, array $relations, string $aggregate = 'sum', string $field = '*', bool $useSubQuery = true): void
    {
        foreach ($relations as $key => $relation) {
            $closure = $name = null;

            if ($relation instanceof Closure) {
                $closure  = $relation;
                $relation = $key;
            } elseif (is_string($key)) {
                $name     = $relation;
                $relation = $key;
            }

            $relation = Str::camel($relation);

            if ($useSubQuery) {
                $count = $this->$relation()->getRelationCountQuery($closure, $aggregate, $field, $name);
            } else {
                $count = $this->$relation()->relationCount($this, $closure, $aggregate, $field, $name);
            }

            if (empty($name)) {
                $name = Str::snake($relation) . '_' . $aggregate;
            }

            if ($useSubQuery) {
                $query->field(['(' . $count . ')' => $name]);
            } else {
                $this->setAttr($name, $count);
            }
        }
    }

    /**
     * HAS ONE 关联定义
     * @access public
     * @param  string $model      模型名
     * @param  string $foreignKey 关联外键
     * @param  string $localKey   当前主键
     * @return HasOne
     */
    public function hasOne(string $model, string $foreignKey = '', string $localKey = ''): HasOne
    {
        // 记录当前关联信息
        $model      = $this->parseModel($model);
        $localKey   = $localKey ?: $this->getPk();
        $foreignKey = $foreignKey ?: $this->getForeignKey($this->name);

        return new HasOne($this, $model, $foreignKey, $localKey);
    }

    /**
     * BELONGS TO 关联定义
     * @access public
     * @param  string $model      模型名
     * @param  string $foreignKey 关联外键
     * @param  string $localKey   关联主键
     * @return BelongsTo
     */
    public function belongsTo(string $model, string $foreignKey = '', string $localKey = ''): BelongsTo
    {
        // 记录当前关联信息
        $model      = $this->parseModel($model);
        $foreignKey = $foreignKey ?: $this->getForeignKey((new $model)->getName());
        $localKey   = $localKey ?: (new $model)->getPk();
        $trace      = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $relation   = Str::snake($trace[1]['function']);

        return new BelongsTo($this, $model, $foreignKey, $localKey, $relation);
    }

    /**
     * HAS MANY 关联定义
     * @access public
     * @param  string $model      模型名
     * @param  string $foreignKey 关联外键
     * @param  string $localKey   当前主键
     * @return HasMany
     */
    public function hasMany(string $model, string $foreignKey = '', string $localKey = ''): HasMany
    {
        // 记录当前关联信息
        $model      = $this->parseModel($model);
        $localKey   = $localKey ?: $this->getPk();
        $foreignKey = $foreignKey ?: $this->getForeignKey($this->name);

        return new HasMany($this, $model, $foreignKey, $localKey);
    }

    /**
     * HAS MANY 远程关联定义
     * @access public
     * @param  string $model      模型名
     * @param  string $through    中间模型名
     * @param  string $foreignKey 关联外键
     * @param  string $throughKey 关联外键
     * @param  string $localKey   当前主键
     * @param  string $throughPk  中间表主键
     * @return HasManyThrough
     */
    public function hasManyThrough(string $model, string $through, string $foreignKey = '', string $throughKey = '', string $localKey = '', string $throughPk = ''): HasManyThrough
    {
        // 记录当前关联信息
        $model      = $this->parseModel($model);
        $through    = $this->parseModel($through);
        $localKey   = $localKey ?: $this->getPk();
        $foreignKey = $foreignKey ?: $this->getForeignKey($this->name);
        $throughKey = $throughKey ?: $this->getForeignKey((new $through)->getName());
        $throughPk  = $throughPk ?: (new $through)->getPk();

        return new HasManyThrough($this, $model, $through, $foreignKey, $throughKey, $localKey, $throughPk);
    }

    /**
     * HAS ONE 远程关联定义
     * @access public
     * @param  string $model      模型名
     * @param  string $through    中间模型名
     * @param  string $foreignKey 关联外键
     * @param  string $throughKey 关联外键
     * @param  string $localKey   当前主键
     * @param  string $throughPk  中间表主键
     * @return HasOneThrough
     */
    public function hasOneThrough(string $model, string $through, string $foreignKey = '', string $throughKey = '', string $localKey = '', string $throughPk = ''): HasOneThrough
    {
        // 记录当前关联信息
        $model      = $this->parseModel($model);
        $through    = $this->parseModel($through);
        $localKey   = $localKey ?: $this->getPk();
        $foreignKey = $foreignKey ?: $this->getForeignKey($this->name);
        $throughKey = $throughKey ?: $this->getForeignKey((new $through)->getName());
        $throughPk  = $throughPk ?: (new $through)->getPk();

        return new HasOneThrough($this, $model, $through, $foreignKey, $throughKey, $localKey, $throughPk);
    }

    /**
     * BELONGS TO MANY 关联定义
     * @access public
     * @param  string $model      模型名
     * @param  string $middle     中间表/模型名
     * @param  string $foreignKey 关联外键
     * @param  string $localKey   当前模型关联键
     * @return BelongsToMany
     */
    public function belongsToMany(string $model, string $middle = '', string $foreignKey = '', string $localKey = ''): BelongsToMany
    {
        // 记录当前关联信息
        $model      = $this->parseModel($model);
        $name       = Str::snake(class_basename($model));
        $middle     = $middle ?: Str::snake($this->name) . '_' . $name;
        $foreignKey = $foreignKey ?: $name . '_id';
        $localKey   = $localKey ?: $this->getForeignKey($this->name);

        return new BelongsToMany($this, $model, $middle, $foreignKey, $localKey);
    }

    /**
     * MORPH  One 关联定义
     * @access public
     * @param  string       $model 模型名
     * @param  string|array $morph 多态字段信息
     * @param  string       $type  多态类型
     * @return MorphOne
     */
    public function morphOne(string $model, $morph = null, string $type = ''): MorphOne
    {
        // 记录当前关联信息
        $model = $this->parseModel($model);

        if (is_null($morph)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $morph = Str::snake($trace[1]['function']);
        }

        if (is_array($morph)) {
            [$morphType, $foreignKey] = $morph;
        } else {
            $morphType  = $morph . '_type';
            $foreignKey = $morph . '_id';
        }

        $type = $type ?: get_class($this);

        return new MorphOne($this, $model, $foreignKey, $morphType, $type);
    }

    /**
     * MORPH  MANY 关联定义
     * @access public
     * @param  string       $model 模型名
     * @param  string|array $morph 多态字段信息
     * @param  string       $type  多态类型
     * @return MorphMany
     */
    public function morphMany(string $model, $morph = null, string $type = ''): MorphMany
    {
        // 记录当前关联信息
        $model = $this->parseModel($model);

        if (is_null($morph)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $morph = Str::snake($trace[1]['function']);
        }

        $type = $type ?: get_class($this);

        if (is_array($morph)) {
            [$morphType, $foreignKey] = $morph;
        } else {
            $morphType  = $morph . '_type';
            $foreignKey = $morph . '_id';
        }

        return new MorphMany($this, $model, $foreignKey, $morphType, $type);
    }

    /**
     * MORPH TO 关联定义
     * @access public
     * @param  string|array $morph 多态字段信息
     * @param  array        $alias 多态别名定义
     * @return MorphTo
     */
    public function morphTo($morph = null, array $alias = []): MorphTo
    {
        $trace    = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $relation = Str::snake($trace[1]['function']);

        if (is_null($morph)) {
            $morph = $relation;
        }

        // 记录当前关联信息
        if (is_array($morph)) {
            [$morphType, $foreignKey] = $morph;
        } else {
            $morphType  = $morph . '_type';
            $foreignKey = $morph . '_id';
        }

        return new MorphTo($this, $morphType, $foreignKey, $alias, $relation);
    }

    /**
     * MORPH TO MANY关联定义
     * @access public
     * @param  string       $model 模型名
     * @param  string       $middle 中间表名/模型名
     * @param  string|array $morph 多态字段信息
     * @param  string       $localKey   当前模型关联键
     * @return MorphToMany
     */
    public function morphToMany(string $model, string $middle, $morph = null, string $localKey = null): MorphToMany
    {
        if (is_null($morph)) {
            $morph = $middle;
        }

        // 记录当前关联信息
        if (is_array($morph)) {
            [$morphType, $morphKey] = $morph;
        } else {
            $morphType = $morph . '_type';
            $morphKey  = $morph . '_id';
        }

        $model    = $this->parseModel($model);
        $name     = Str::snake(class_basename($model));
        $localKey = $localKey ?: $this->getForeignKey($name);

        return new MorphToMany($this, $model, $middle, $morphType, $morphKey, $localKey);
    }

    /**
     * MORPH BY MANY关联定义
     * @access public
     * @param  string       $model 模型名
     * @param  string       $middle 中间表名/模型名
     * @param  string|array $morph 多态字段信息
     * @param  string       $foreignKey 关联外键
     * @return MorphToMany
     */
    public function morphByMany(string $model, string $middle, $morph = null, string $foreignKey = null): MorphToMany
    {
        if (is_null($morph)) {
            $morph = $middle;
        }

        // 记录当前关联信息
        if (is_array($morph)) {
            [$morphType, $morphKey] = $morph;
        } else {
            $morphType = $morph . '_type';
            $morphKey  = $morph . '_id';
        }

        $model      = $this->parseModel($model);
        $foreignKey = $foreignKey ?: $this->getForeignKey($this->name);

        return new MorphToMany($this, $model, $middle, $morphType, $morphKey, $foreignKey, true);
    }

    /**
     * 解析模型的完整命名空间
     * @access protected
     * @param  string $model 模型名（或者完整类名）
     * @return string
     */
    protected function parseModel(string $model): string
    {
        if (false === strpos($model, '\\')) {
            $path = explode('\\', static::class);
            array_pop($path);
            array_push($path, Str::studly($model));
            $model = implode('\\', $path);
        }

        return $model;
    }

    /**
     * 获取模型的默认外键名
     * @access protected
     * @param  string $name 模型名
     * @return string
     */
    protected function getForeignKey(string $name): string
    {
        if (strpos($name, '\\')) {
            $name = class_basename($name);
        }

        return Str::snake($name) . '_id';
    }

    /**
     * 检查属性是否为关联属性 如果是则返回关联方法名
     * @access protected
     * @param  string $attr 关联属性名
     * @return string|false
     */
    protected function isRelationAttr(string $attr)
    {
        $relation = Str::camel($attr);

        if ((method_exists($this, $relation) && !method_exists('xyh\Model', $relation)) || isset(static::$macro[static::class][$relation])) {
            return $relation;
        }

        return false;
    }

    /**
     * 智能获取关联模型数据
     * @access protected
     * @param  Relation $modelRelation 模型关联对象
     * @return mixed
     */
    protected function getRelationData(Relation $modelRelation)
    {
        if ($this->parent && !$modelRelation->isSelfRelation()
            && get_class($this->parent) == get_class($modelRelation->getModel())) {
            return $this->parent;
        }

        // 获取关联数据
        return $modelRelation->getRelation();
    }

    /**
     * 关联数据自动写入检查
     * @access protected
     * @return void
     */
    protected function checkAutoRelationWrite(): void
    {
        foreach ($this->together as $key => $name) {
            if (is_array($name)) {
                if (key($name) === 0) {
                    $this->relationWrite[$key] = [];
                    // 绑定关联属性
                    foreach ($name as $val) {
                        if (isset($this->data[$val])) {
                            $this->relationWrite[$key][$val] = $this->data[$val];
                        }
                    }
                } else {
                    // 直接传入关联数据
                    $this->relationWrite[$key] = $name;
                }
            } elseif (isset($this->relation[$name])) {
                $this->relationWrite[$name] = $this->relation[$name];
            } elseif (isset($this->data[$name])) {
                $this->relationWrite[$name] = $this->data[$name];
                unset($this->data[$name]);
            }
        }
    }

    /**
     * 自动关联数据更新（针对一对一关联）
     * @access protected
     * @return void
     */
    protected function autoRelationUpdate(): void
    {
        foreach ($this->relationWrite as $name => $val) {
            if ($val instanceof Model) {
                $val->exists(true)->save();
            } else {
                $model = $this->getRelation($name, true);

                if ($model instanceof Model) {
                    $model->exists(true)->save($val);
                }
            }
        }
    }

    /**
     * 自动关联数据写入（针对一对一关联）
     * @access protected
     * @return void
     */
    protected function autoRelationInsert(): void
    {
        foreach ($this->relationWrite as $name => $val) {
            $method = Str::camel($name);
            $this->$method()->save($val);
        }
    }

    /**
     * 自动关联数据删除（支持一对一及一对多关联）
     * @access protected
     * @param  bool $force 强制删除
     * @return void
     */
    protected function autoRelationDelete($force = false): void
    {
        foreach ($this->relationWrite as $key => $name) {
            $name   = is_numeric($key) ? $name : $key;
            $result = $this->getRelation($name, true);

            if ($result instanceof Model) {
                $result->force($force)->delete();
            } elseif ($result instanceof Collection) {
                foreach ($result as $model) {
                    $model->force($force)->delete();
                }
            }
        }
    }

    /**
     * 移除当前模型的关联属性
     * @access public
     * @return $this
     */
    public function removeRelation()
    {
        $this->relation = [];
        return $this;
    }
}
