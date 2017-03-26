<?php

namespace LaravelRocket\Generator\Generators;

class ModelGenerator extends Generator
{

    public function generate($name, $overwrite = false, $baseDirectory = null)
    {
        $modelName = $this->getModelName($name);
        $this->generateModel($modelName);
        $this->generateModelUnitTest($modelName);
        $this->generateModelFactory($modelName);
    }

    /**
     * @param  string $name
     * @return string
     */
    protected function getModelName($name)
    {
        $className = $this->getClassName($name);

        return $className;
    }

    /**
     * @param  string $name
     * @return string
     */
    protected function getModelClass($name)
    {
        $modelName = $this->getModelName($name);

        return '\\App\\Models\\'.$modelName;
    }

    /**
     * @param string $tableName
     *
     * @return \Doctrine\DBAL\Schema\Column[]
     */
    protected function getTableColumns($tableName)
    {
        $hasDoctrine = interface_exists('Doctrine\DBAL\Driver');
        if (!$hasDoctrine) {
            return [];
        }

        $platform = \DB::getDoctrineConnection()->getDatabasePlatform();
        $platform->registerDoctrineTypeMapping('json', 'string');

        $schema = \DB::getDoctrineSchemaManager();

        $columns = $schema->listTableColumns($tableName);

        return $columns;
    }

    /**
     * @param  string $tableName
     * @return array
     */
    protected function getFillableColumns($tableName)
    {
        $ret = [];
        $columns = $this->getTableColumns($tableName);
        if ($columns) {
            foreach ($columns as $column) {
                if ($column->getAutoincrement()) {
                    continue;
                }
                $columnName = $column->getName();
                if (!in_array($columnName, ['created_at', 'updated_at', 'deleted_at'])) {
                    $ret[] = $columnName;
                }
            }
        }

        return $ret;
    }

    /**
     * @param  string $tableName
     * @return array
     */
    protected function getDateTimeColumns($tableName)
    {
        $ret = [];
        $columns = $this->getTableColumns($tableName);
        if ($columns) {
            foreach ($columns as $column) {
                if ($column->getType() != 'DateTime') {
                    continue;
                }
                $columnName = $column->getName();
                if (!in_array($columnName, ['created_at', 'updated_at'])) {
                    $ret[] = $columnName;
                }
            }
        }

        return $ret;
    }

    /**
     * @param  string $tableName
     * @return bool
     */
    protected function hasSoftDeleteColumn($tableName)
    {
        $columns = $this->getTableColumns($tableName);
        if ($columns) {
            foreach ($columns as $column) {
                $columnName = $column->getName();
                if ($columnName == 'deleted_at') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  string $modelName
     * @return string
     */
    protected function getTableName($modelName)
    {
        $modelName = $this->getModelName($modelName);

        $name = \StringHelper::pluralize(\StringHelper::camel2Snake($modelName));
        $columns = $this->getTableColumns($name);
        if (count($columns)) {
            return $name;
        }

        $name = \StringHelper::singularize(\StringHelper::camel2Snake($modelName));
        $columns = $this->getTableColumns($name);
        if (count($columns)) {
            return $name;
        }

        return \StringHelper::pluralize(\StringHelper::camel2Snake($modelName));
    }

    /**
     * @return string[]
     */
    protected function getTableList()
    {
        $hasDoctrine = interface_exists('Doctrine\DBAL\Driver');
        if (!$hasDoctrine) {
            return [];
        }

        $tables = \DB::getDoctrineSchemaManager()->listTables();
        $ret = [];
        foreach ($tables as $table) {
            $ret[] = $table->getName();
        }

        return $ret;
    }

    /**
     * @param  string $modelName
     * @return bool
     */
    protected function generateModel($modelName)
    {
        $className = $this->getModelClass($modelName);
        $classPath = $this->convertClassToPath($className);

        $stubFilePath = __DIR__.'../stubs/repository/model.stub';

        $tableName = $this->getTableName($modelName);
        $columns = $this->getFillableColumns($tableName);
        $fillables = count($columns) > 0 ? "'".implode("',".PHP_EOL."        '", $columns)."'," : '';

        return $this->generateFile($modelName, $classPath, $stubFilePath, [
            'TABLE'                  => $tableName,
            'FILLABLES'              => $fillables,
            'SOFT_DELETE_CLASS_USE%' => '',
            'SOFT_DELETE_USE'        => '',
            'DATETIMES'              => '',
            'RELATIONS'              => '',
        ]);
    }

    protected function generateModelRelation($modelName)
    {
        $relations = "";
        $tables = $this->getTableList();

        $tableName = $this->getTableName($modelName);
        $columns = $this->getFillableColumns($tableName);

        foreach ($columns as $column) {
            $columnName = $column->getName();
            if (preg_match('/^(.*_image)_id$/', $columnName, $matches)) {

                $relationName = \StringHelper::snake2Camel($matches[1]);
                $relations .= '    public function '.$relationName.'()'.PHP_EOL.'    {'.PHP_EOL.'        return $this->hasOne(\App\Models\Image::class, \'id\', \''.$columnName.'\');'.PHP_EOL.'    }'.PHP_EOL.PHP_EOL;
            } elseif (preg_match('/^(.*)_id$/', $columnName, $matches)) {
                $relationName = \StringHelper::snake2Camel($matches[1]);
                $className = ucfirst($relationName);
                if (!$this->getPath($className)) {
                    continue;
                }
                $relations .= '    public function '.$relationName.'()'.PHP_EOL.'    {'.PHP_EOL.'        return $this->belongsTo(\App\Models\\'.$className.'::class, \''.$columnName.'\', \'id\');'.PHP_EOL.'    }'.PHP_EOL.PHP_EOL;
            }
        }

        return $relations;
    }

    /**
     * @param  string $modelName
     * @return bool
     */
    protected function generateModelUnitTest($modelName)
    {
        $classPath = base_path('/../tests/Repositories/'.$modelName.'RepositoryTest.php');
        $modelClass = $this->getModelClass($modelName);
        $instance = new $modelClass();

        $stubFilePath = __DIR__.'../stubs/repository/repository_unittest.stub';
        if ($instance instanceof \LaravelRocket\Foundation\Models\AuthenticatableBase) {
            $stubFilePath = __DIR__.'../stubs/repository/repository_unittest.stub';
        }

        return $this->generateFile($modelName, $classPath, $stubFilePath);
    }

    /**
     * @param  string $modelName
     * @return bool
     */
    protected function generateModelFactory($modelName)
    {
        $className = $this->getModelClass($modelName);
        $tableName = $this->getTableName($modelName);

        $columns = $this->getFillableColumns($tableName);

        $factoryPath = base_path('/../database/factories/ModelFactory.php');
        $key = '/* NEW MODEL FACTORY */';

        $data = '$factory->define(App\Models\\'.$className.'::class, function (Faker\Generator $faker) {'.PHP_EOL.'    return ['.PHP_EOL;
        foreach ($columns as $column) {
            if (preg_match('/_id$/', $column->getName())) {
                $defaultValue = 0;
            } else {
                $defaultValue = "''";
            }
            $data .= "        '".$column->getName()."' => ".$defaultValue.",".PHP_EOL;
        }
        $data .= '    ];'.PHP_EOL.'});'.PHP_EOL.PHP_EOL.$key;

        $this->replaceFile([
            $key => $data,
        ], $factoryPath);

        return true;
    }
}
