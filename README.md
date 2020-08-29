# think-orm-trait
快速上手think orm，减少文档学习成本，开开心心敲代码，快快乐乐把家还。
准备工作：建议至少读一遍think orm的数据库与模型部分的文档。

## ModelTrait.php - 快捷查询

### 公共查询约定setComplexQuery

````php
public static function setComplexQuery(array $aLocator = [], array $aField = [], array $aJoin = [], array $aSort = [], string $group = '')
{
    //......
}
````

#### 形参说明

##### $aLocator - 查询条件

- AND查询

    ````php
  <?php
    array(
        'id' => ['IN',[1,2,6,8]],
        'sex' => 'male',
        'create_time' => ['>','2019-11-11 11:11:11'],
    );
    ````

- OR查询

    ````php
  <?php
    array(
        '{logic}' => 'OR',
        array(
            'id' => ['NOT IN',[1,2,6,8]],
        ),
        array(
            'name' => ['LIKE','%抠脚%'],
            'status' => 'active',
         ),
    );
    ````

- 每个数组的配置，你将其看作一个分组，类似sql条件中()。所以你可以根据实际情况，设置多个分组，完成复杂的查询

    ````php
  <?php
    $aLocator = [
        [
            'sex' => 'male',
            'create_time' => ['>', '2019-11-11 11:11:11'],
        ],
        [
            '{logic}' => 'OR',
            [
                'id' => ['NOT IN', [1, 2, 6, 8]],
            ],
            [
                'name' => ['LIKE', '%抠脚%'],
                'status' => 'active',
            ],
        ]
    ];
    $users = UserModel::getList($aLocator);
    // 生成的sql语句如下：
    // SELECT * FROM `user` WHERE  ( `sex` = 'male' AND `create_time` > '2019-11-11 11:11:11' )  AND (  `id` NOT IN (1,2,6,8)  OR ( `name` LIKE '%抠脚%' AND `status` = 'active' ) )
    ````

- 在$aLocator中使用hasWhere关联查询

    基本格式：'{has}.关联方法名' => array(查询条件),
    
    进阶格式：'{has}' => array('关联方法名a' => array(查询条件),'关联方法名b' => array(查询条件))
    
    ````php
  <?php
    $aLocator = [
        'sex' => 'male',
        'create_time' => ['>', '2019-11-11 11:11:11'],
        'status' => 'active',
        '{has}.account' => [
            'status' => 'active',
        ],
    ];
    $users = UserModel::getList($aLocator);
    // 生成的sql如下：
    // SELECT `UserModel`.* FROM `user` `UserModel` INNER JOIN `account` `AccountModel` ON `UserModel`.`id`=`AccountModel`.`user_id` WHERE  `AccountModel`.`status` = 'active'  AND ( `UserModel`.`sex` = 'male' AND `UserModel`.`create_time` > '2019-11-11 11:11:11' AND `UserModel`.`status` = 'active' )
    ````

#### $aField - 查询字段
查询字段没有什么好赘述的，仅支持主模型的字段。如果想要查询关联模型或关联表字段可以在形参$aJoin中设置

````php
<?php
// think orm中的一些配置转换
$aField = [
    '{visible}' => ['需要显示的字段'],
    '{hidden}' => ['需要隐藏的字段'],
    '{append}' => ['描述字段'],
];
````

#### $aJoin - 关联设置

- 关联表查询

    ````php
  <?php
    $aLocator = [
        'sex' => 'male',
        'create_time' => ['>', '2019-11-11 11:11:11'],
        'status' => 'active',
        'account.balance' => ['>=', 50000],
    ];
    $aField = ['id', 'name', 'sex'];
    $aJoin = [
        [
            // 关联表的模型类名称
            UserDetailModel::class,
            // 关联条件
            // 左侧是当前表的字段（即UserDetailModel），右侧是需要关联表中的字段
            // 如果右侧为主模型字段（即UserModel），则可以省略别名
            ['user_id' => 'id'],
            // 关联方式默认为inner，可选'LEFT|INNER|RIGHT'
            'LEFT',
        ],
        [
            // 有时候我们需要在$aLocator填写关联表某些字段为约束条件，则需要用到别名，避免字段冲突的问题
            // ['关联表的模型类名称','别名']
            [AccountModel::class, 'account'],
            ['user_id' => 'id'],
            // 关联方式默认为inner，可选'LEFT|INNER|RIGHT'
            'LEFT',
            // 查询字段
            ['balance']
        ]
    ];
    $users = UserModel::getList($aLocator, $aField, $aJoin);
    // 生成的sql如下：
    // SELECT `user`.`id`,`user`.`name`,`user`.`sex`,`account`.`balance` FROM `user` `user` LEFT JOIN `user_detail` ON `user_detail`.`user_id`=`user`.`id` LEFT JOIN `account` `account` ON `account`.`user_id`=`user`.`id` WHERE  ( `user`.`sex` = 'male' AND `user`.`create_time` > '2019-11-11 11:11:11' AND `user`.`status` = 'active' AND `account`.`balance` >= 50000 )
    ````

- 关联模型查询

    如果你配置了模型之间的关系，则join查询可以大大的简化，比如你在UserModel中配置了与AccountModel、UserDetailModel的一对一关联，我们来修改一下上面的查询方式。
    
    主模型配置
    
    ````php
  <?php
    
    
    namespace localhost\model;
    
    
    class UserModel extends BaseModel
    {
        protected string $name = 'user';
    
        protected string $pk = 'id';
    
        public function account()
        {
            return $this->hasOne(AccountModel::class, 'user_id');
        }
    
        public function detail()
        {
            return $this->hasOne(UserDetailModel::class, 'user_id');
        }
    }
    ````
    
    通过模型配置关联，简化写法，我个人比较喜欢这种写法：
    
    ````php
  <?php
    $aLocator = [
        'sex' => 'male',
        'create_time' => ['>', '2019-11-11 11:11:11'],
        'status' => 'active',
        '{has}.account' => [
            'balance' => ['>=', 50000],
        ]
    ];
    $aField = ['id', 'name', 'sex'];
    $aJoin = [
        // '{with}'.关联模型方法名 => [关联查询设置] 或一个匿名函数（见think-orm文档）
        '{with}.account' => [
            // 查询字段
            'field' => ['user_id', 'balance'],
            // 绑定到主模型的字段
            //'bind' => [],
            // 还支持嵌套关联查询，不过不推荐了
            //'join'=>[
            //    '{with}.transaction'=>[],
            //],
        ],
        '{with}.detail' => [
            'field' => ['user_id', 'address', 'telephone'],
        ],
    ];
    $users = UserModel::getList($aLocator, $aField, $aJoin);
    // 生成的sql如下：
    // SELECT `UserModel`.`id`,`UserModel`.`name`,`UserModel`.`sex` FROM `user` `UserModel` INNER JOIN `account` `AccountModel` ON `UserModel`.`id`=`AccountModel`.`user_id` WHERE  `AccountModel`.`balance` >= 50000  AND ( `UserModel`.`sex` = 'male' AND `UserModel`.`create_time` > '2019-11-11 11:11:11' AND `UserModel`.`status` = 'active' )
    ````