<?php
namespace shamanzpua\behaviors;

use yii\db\ActiveRecord;
use yii\db\ActiveRecordInterface;
use yii\helpers\Json;
use yii\helpers\ArrayHelper;
use yii\helpers\StringHelper;
use yii\helpers\Inflector;
use Yii;

/**
 * Behavior logs entity changes.
 *
 * For example:
 *
 * ```php
 * public function behaviors()
 *  {
 *      return [
 *          [
 *              'class' => EntityChangeLogBehavior::class,
 *
 *              'logModelClass' => Log::class,  //ActiveRecord log table class
 *
 *              'attributes' => [   //attributes of owner. Default: all attributes
 *                  'name',
 *                  'date',
 *                  'id',
 *              ],
 *
 *              'columns' => [  //Required log table columns
 *                  'action' => 'action_column_name' // Default 'action',
 *                  'new_value' => 'new_value_column_name' // Default 'new_value',
 *                  'old_value' => 'old_value_column_name' // Default 'old_value',
 *              ],
 *
 *              'relatedAttributes' => [   //attributes of owners relations
 *                  'user' => ['email'],
 *                  'category' => ['name'],
 *              ],
 *
 *              'additionalLogTableFields' => [   //additional log table fields. key -> log table col, value -> owners col
 *                  'log_item_name' => 'title',
 *              ],
 *          ]
 *      ];
 *  }
 * ```
 *
 * @author shamanzpua
 * @package shamanzpua\behaviors
 */
class EntityChangeLogBehavior extends \yii\base\Behavior
{

    //default actions
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_CREATE = 'create';
    
    //state constants
    const IS_NEW_TRUE = true;
    const IS_NEW_FALSE = false;

    //const LOG_TABLE_FIELDS
    const LOG_TABLE_NEW_VALUE = 'new_value';
    const LOG_TABLE_OLD_VALUE = 'old_value';
    const LOG_TABLE_ACTION = 'action';
    const LOG_TABLE_ENTITY_NAME = 'entity';
    
    /**
     * @var ActiveRecordInterface old owner state
     */
    protected $oldOwnerState;

    /**
     * @var array | null old state of owner relations
     */
    protected $oldOwnerRelations;

    /**
     * @var array [ $logTableField => $ownerTableField ]
     */
    public $additionalLogTableFields = [];

    /**
     * @var string class name of log model class ActiveRecordInterface::class
     */
    public $logModelClass;
    
    /**
     * @var string class name of log model class ActiveRecordInterface::class
     */
    protected $logModel;

    /**
     * @var array of owner model attributes
     */
    public $attributes = [];

    
    /**
     * @var array of relations atributes
     */
    public $relatedAttributes = [];
    
    
    /**
     * @var array of log table fields
     */
    public $columns = [];
    
    /**
     * @var array of default log table fields
     */
    protected $defaultColumns = [
        self::LOG_TABLE_ACTION => 'action',
        self::LOG_TABLE_NEW_VALUE => 'new_value',
        self::LOG_TABLE_OLD_VALUE => 'old_value',
        self::LOG_TABLE_ENTITY_NAME => null,
    ];

