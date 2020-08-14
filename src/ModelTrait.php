<?php

namespace funnymudpee\thinkphp;

use think\Collection;
use think\db\exception\DbException;
use think\db\Query;
use think\Model;

/**
 * Trait ModelTrait
 * @package funnymudpee\thinkphp
 */
trait ModelTrait
{
    /**
     * @param array $aLocator
     * @param array $aJoin
     * @param string $group
     * @return int
     */
    public static function getCount(array $aLocator = [], array $aJoin = [], string $group = '')
    {
        $query = self::setComplexQuery($aLocator, [], $aJoin, [], $group);
        return $query->count();
    }

    /**
     * 设置复杂查询
     * @param array $aLocator
     * @param array $aField
     * @param array $aJoin
     * @param array $aSort
     * @param string $group
     * @return Query
     */
    public static function setComplexQuery(array $aLocator = [], array $aField = [], array $aJoin = [], array $aSort = [], string $group = '')
    {
        $withTrashed = false;
        if (isset($aLocator['{withTrashed}'])) {
            $withTrashed = boolval($aLocator['{withTrashed}']);
            unset($aLocator['{withTrashed}']);
        }
        $whereGroup = [];
        $hasWhereGroup = [];
        $whereOrGroup = [];
        self::optimizeCondition($aLocator, $whereGroup, $hasWhereGroup, $whereOrGroup);
        $with = self::extractWith($aJoin);
        $append = self::extractAppend($aField);
        $visible = self::extractVisible($aField);
        $hidden = self::extractHidden($aField);
        // start
        /** @var Query $query */
        $query = null;
        // with trashed
        if ($withTrashed) {
            $query = static::withTrashed();
        }
        // has where
        if ($hasWhereGroup) {
            $mainModelFields = [];
            foreach ($aField as $i => $field) {
                if (is_string($i)) {
                    $fieldAlias = $field;
                    $field = $i;
                }
                if (false === strpos($field, '.')) {
                    array_push($mainModelFields, (!empty($fieldAlias) ? $field . ' AS ' . $fieldAlias : $field));
                    unset($aField[$i]);
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
        if ($query) {
            // alias
            $alias = $query->getOptions('alias');
            $table = $query->getTable();
            $alias = isset($alias[$table]) ? $alias[$table] : '';
        } else {
            $query = static::newQuery();
        }
        if (empty($alias) && !empty($aJoin)) {
            $alias = $query->getTable();
            $query->alias($query->getTable());
        }
        if (!empty($alias)) {
            self::mainModelConditionWithAlias($whereGroup, $alias);
            self::mainModelConditionWithAlias($whereOrGroup, $alias);
            self::fieldWithAlias($aField, $alias);
        }
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
        if (!empty($aSort)) {
            $aNewSort = [];
            foreach ($aSort as $sortField => $sortValue) {
                if (false === strpos($sortField, '.') && $alias) {
                    $aNewSort[$alias . $sortField] = $sortValue;
                } else {
                    $aNewSort[$sortField] = $sortValue;
                }
            }
            $aSort = $aNewSort;
        }
        // group
        if (!empty($group)) {
            $query->group($alias . $group);
        }
        // join
        // [['模型类','别名'],['模型关联键名'=>'当前模型关联键名/自定义表达式'],'LEFT|INNER|RIGHT']
        foreach ($aJoin as $aItem) {
            // join table
            if (is_array($aItem[0])) {
                $aJoinModelInfo = $aItem[0];
                /** @var Model $oJoinModel */
                $oJoinModel = new $aJoinModelInfo[0];
                if (!empty($aJoinModelInfo[1])) {
                    $joinName = $aJoinModelInfo[1];
                    $join = [$oJoinModel->getTable() => $aJoinModelInfo[1]];
                } else {
                    $join = $joinName = $oJoinModel->getTable();
                }
            } else {
                /** @var Model $oJoinModel */
                $oJoinModel = new $aItem[0];
                $join = $joinName = $oJoinModel->getTable();
            }
            // condition
            $condition = '';
            foreach ($aItem[1] as $localKey => $foreignKey) {
                if (is_string($foreignKey) && !is_numeric($foreignKey) && false === strpos($foreignKey, '.')) {
                    $foreignKey = self::concatenateAlias($foreignKey, $alias);
                }
                $condition .= self::concatenateAlias($localKey, $joinName) . ' = ' . $foreignKey . ' AND ';
            }
            $condition = trim($condition, ' AND ');
            // join type
            $joinType = $aItem[2] ?? 'INNER';
            $query->join($join, $condition, $joinType);
        }
        $query->field($aField)->order($aSort)->with($with)->append($append)->visible($visible)->hidden($hidden);
        return $query;
    }

    /**
     * 整理为TP ORM支持的数组where条件
     * @param array $aLocator
     * @param array $whereGroup
     * @param array $hasWhereGroup
     * @param array $whereOrGroup
     */
    private static function optimizeCondition(array $aLocator, array &$whereGroup, array &$hasWhereGroup = [], array &$whereOrGroup = [])
    {
        if (empty($aLocator)) {
            return;
        }
        $where = [];
        foreach ($aLocator as $key => $value) {
            if (is_numeric($key)) {
                if (!is_array($value)) {
                    continue;
                }
                if (isset($value['__LOGIC__'])) {
                    $logic = $value['__LOGIC__'];
                    unset($value['__LOGIC__']);
                    $subWhereGroup = [];
                    foreach ($value as $aSubLocator) {
                        self::optimizeCondition($aSubLocator, $subWhereGroup);
                    }
                    if (!empty($subWhereGroup)) {
                        switch ($logic) {
                            case 'OR':
                                //$whereOrGroup = array_merge($whereOrGroup, $subWhereGroup);
                                array_push($whereOrGroup, $subWhereGroup);
                                break;
                        }
                    }
                } else {
                    array_push($where, $value);
                }
            } elseif (false !== strpos($key, '{has}.')) {
                list($identify, $relation) = explode('.', $key);
                $hasWhere = [];
                self::optimizeCondition($value, $hasWhere);
                if (1 === count($hasWhere) && isset($hasWhere[0]) && is_array($hasWhere[0][0])) {
                    $hasWhere = reset($hasWhere);
                }
                array_push($hasWhereGroup, ['relation' => $relation, 'where' => $hasWhere]);
            } elseif (is_string($key)) {
                if (is_array($value)) {
                    if (is_array($value[0])) {
                        foreach ($value as $item) {
                            array_unshift($item, $key);
                            array_push($where, $item);
                        }
                    } else {
                        array_unshift($value, $key);
                        array_push($where, $value);
                    }
                } else {
                    array_push($where, [$key, '=', $value]);
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

    /**
     * 提取模型关联字段配置
     * @param array $aJoin
     * @return array
     */
    private static function extractWith(array &$aJoin)
    {
        $aWith = [];
        if (empty($aJoin)) {
            return $aWith;
        }
        foreach ($aJoin as $withKey => $withConf) {
            if (false !== strpos($withKey, '{with}.')) {
                list($identify, $relation) = explode('.', $withKey);
                $aWith[$relation] = self::setWith($withConf);
                unset($aJoin[$withKey]);
            }
        }
        $data = $aJoin['{with}'] ?? [];
        unset($aJoin['{with}']);
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
        $aField = $config['field'] ?? [];
        $aAppend = self::extractAppend($aField);
        $aVisible = self::extractVisible($aField);
        $aHidden = self::extractHidden($aField);
        $aJoin = $config['join'] ?? [];
        $aBind = $config['bind'] ?? [];
        $aWith = self::extractWith($aJoin);
        return function ($query) use ($aField, $aBind, $aWith, $aAppend, $aVisible, $aHidden) {
            $aField = empty($aField) ? true : $aField;
            $query->field($aField)
                ->with($aWith)
                ->append($aAppend)
                ->visible($aVisible)
                ->hidden($aHidden)
                ->bind($aBind);
        };
    }

    /**
     * @param array $aField
     * @return array
     */
    private static function extractAppend(array &$aField)
    {
        $aAppend = [];
        if (!empty($aField['{append}'])) {
            $aAppend = $aField['{append}'];
            unset($aField['{append}']);
        }
        return $aAppend;
    }

    /**
     * @param array $aField
     * @return array
     */
    private static function extractVisible(array &$aField)
    {
        $visible = [];
        if (!empty($aField['{visible}'])) {
            $visible = $aField['{visible}'];
            unset($aField['{visible}']);
        }
        return $visible;
    }

    /**
     * @param array $aField
     * @return array
     */
    private static function extractHidden(array &$aField)
    {
        $hidden = [];
        if (!empty($aField['{hidden}'])) {
            $hidden = $aField['{hidden}'];
            unset($aField['{hidden}']);
        }
        return $hidden;
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
                $fieldAlias = $field;
                $field = $i;
                $result[self::concatenateAlias($field, $alias)] = $fieldAlias;
            } else {
                $result[$i] = self::concatenateAlias($field, $alias);
            }
        }
        $fields = $result;
    }

    private static function concatenateAlias(string $field, ?string $alias)
    {
        if (!empty($alias) && false === strpos($field, '.')) {
            $field = $alias . '.' . $field;
        }
        return $field;
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
     * @param array $aField 查询字段
     * @param array $aJoin 关联查询
     * @return self
     * @throws DbException
     */
    public static function get($condition = '', array $aField = [], array $aJoin = [])
    {
        if (empty($condition)) {
            $aLocator = [];
        } elseif (is_array($condition)) {
            $aLocator = $condition;
        } elseif (is_string($condition) || is_int($condition)) {
            $pk = self::getPrimaryKey();
            if (is_string($pk)) {
                $aLocator[$pk] = $condition;
            } else {
                throw new DbException('模型包含多个主键：' . implode('、', $pk));
            }
        } else {
            $aLocator = [];
        }
        $query = self::setComplexQuery($aLocator, $aField, $aJoin);
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
     * @param string $field
     * @param mixed $fieldValue
     * @param array $aField
     * @param array $aJoin
     * @return self
     * @throws DbException
     */
    public static function getByField(string $field, $fieldValue, array $aField = [], array $aJoin = [])
    {
        return self::get([$field => $fieldValue], $aField, $aJoin);
    }

    /**
     * 更新数据
     * @param array $aUpdateData
     * @param array $aLocator
     * @return Model
     */
    public static function updateByLocator(array $aUpdateData, array $aLocator)
    {
        $where = [];
        self::optimizeCondition($aLocator, $where);
        return self::update($aUpdateData, $where);
    }

    /**
     * 添加或更新多条数据
     * @param array $aDataList
     * @param bool $replace
     * @return mixed
     */
    public static function setAll(array $aDataList, bool $replace = true)
    {
        if (empty($aDataList)) {
            return false;
        }
        return (new static())->saveAll($aDataList, $replace);
    }

    /**
     * @param array $aLocator
     * @param array $aField
     * @param array $aJoin
     * @param array $aSort
     * @return self
     * @throws DbException
     */
    public static function getOne(array $aLocator = [], array $aField = [], array $aJoin = [], array $aSort = [])
    {
        $list = self::getList($aLocator, $aField, $aJoin, $aSort, 1);
        if ($list->isEmpty()) {
            return $list;
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
    public static function getList(array $where = [], array $field = [], array $join = [], array $sort = [], int $limit = 0, string $group = '')
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
    public static function getPage(array $where = [], array $field = [], array $join = [], array $sort = [], int $listRows = 0, string $group = '')
    {
        // 分页配置
        $aConf = config('paginate');
        if ($listRows) {
            $aConf['list_rows'] = $listRows;
        } elseif ($submitListRows = request()->param('list_rows', 0, 'intval')) {
            $aConf['list_rows'] = $submitListRows;
        }
        $query = self::setComplexQuery($where, $field, $join, $sort, $group);
        // query
        $oPaginate = $query->paginate($aConf);
        return [
            'list' => $oPaginate->items(),
            'total_pages' => $oPaginate->lastPage(),
        ];
    }

    /**
     * 删除数据
     * @param array $aLocator
     * @return bool
     * @throws DbException
     */
    public static function deleteByLocator(array $aLocator = [])
    {
        $whereGroup = [];
        self::optimizeCondition($aLocator, $whereGroup);
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
        $aLocator = [
            $field => $value,
        ];
        $whereGroup = [];
        self::optimizeCondition($aLocator, $whereGroup);
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
