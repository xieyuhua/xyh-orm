<?php

declare (strict_types = 1);

namespace xyh\model;

use Closure;
use ReflectionFunction;
use xyh\db\BaseQuery as Query;
use xyh\db\exception\DbException as Exception;
use xyh\Model;

/**
 * 模型关联基础类
 * @package xyh\model
 * @mixin Query
 */
abstract class Relation
{
    /**
     * 父模型对象
     * @var Model
     */
    protected $parent;

    /**
     * 当前关联的模型类名
     * @var string
     */
    protected $model;

    /**
     * 关联模型查询对象
     * @var Query
     */
    protected $query;

    /**
     * 关联表外键
     * @var string
     */
    protected $foreignKey;

    /**
     * 关联表主键
     * @var string
     */
    protected $localKey;

    /**
     * 是否执行关联基础查询
     * @var bool
     */
    protected $baseQuery;

    /**
     * 是否为自关联
     * @var bool
     */
    protected $selfRelation = false;

    /**
     * 关联数据数量限制
     * @var int
     */
    protected $withLimit;

    /**
     * 关联数据字段限制
     * @var array
     */
    protected $withField;

    /**
     * 排除关联数据字段
     * @var array
     */
    protected $withoutField;

    /**
     * 默认数据
     * @var mixed
     */
    protected $default;

    /**
     * 获取关联的所属模型
     * @access public
     * @return Model
     */
    public function getParent(): Model
    {
        return $this->parent;
    }

    /**
     * 获取当前的关联模型类的Query实例
     * @access public
     * @return Query
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * 获取关联表外键
     * @access public
     * @return string
     */
    public function getForeignKey()
    {
        return $this->foreignKey;
    }

    /**
     * 获取关联表主键
     * @access public
     * @return string
     */
    public function getLocalKey()
    {
        return $this->localKey;
    }

    /**
     * 获取当前的关联模型类的实例
     * @access public
     * @return Model
     */
    public function getModel(): Model
    {
        return $this->query->getModel();
    }

    /**
     * 当前关联是否为自关联
     * @access public
     * @return bool
     */
    public function isSelfRelation(): bool
    {
        return $this->selfRelation;
    }

    /**
     * 封装关联数据集
     * @access public
     * @param  array $resultSet 数据集
     * @param  Model $parent 父模型
     * @return mixed
     */
    protected function resultSetBuild(array $resultSet, Model $parent = null)
    {
        return (new $this->model)->toCollection($resultSet)->setParent($parent);
    }

    protected function getQueryFields(string $model)
    {
        $fields = $this->query->getOptions('field');
        return $this->getRelationQueryFields($fields, $model);
    }

    protected function getRelationQueryFields($fields, string $model)
    {
        if (empty($fields) || '*' == $fields) {
            return $model . '.*';
        }

        if (is_string($fields)) {
            $fields = explode(',', $fields);
        }

        foreach ($fields as &$field) {
            if (false === strpos($field, '.')) {
                $field = $model . '.' . $field;
            }
        }

        return $fields;
    }

    protected function getQueryWhere(array &$where, string $relation): void
    {
        foreach ($where as $key => &$val) {
            if (is_string($key)) {
                $where[] = [false === strpos($key, '.') ? $relation . '.' . $key : $key, '=', $val];
                unset($where[$key]);
            } elseif (isset($val[0]) && false === strpos($val[0], '.')) {
                $val[0] = $relation . '.' . $val[0];
            }
        }
    }

    /**
     * 限制关联数据的数量
     * @access public
     * @param  int $limit 关联数量限制
     * @return $this
     */
    public function withLimit(int $limit)
    {
        $this->withLimit = $limit;
        return $this;
    }

    /**
     * 限制关联数据的字段
     * @access public
     * @param  array|string $field 关联字段限制
     * @return $this
     */
    public function withField($field)
    {
        if (is_string($field)) {
            $field = array_map('trim', explode(',', $field));
        }

        $this->withField = $field;
        return $this;
    }

    /**
     * 排除关联数据的字段
     * @access public
     * @param  array|string $field 关联字段限制
     * @return $this
     */
    public function withoutField($field)
    {
        if (is_string($field)) {
            $field = array_map('trim', explode(',', $field));
        }

        $this->withoutField = $field;
        return $this;
    }

    /**
     * 设置关联数据不存在的时候默认值
     * @access public
     * @param  mixed $data 默认值
     * @return $this
     */
    public function withDefault($data = null)
    {
        $this->default = $data;
        return $this;
    }

    /**
     * 获取关联数据默认值
     * @access protected
     * @return mixed
     */
    protected function getDefaultModel()
    {
        if (is_array($this->default)) {
            $model = (new $this->model)->data($this->default);
        } elseif ($this->default instanceof Closure) {
            $closure = $this->default;
            $model   = new $this->model;
            $closure($model);
        } else {
            $model = $this->default;
        }

        return $model;
    }

    /**
     * 判断闭包的参数类型
     * @access protected
     * @return mixed
     */
    protected function getClosureType(Closure $closure)
    {
        $reflect = new ReflectionFunction($closure);
        $params  = $reflect->getParameters();

        if (!empty($params)) {
            $type = $params[0]->getType();
            return is_null($type) || Relation::class == $type->getName() ? $this : $this->query;
        }

        return $this;
    }

    /**
     * 执行基础查询（仅执行一次）
     * @access protected
     * @return void
     */
    protected function baseQuery(): void
    {}

    public function __call($method, $args)
    {
        if ($this->query) {
            // 执行基础查询
            $this->baseQuery();

            $result = call_user_func_array([$this->query, $method], $args);

            return $result === $this->query ? $this : $result;
        }

        throw new Exception('method not exists:' . __CLASS__ . '->' . $method);
    }
}