    /**
     * @return array 
     */
    public function getLogTableColumns()
    {
        return ArrayHelper::merge($this->defaultColumns, $this->columns);
    }


    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDeleteLog',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdateLog',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsertLog',
            ActiveRecord::EVENT_AFTER_FIND => 'afterFindEvent',
        ];
    }

    /**
     * @inheritdoc
     */
    public function __construct($config = array())
    {
        parent::__construct($config);

        if (!is_array($this->attributes)) {
            throw new \yii\base\InvalidConfigException("'attributes' should be an array");
        }

        if (!is_array($this->relatedAttributes)) {
            throw new \yii\base\InvalidConfigException("'relatedAttributes' should be an array");
        }
        
        $this->logModel = Yii::createObject($this->logModelClass);
        
        if (($this->logModel instanceof ActiveRecordInterface) === false) {
            throw new \yii\base\InvalidConfigException("'logModelClass' should implements ActiveRecordInterface");
        }
        
        $columns = $this->getLogTableColumns();
        array_walk($columns, function ($column, $param) {
            if (!$this->logModel->hasAttribute($column) && $column != null) {
                throw new \yii\base\InvalidConfigException($this->logModel->className() . " doesn't have field '$column'");
            }
        });
    }

    /**
     * @inheritdoc
     */
    public function afterFindEvent()
    {
        if (($this->owner instanceof ActiveRecordInterface) === false) {
            throw new \yii\di\NotInstantiableException('Behavior should be attach to ActiveRecordInterface class');
        }
        
        $this->setOldState(clone $this->owner);

        array_walk($this->relatedAttributes, function ($value, $relation) {
            $this->oldOwnerRelations[$relation] = $this->getRelatedModels($relation);
        });
    }

    /**
     * Get owner entity name
     * @return string
     */
    public function getLogEntityName()
    {
        return Inflector::titleize(StringHelper::basename($this->owner->className()));
    }
    
    /**
     * save data for log
     * @param string $action 
     */
    public function saveLogData($action)
    {
        $logTableFields = $this->getLogTableColumns();
        
        $log = $this->logModel;
        $oldData = ($action === self::ACTION_CREATE) ? [] : $this->oldData();
        $newData = ($action === self::ACTION_DELETE) ? [] : $this->newData();
        $attributes = [
            $logTableFields[self::LOG_TABLE_OLD_VALUE] => Json::encode($oldData),
            $logTableFields[self::LOG_TABLE_NEW_VALUE] => Json::encode($newData),
            $logTableFields[self::LOG_TABLE_ACTION] => $action,
            $logTableFields[self::LOG_TABLE_ENTITY_NAME] => $this->getLogEntityName(),
        ];
        
        array_walk($this->additionalLogTableFields, function ($modelAttribute, $logTableField) use (&$attributes) {
            $attributes[$logTableField] = $this->owner->$modelAttribute;
        });

        $log->setAttributes($attributes);
        $log->save();
    }

    /**
     * get old data of owner model
     * @return array
     */
    protected function oldData()
    {
        return ($this->oldOwnerState)
        ? $this->attachRelatedAttributes($this->getLogAttributes($this->oldOwnerState), self::IS_NEW_FALSE)
        : [];
    }

    /**
     * get new data of owner model
     * @return array
     */
    protected function newData()
    {
        return $this->attachRelatedAttributes(
            $this->getLogAttributes($this->owner),
            self::IS_NEW_TRUE
        );
    }

    /**
     * Get loggin model attribute => value array
     * @param ActiveRecord $model
     * @return array
     */
    protected function getLogAttributes($model)
    {
        if (empty($this->attributes)) {
            $this->attributes = $model->attributes();
        }

        $data = $this->getModelAttributes($model, $this->attributes);

        return $data;
    }

    /**
     * Get loggin model relations attribute => value arrays
     * @param array $data
     * @param boolean $new
     * @return array
     */
    protected function attachRelatedAttributes($data, $new = self::IS_NEW_TRUE)
    {
        if (empty($this->relatedAttributes)) {
            return $data;
        }

        array_walk($this->relatedAttributes, function ($attributes, $relation) use (&$data, &$new) {
            if (empty($attributes)) {
                $attributes = $this->getRelatedModel($relation)->attributes();
            }
            
            $models = ($new) ? $this->getRelatedModels($relation) : $this->getOldRelatedModels($relation);
            
            array_walk($models, function ($model, $key) use (&$data, &$attributes, &$relation) {
                $data[$relation][] = $this->getModelAttributes($model, $attributes);
            });
        });
        
        return $data;
    }

    /**
     * @param ActiveRecordInterface $model
     * @param array $attributes
     * @return array
     */
    public function getModelAttributes(ActiveRecordInterface $model, $attributes = [])
    {
        $data = [];
        if (empty($attributes)) {
            return $data;
        }
        
        foreach ($attributes as $attribute) {
            $data[$attribute] = $model->getAttribute($attribute);
        }
        return $data;
    }

    /**
     * Get related models
     *
     * @param string $relation
     * @return array | null
     */
    public function getRelatedModels($relation)
    {
        return $this->getRelation($relation)->all();
    }
    
    /**
     * Get related model
     *
     * @param string $relation
     * @return ActiveRecordInterface | null
     */
    public function getRelatedModel($relation)
    {
        return $this->getRelation($relation)->one();
    }
    
    /**
     * Get relation
     *
     * @param string $relation
     * @return yii\db\ActiveQueryInterface
     * @throws \yii\base\InvalidConfigException
     */
    public function getRelation($relation)
    {
        try {
            return $this->owner->getRelation($relation);
        } catch (yii\base\InvalidParamException $ex) {
            throw new \yii\base\InvalidConfigException($this->owner->className() . " doesn't have '$relation' relation");
        }
    }
    
    

    /**
     * @param string $relation
     * @return array | null
     */
    public function getOldRelatedModels($relation)
    {
        return $this->oldOwnerRelations[$relation];
    }

    /**
     * @inheritdoc
     */
    public function afterDeleteLog()
    {
        $this->saveLogData(self::ACTION_DELETE);
    }

    /**
     * @inheritdoc
     */
    public function afterUpdateLog()
    {
        $this->saveLogData(self::ACTION_UPDATE);
    }

    /**
     * @inheritdoc
     */
    public function afterInsertLog()
    {
        $this->saveLogData(self::ACTION_CREATE);
    }

    /**
     * @param ActiveRecordInterface $value
     */
    public function setOldState($value)
    {
        $this->oldOwnerState = $value;
    }

    /**
     * @return ActiveRecordInterface
     */
    public function getOldState()
    {
        return $this->oldOwnerState;
    }
}
