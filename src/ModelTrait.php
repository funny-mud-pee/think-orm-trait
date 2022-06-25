<?php
declare(strict_types=1);

namespace funnymudpee\thinkphp;

use think\Collection;
use think\db\exception\DbException;
use think\db\Query;
use think\db\Raw;
use think\Model;
use think\model\concern\SoftDelete;

/**
 * Trait ModelTrait
 * @mixin Query
 * @mixin SoftDelete
 * @package funnymudpee\thinkphp
 */
trait ModelTrait
{
    /**
     * @param array $locator
     * @param array $join
     * @param string $group
     * @return int
     */
    public static function getCount(array $locator = [], array $join = [], string $group = '')
    {
        $query = self::setComplexQuery($locator, [], $join, [], $group);
        return $query->count();
    }

    /**
     * 设置复杂查询
     * @param array $locator
     * @param array $field
     * @param array $join
     * @param array $sort
     * @param string $group
     * @return Query
     */
    public static function setComplexQuery(array $locator = [], ?array $field = [], array $join = [], array $sort = [], string $group = '')
    {
        // 软删除查询
        $withTrashed = false;
        if (isset($locator['{withTrashed}'])) {
            $withTrashed = boolval($locator['{withTrashed}']);
            unset($locator['{withTrashed}']);
        }
        // 查询范围
        $withoutGlobalScope = null;
        if (isset($locator['{withoutGlobalScope}'])) {
            $withoutGlobalScope = $locator['{withoutGlobalScope}'];
            unset($locator['{withoutGlobalScope}']);
        }

        $whereGroup = [];
        $hasWhereGroup = [];
        $whereOrGroup = [];
        self::optimizeCondition($locator, $whereGroup, $hasWhereGroup, $whereOrGroup);

        // 提取with
        $with = self::extractWith($join);
        $withCount = self::extractWithCount($join);

        // 字段查询
        $append = [];
        $visible = [];
        $hidden = [];
        if (is_null($field)) {
            $field = [];
        } elseif (empty($field)) {
            $field = static::getTableFields();
        } else {
            $append = self::extractAppend($field);
            $visible = self::extractVisible($field);
            $hidden = self::extractHidden($field);
            if (empty($field)) {
                $field = static::getTableFields();
            }
        }

        // start
        /** @var Query $query */
        $query = null;
        // 软删除查询
        if ($withTrashed && property_exists(static::class, 'withTrashed')) {
            static::withTrashedData(true);
        }
        // 全局作用域的处理
        if (!is_null($withoutGlobalScope) && is_array($withoutGlobalScope)) {
            $query = static::withoutGlobalScope($withoutGlobalScope);
        }
        // has where
        if ($hasWhereGroup) {
            $mainModelFields = [];
            foreach ($field as $i => $fieldItem) {
                if (is_string($i)) {
                    $fieldAlias = $fieldItem;
                    $fieldItem = $i;
                }
                if (false === strpos($fieldItem, '.')) {
                    array_push($mainModelFields, (!empty($fieldAlias) ? $fieldItem . ' AS ' . $fieldAlias : $fieldItem));
                    unset($field[$i]);
                }
            }
            $hasWhereField = $mainModelFields ? implode(',', $mainModelFields) : '';
            foreach ($hasWhereGroup as $aHasWhereItem) {
                if (!is_null($query)) {
                    $query->hasWhere($aHasWhereItem['relation'], $aHasWhereItem['where']);
                } else {
                    $query = static::hasWhere($aHasWhereItem['relation'], $aHasWhereItem['where'], $hasWhereField);
                }
            }
        }
        // where
        $alias = '';
        if ($query) {
            // alias
            $alias = $query->getOptions('alias');
            $table = $query->getTable();
            $alias = isset($alias[$table]) ? $alias[$table] : '';
        } else {
            $query = (new static())->db();
        }
        if (empty($alias) && !empty($join)) {
            $alias = $query->getTable();
            $query->alias($query->getTable());
        }
        // concatenate alias
        self::mainModelConditionWithAlias($whereGroup, $alias);
        self::mainModelConditionWithAlias($whereOrGroup, $alias);
        self::fieldWithAlias($field, $alias);
        $whereGroup = empty($whereGroup) ? true : $whereGroup;
        $query->where($whereGroup);
        // where or
        if (!empty($whereOrGroup)) {
            foreach ($whereOrGroup as $whereOr) {
                $query->where(function ($query) use ($whereOr) {
                    $query->whereOr($whereOr);
                });
            }
        }
        // sort
        if (!empty($sort)) {
            $aNewSort = [];
            foreach ($sort as $sortField => $sortValue) {
                $aNewSort[self::concatenateAlias($sortField, $alias)] = $sortValue;
            }
            $sort = $aNewSort;
        }
        // group
        if (!empty($group)) {
            $query->group(self::concatenateAlias($group, $alias));
        }
        // join
        // [['模型类','别名'],['关联键名'=>'外键'],'LEFT|INNER|RIGHT',['查询字段']]
        foreach ($join as $aItem) {
            // join table
            [$tpJoinExpression, $joinAlias] = self::resolveJoin($aItem);

            // condition
            $condition = '';
            foreach ($aItem[1] as $localKey => $foreignKey) {
                if (is_string($foreignKey) && !is_numeric($foreignKey) && false === strpos($foreignKey, '.')) {
                    $foreignKey = self::concatenateAlias($foreignKey, $alias);
                }
                $condition .= self::concatenateAlias($localKey, $joinAlias) . ' = ' . $foreignKey . ' AND ';
            }
            $condition = trim($condition, ' AND ');
            // join type
            $joinType = $aItem[2] ?? 'INNER';
            $query->join($tpJoinExpression, $condition, $joinType);
            // join model fields
            $aGetJoinFields = $aItem[3] ?? [];
            if (!empty($aGetJoinFields)) {
                self::fieldWithAlias($aGetJoinFields, $joinAlias);
                $field = array_merge($field, $aGetJoinFields);
            }
        }

        $query->field($field)->order($sort)->with($with)->withCount($withCount)->append($append)->visible($visible)->hidden($hidden);

        return $query;
    }

