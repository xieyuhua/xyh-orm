<?php

declare (strict_types = 1);

namespace xyh\model\concern;

use xyh\db\BaseQuery as Query;
use xyh\Model;

/**
 * 数据软删除
 * @mixin Model
 */
trait SoftDelete
{
    /**
     * 是否包含软删除数据
     * @var bool
     */
    protected $withTrashed = false;

    /**
     * 判断当前实例是否被软删除
     * @access public
     * @return bool
     */
    public function trashed(): bool
    {
        $field = $this->getDeleteTimeField();

        if ($field && !empty($this->getOrigin($field))) {
            return true;
        }

        return false;
    }

    /**
     * 查询软删除数据
     * @access public
     * @return Query
     */
    public static function withTrashed(): Query
    {
        $model = new static();

        return $model->withTrashedData(true)->db();
    }

    /**
     * 查询软删除数据
     * @access public
     * @return Query
     */
    public function queryWithTrashed(): Query
    {
        return $this->withTrashedData(true)->db();
    }

    /**
     * 是否包含软删除数据
     * @access protected
     * @param  bool $withTrashed 是否包含软删除数据
     * @return $this
     */
    protected function withTrashedData(bool $withTrashed)
    {
        $this->withTrashed = $withTrashed;
        return $this;
    }

    /**
     * 只查询软删除数据
     * @access public
     * @return Query
     */
    public static function onlyTrashed(): Query
    {
        $model = new static();
        $field = $model->getDeleteTimeField(true);

        if ($field) {
            return $model
                ->db()
                ->useSoftDelete($field, $model->getWithTrashedExp());
        }

        return $model->db();
    }

    /**
     * 只查询软删除数据
     * @access public
     * @return Query
     */
    public function queryOnlyTrashed(): Query
    {
        $field = $this->getDeleteTimeField(true);

        if ($field) {
            return $this->db()
                ->useSoftDelete($field, $this->getWithTrashedExp());
        }

        return $this->db();
    }

    /**
     * 获取软删除数据的查询条件
     * @access protected
     * @return array
     */
    protected function getWithTrashedExp(): array
    {
        return is_null($this->defaultSoftDelete) ? ['notnull', ''] : ['<>', $this->defaultSoftDelete];
    }

    /**
     * 删除当前的记录
     * @access public
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->isExists() || $this->isEmpty() || false === $this->trigger('BeforeDelete')) {
            return false;
        }

        $name  = $this->getDeleteTimeField();
        $force = $this->isForce();

        if ($name && !$force) {
            // 软删除
            $this->set($name, $this->autoWriteTimestamp($name));

            $result = $this->exists()->withEvent(false)->save();

            $this->withEvent(true);
        } else {
            // 读取更新条件
            $where = $this->getWhere();

            // 删除当前模型数据
            $result = $this->db()
                ->where($where)
                ->removeOption('soft_delete')
                ->delete();

            $this->lazySave(false);
        }

        // 关联删除
        if (!empty($this->relationWrite)) {
            $this->autoRelationDelete($force);
        }

        $this->trigger('AfterDelete');

        $this->exists(false);

        return true;
    }

    /**
     * 删除记录
     * @access public
     * @param  mixed $data 主键列表 支持闭包查询条件
     * @param  bool  $force 是否强制删除
     * @return bool
     */
    public static function destroy($data, bool $force = false): bool
    {
        // 传入空值（包括空字符串和空数组）的时候不会做任何的数据删除操作，但传入0则是有效的
        if (empty($data) && 0 !== $data) {
            return false;
        }
        // 仅当强制删除时包含软删除数据
        $model = (new static());
        if ($force) {
            $model->withTrashedData(true);
        }
        $query = $model->db(false);

        if (is_array($data) && key($data) !== 0) {
            $query->where($data);
            $data = null;
        } elseif ($data instanceof \Closure) {
            call_user_func_array($data, [ & $query]);
            $data = null;
        } elseif (is_null($data)) {
            return false;
        }

        $resultSet = $query->select($data);

        foreach ($resultSet as $result) {
            $result->force($force)->delete();
        }

        return true;
    }

    /**
     * 恢复被软删除的记录
     * @access public
     * @param  array $where 更新条件
     * @return bool
     */
    public function restore($where = []): bool
    {
        $name = $this->getDeleteTimeField();

        if (!$name || false === $this->trigger('BeforeRestore')) {
            return false;
        }

        if (empty($where)) {
            $pk = $this->getPk();
            if (is_string($pk)) {
                $where[] = [$pk, '=', $this->getData($pk)];
            }
        }

        // 恢复删除
        $this->db(false)
            ->where($where)
            ->useSoftDelete($name, $this->getWithTrashedExp())
            ->update([$name => $this->defaultSoftDelete]);

        $this->trigger('AfterRestore');

        return true;
    }

    /**
     * 获取软删除字段
     * @access protected
     * @param  bool  $read 是否查询操作 写操作的时候会自动去掉表别名
     * @return string|false
     */
    protected function getDeleteTimeField(bool $read = false)
    {
        $field = property_exists($this, 'deleteTime') && isset($this->deleteTime) ? $this->deleteTime : 'delete_time';

        if (false === $field) {
            return false;
        }

        if (false === strpos($field, '.')) {
            $field = '__TABLE__.' . $field;
        }

        if (!$read && strpos($field, '.')) {
            $array = explode('.', $field);
            $field = array_pop($array);
        }

        return $field;
    }

    /**
     * 查询的时候默认排除软删除数据
     * @access protected
     * @param  Query  $query
     * @return void
     */
    protected function withNoTrashed(Query $query): void
    {
        $field = $this->getDeleteTimeField(true);

        if ($field) {
            $condition = is_null($this->defaultSoftDelete) ? ['null', ''] : ['=', $this->defaultSoftDelete];
            $query->useSoftDelete($field, $condition);
        }
    }
}
