<?php

namespace funnymudpee\thinkphp;

use think\facade\Db;

/**
 * tp orm mysql支持事务嵌套，尽管套就好，不用过多处理
 * Trait DbTransTrait
 * @package funnymudpee\thinkphp
 */
trait DbTransTrait
{
    /**
     * 开始事务
     * @access protected
     * @return void
     */
    protected static function startDbTrans()
    {
        Db::startTrans();
    }

    /**
     * 结束事务
     * @access protected
     * @param bool $isWrong [in opt]是否出错，标记出错时事务回滚
     * @return void
     */
    protected static function endDbTrans(bool $isWrong = true)
    {
        if ($isWrong) {
            Db::rollback();
        } else {
            Db::commit();
        }
    }
}