    /**
     * 整理为TP ORM支持的数组where条件
     * @param array $locator
     * @param array $whereGroup
     * @param array $hasWhereGroup
     * @param array $whereOrGroup
     */
    private static function optimizeCondition(array $locator, array &$whereGroup, array &$hasWhereGroup = [], array &$whereOrGroup = [])
    {
        if (empty($locator)) {
            return;
        }
        self::parseWhereItemWithStringKey($locator);
        $where = [];
        if (self::existLogicKey($locator)) {
            self::dealAndPushLogic($locator, $whereOrGroup);
        } else {
            foreach ($locator as $key => $value) {
                if (is_numeric($key)) {
                    if (!is_array($value)) {
                        continue;
                    }
                    if (self::existLogicKey($value)) {
                        self::dealAndPushLogic($value, $whereOrGroup);
                    } else {
                        self::parseWhereItemWithStringKey($value);
                        array_push($where, $value);
                    }
                } elseif (self::isHasWhereKey($key)) {
                    [$identify, $relation] = explode('.', $key);
                    $hasWhere = [];
                    self::optimizeCondition($value, $hasWhere);
                    if (1 === count($hasWhere) && isset($hasWhere[0]) && is_array($hasWhere[0][0])) {
                        $hasWhere = reset($hasWhere);
                    }
                    array_push($hasWhereGroup, ['relation' => $relation, 'where' => $hasWhere]);
                }
            }
        }
        if (count($where) === 1) {
            $where = reset($where);
        }
        if (!empty($where)) {
            array_push($whereGroup, $where);
        }
    }

    private static function parseWhereItemWithStringKey(array &$locator)
    {
        foreach ($locator as $key => $value) {
            if (!is_string($key) || self::isLogicKey($key) || self::isHasWhereKey($key)) {
                continue;
            }
            if (is_array($value)) {
                $firstKey = array_key_first($value);
                if (is_array($value[$firstKey])) {
                    $whereGroup = [];
                    foreach ($value as $item) {
                        array_unshift($item, $key);
                        array_push($whereGroup, $item);
                    }
                    array_push($locator, $whereGroup);
                } else {
                    array_unshift($value, $key);
                    array_push($locator, $value);
                }
            } else {
                array_push($locator, [$key, '=', $value]);
            }
            unset($locator[$key]);
        }
    }

