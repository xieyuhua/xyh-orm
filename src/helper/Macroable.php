<?php

namespace xyh\helper;

use Closure;
use xyh\exception\FuncNotFoundException;

trait Macroable
{
    /**
     * 方法注入.
     *
     * @var Closure[]
     */
    protected static $macro = [];

    /**
     * 设置方法注入.
     *
     * @param string  $method
     * @param Closure $closure
     *
     * @return void
     */
    public static function macro(string $method, Closure $closure)
    {
        static::$macro[$method] = $closure;
    }

    /**
     * 检查方法是否已经有注入
     *
     * @param  string  $name
     * @return bool
     */
    public static function hasMacro(string $method)
    {
        return isset(static::$macro[$method]);
    }

    public function __call($method, $args)
    {
        if (!isset(static::$macro[$method])) {
            throw new FuncNotFoundException('method not exists: ' . static::class . '::' . $method . '()', "{static::class}::{$method}");
        }

        return call_user_func_array(static::$macro[$method]->bindTo($this, static::class), $args);
    }

    public static function __callStatic($method, $args)
    {
        if (!isset(static::$macro[$method])) {
            throw new FuncNotFoundException('method not exists: ' . static::class . '::' . $method . '()', "{static::class}::{$method}");
        }

        return call_user_func_array(static::$macro[$method]->bindTo(null, static::class), $args);        
    }
}
