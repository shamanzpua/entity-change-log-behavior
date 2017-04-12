# Entity chane logger behavior

Logs models state before and after change

## Installation

Add to composer.json
````
 "require": {
    "shamanzpua/entity-change-log-behavior": "*"
 }
````

## Usage:

```php
 public function behaviors()
  {
      return [
          [
              'class' => EntityChangeLogBehavior::class,

              'logModelClass' => Log::class,  //ActiveRecord log table class

              'attributes' => [   //attributes of owner. Default: all attributes
                  'name',
                  'date',
                  'id',
              ],

              'columns' => [  //Required log table columns
                  'action' => 'action_column_name' // Default 'action',
                  'new_value' => 'new_value_column_name' // Default 'new_value',
                  'old_value' => 'old_value_column_name' // Default 'old_value',
              ],

              'relatedAttributes' => [   //attributes of owners relations
                  'user' => ['email'],
                  'category' => ['name'],
              ],

              'additionalLogTableFields' => [   //additional log table fields. key -> log table col, value -> owners col
                  'log_item_name' => 'title',
              ],
          ]
      ];
  }
```