    private static function isLogicKey(string $key)
    {
        if ('{logic}' === $key) {
            return true;
        }
        return false;
    }

    private static function isHasWhereKey(string $key)
    {
        if ('{has}' === $key || false !== strpos($key, '{has}.')) {
            return true;
        }
        return false;
    }

    private static function existLogicKey(array $data)
    {
        return array_key_exists('{logic}', $data);
    }

    private static function dealAndPushLogic(array $logicGroup, array &$whereOrGroup = [])
    {
        $logic = $logicGroup['{logic}'] ?? null;
        unset($logicGroup['{logic}']);
        if (!empty($logicGroup)) {
            switch ($logic) {
                case 'OR':
                    //$whereOrGroup = array_merge($whereOrGroup, $subWhereGroup);
                    array_push($whereOrGroup, $logicGroup);
                    break;
            }
        }
    }

    /**
     * 提取模型关联字段配置
     * @param array $join
     * @return array
     */
    private static function extractWith(array &$join)
    {
        $aWith = [];
        if (empty($join)) {
            return $aWith;
        }
        foreach ($join as $withKey => $withConf) {
            if (is_string($withKey) && false !== strpos($withKey, '{with}.')) {
                [$identify, $relation] = explode('.', $withKey);
                $aWith[$relation] = self::setWith($withConf);
                unset($join[$withKey]);
            }
        }
        $data = $join['{with}'] ?? [];
        unset($join['{with}']);
        if (!is_array($data)) {
            array_push($aWith, $data);
        } else {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $aWith[$key] = self::setWith($value);
                } else {
                    $aWith[] = $value;
                }
            }
        }
        return $aWith;
    }

    private static function setWith(array $config)
    {
        $field = $config['field'] ?? [];
        $aAppend = self::extractAppend($field);
        $aVisible = self::extractVisible($field);
        $aHidden = self::extractHidden($field);
        $join = $config['join'] ?? [];
        $aBind = $config['bind'] ?? [];
        $aWith = self::extractWith($join);
        return function ($query) use ($field, $aBind, $aWith, $aAppend, $aVisible, $aHidden) {
            $field = empty($field) ? true : $field;
            $query->field($field)
                ->with($aWith)
                ->append($aAppend)
                ->visible($aVisible)
                ->hidden($aHidden)
                ->bind($aBind);
        };
    }

    /**
     * @param array $field
     * @return array
     */
    private static function extractAppend(array &$field)
    {
        $aAppend = [];
        if (!empty($field['{append}'])) {
            $aAppend = $field['{append}'];
            unset($field['{append}']);
        }
        return $aAppend;
    }

    /**
     * @param array $field
     * @return array
     */
    private static function extractVisible(array &$field)
    {
        $visible = [];
        if (!empty($field['{visible}'])) {
            $visible = $field['{visible}'];
            unset($field['{visible}']);
        }
        return $visible;
    }

    /**
     * @param array $field
     * @return array
     */
    private static function extractHidden(array &$field)
    {
        $hidden = [];
        if (!empty($field['{hidden}'])) {
            $hidden = $field['{hidden}'];
            unset($field['{hidden}']);
        }
        return $hidden;
    }

    private static function extractWithCount(array &$join)
    {
        $data = $join['{withCount}'] ?? [];
        unset($join['{withCount}']);
        return $data;
    }

    /**
     * 整理为TP ORM支持的数组where条件
     * @param array $where
     * @param string $alias
     */
    private static function mainModelConditionWithAlias(array &$where, string $alias)
    {
        if (empty($where) || empty($alias)) {
            return;
        }
        foreach ($where as &$item) {
            if (is_array($item[0])) {
                self::mainModelConditionWithAlias($item, $alias);
            } else {
                $item[0] = self::concatenateAlias($item[0], $alias);
            }
        }
    }

    private static function concatenateAlias(string $field, ?string $alias)
    {
        if (!empty($alias) && false === strpos($field, '.')) {
            if (self::isAggregateField($field)) {
                $leftParenthesesPosition = strpos($field, '(');
                [$left, $right] = explode('(', $field);
                $field = $left . '(' . $alias . '.' . $right;
            } else {
                $field = $alias . '.' . $field;
            }
        }
        return $field;
    }

    private static function isAggregateField(string $field)
    {
        if (false === strpos($field, '(') || false === strpos($field, ')')) {
            return false;
        }
        $field = strtoupper($field);
        if (0 === strpos($field, 'SUM') || 0 === strpos($field, 'COUNT') || 0 === strpos($field, 'MIN') || 0 === strpos($field, 'MAX')) {
            return true;
        }
        return false;
    }

    /**
     * 整理为TP ORM支持的数组where条件
     * @param array $fields
     * @param string $alias
     */
    private static function fieldWithAlias(array &$fields, string $alias)
    {
        if (empty($fields) || empty($alias)) {
            return;
        }
        $result = [];
        foreach ($fields as $i => $field) {
            if (is_string($i)) {
//                if (self::isAggregateField($i)) {
//                    // SUM('id')
//                    $leftParenthesesPosition = strpos($i, '(');
//                    [$left, $right] = explode('(', $i);
//                    $i = $left . '(' . $alias . '.' . $right;
//                    $result[$i] = $field;
//                } else {
//                    $fieldAlias = $field;
//                    $field = $i;
//                    $result[self::concatenateAlias($field, $alias)] = $fieldAlias;
//                }
                $fieldAlias = $field;
                $field = $i;
                $result[self::concatenateAlias($field, $alias)] = $fieldAlias;
            } else {
                $result[$i] = self::concatenateAlias($field, $alias);
            }
        }
        $fields = $result;
    }

    private static function resolveJoin(array $input)
    {
        $config = $input[0] ?? '';
        if (is_array($config)) {
            $modelOrSubSQL = $config[0];
            $alias = $config[1] ?? '';
            if (class_exists($modelOrSubSQL)) {
                /** @var Model $oJoinModel */
                $oJoinModel = new $modelOrSubSQL;
                if (!empty($alias)) {
                    $join = [$oJoinModel->getTable() => $alias];
                } else {
                    $join = $alias = $oJoinModel->getTable();
                }
            } else {
                if (empty($alias)) {
                    throw new \Exception('请设置子查询别名');
                }
                $join = [$modelOrSubSQL => $alias];
            }

        } elseif (class_exists($config)) {
            /** @var Model $oJoinModel */
            $oJoinModel = new $config;
            $join = $alias = $oJoinModel->getTable();
        } else {
            // todo 分离子查询和别名  `(select id from user) AS user`
            $join = '';
            $alias = '';
        }
        return [$join, $alias];
    }

    /**
     * @param string|Raw $field
     * @param array $locator
     * @param array $join
     * @param string $group
     * @return float
     */
    public static function getSum($field, array $locator = [], array $join = [], string $group = '')
    {
        $query = self::setComplexQuery($locator, [], $join, [], $group);
        return $query->sum($field);
    }

    /**
     * 根据主键更新记录
     * @param array $aData
     * @param $id
     * @return bool
     * @throws DbException
     */
    public static function updateById(array $aData, $id)
    {
        $model = self::get($id, ['id']);
        if ($model->isEmpty()) {
            return false;
        }
        return $model->save($aData);
    }

    /**
     * 获取一条数据
     * @param mixed $condition ID或者一组条件
     * @param array $field 查询字段
     * @param array $join 关联查询
     * @return self
     * @throws DbException
     */
    public static function get($condition = '', array $field = [], array $join = [])
    {
        if (empty($condition)) {
            $locator = [];
        } elseif (is_array($condition)) {
            $locator = $condition;
        } elseif (is_string($condition) || is_int($condition)) {
            $pk = self::getPrimaryKey();
            if (is_string($pk)) {
                $locator[$pk] = $condition;
            } else {
                throw new DbException('模型包含多个主键:' . implode(',', $pk));
            }
        } else {
            $locator = [];
        }
        $query = self::setComplexQuery($locator, $field, $join);
        return $query->findOrEmpty();
    }

    /**
     * 获取当前模型主键
     * @return mixed
     */
    public static function getPrimaryKey()
    {
        return (new static())->getPk();
    }

    /**
     * 根据字段更新记录
     * @param array $aData
     * @param string $field
     * @param string $fieldValue
     * @return bool
     * @throws DbException
     */
    public static function updateByField(array $aData, string $field, string $fieldValue)
    {
        $model = self::getByField($field, $fieldValue, [$field]);
        if ($model->isEmpty()) {
            return false;
        }
        return $model->save($aData);
    }

    /**
     * 根据字段获取数据
     * @param string|array $key
     * @param string|array $value
     * @param array $field
     * @param array $join
     * @return self
     * @throws DbException
     */
    public static function getByField($key, $value, array $field = [], array $join = [])
    {
        if (is_array($key) && is_array($value)) {
            $locator = array_combine($key, $value);
        } else {
            $locator = [$key => $value];
        }
        return self::get($locator, $field, $join);
    }

    /**
     * 更新数据
     * @param array $aUpdateData
     * @param array $locator
     * @return static
     */
    public static function updateByLocator(array $aUpdateData, array $locator)
    {
        $where = [];
        self::optimizeCondition($locator, $where);
        return self::update($aUpdateData, $where);
    }

    /**
     * 添加或更新多条数据
     * @param array $aDataList
     * @param bool $replace
     * @return mixed
     */
    public static function setAll(iterable $dataSet, bool $replace = true)
    {
        if (empty($dataSet)) {
            return false;
        }
        return (new static())->saveAll($dataSet, $replace);
    }

    /**
     * @param array $locator
     * @param array $field
     * @param array $join
     * @param array $sort
     * @return self
     * @throws DbException
     */
    public static function getOne(array $locator = [], array $field = [], array $join = [], array $sort = [], string $group = '')
    {
        $list = self::getList($locator, $field, $join, $sort, 1, $group);
        if ($list->isEmpty()) {
            return new static();
        }
        return $list[0];
    }

    /**
     * 获取数据列表
     * @param array $where
     * @param array $field
     * @param array $join
     * @param array $sort
     * @param int $limit
     * @param string $group
     * @return Collection
     * @throws DbException
     */
    public static function getList(array $where = [], ?array $field = [], array $join = [], array $sort = [], int $limit = 0, string $group = '')
    {
        $query = self::setComplexQuery($where, $field, $join, $sort, $group);
        return $query->limit($limit)->select();
    }

    /**
     * 获取分页数据
     * @param array $where
     * @param array $field
     * @param array $join
     * @param array $sort
     * @param int $listRows
     * @param string $group
     * @return array
     * @throws DbException
     */
    public static function getPage(array $where = [], ?array $field = [], array $join = [], array $sort = [], int $listRows = 0, string $group = '')
    {
        $aConf = null;
        if ($submitListRows = request()->param('list_rows', 0, 'intval')) {
            $aConf['list_rows'] = $submitListRows;
        } elseif ($listRows) {
            $aConf['list_rows'] = $listRows;
        }
        $query = self::setComplexQuery($where, $field, $join, $sort, $group);
        // query
        $oPaginate = $query->paginate($aConf);
        $result = $oPaginate->toArray();
        return [
            'list' => $result['data'],
            'total_pages' => $result['last_page'],
        ];
    }

    /**
     * 删除数据
     * @param array $locator
     * @return bool
     * @throws DbException
     */
    public static function deleteByLocator(array $locator = [])
    {
        $whereGroup = [];
        self::optimizeCondition($locator, $whereGroup);
        if (empty($whereGroup)) {
            throw new DbException('删除条件不能为空');
        }
        return self::destroy(function ($query) use ($whereGroup) {
            $query->where($whereGroup);
        });
    }

    /**
     * 根据字段删除数据
     * @param string $field
     * @param $value
     * @return bool
     * @throws DbException
     */
    public static function deleteByField(string $field, $value)
    {
        $locator = [
            $field => $value,
        ];
        $whereGroup = [];
        self::optimizeCondition($locator, $whereGroup);
        if (empty($whereGroup)) {
            throw new DbException('删除条件不能为空');
        }
        return self::destroy(function ($query) use ($whereGroup) {
            $query->where($whereGroup);
        });
    }

    /**
     * 删除数据
     * @param int $id
     * @return bool
     */
    public static function deleteById(int $id)
    {
        return self::destroy(function ($query) use ($id) {
            $model = $query->getModel();
            $pk = $model->getPrimaryKey();
            $query->where($pk, '=', $id);
        });
    }

    /**
     * 清空数据
     * @return bool
     */
    public static function clear()
    {
        return self::destroy(function ($query) {
            $query->where(true);
        });
    }
}
