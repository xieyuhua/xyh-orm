<?php


namespace xyh\facade;

use xyh\Facade;

/**
 * @see \xyh\DbManager
 * @mixin \xyh\DbManager
 */
class Db extends Facade
{
    /**
     * 获取当前Facade对应类名（或者已经绑定的容器对象标识）
     * @access protected
     * @return string
     */
    protected static function getFacadeClass()
    {
        return 'xyh\DbManager';
    }
}
