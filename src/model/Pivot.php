<?php

declare (strict_types = 1);

namespace xyh\model;

use xyh\Model;

/**
 * 多对多中间表模型类
 */
class Pivot extends Model
{

    /**
     * 父模型
     * @var Model
     */
    public $parent;

    /**
     * 是否时间自动写入
     * @var bool
     */
    protected $autoWriteTimestamp = false;

    /**
     * 架构函数
     * @access public
     * @param array      $data   数据
     * @param Model|null $parent 上级模型
     * @param string     $table  中间数据表名
     */
    public function __construct(array $data = [], Model $parent = null, string $table = '')
    {
        $this->parent = $parent;

        if (is_null($this->name)) {
            $this->name = $table;
        }

        parent::__construct($data);
    }

    /**
     * 创建新的模型实例
     * @access public
     * @param array $data    数据
     * @param mixed $where   更新条件
     * @param array $options 参数
     * @return Model
     */
    public function newInstance(array $data = [], $where = null, array $options = []): Model
    {
        $model = parent::newInstance($data, $where, $options);

        $model->parent = $this->parent;
        $model->name   = $this->name;

        return $model;
    }
}
