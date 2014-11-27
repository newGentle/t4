<?php

namespace T4\Dbal\Drivers;

use T4\Core\Collection;
use T4\Dbal\Connection;
use T4\Dbal\IDriver;
use T4\Dbal\QueryBuilder;
use T4\Orm\Model;

class Pgsql
    implements IDriver
{
    use TPgsqlQueryBuilder;

    protected function createColumnDDL($options)
    {
        switch ($options['type']) {
            case 'pk':
                return 'BIGSERIAL PRIMARY KEY';
            case 'relation':
            case 'link':
                return 'BIGINT UNSIGNED NOT NULL DEFAULT \'0\'';
            case 'boolean':
                return 'BOOLEAN';
            case 'int':
                return 'INT';
            case 'float':
                return 'FLOAT';
            case 'text':
                $options['length'] = isset($options['length']) ? $options['length'] : '';
                switch (strtolower($options['length'])) {
                    case 'tiny':
                    case 'small':
                        return 'TINYTEXT';
                    case 'medium':
                        return 'MEDIUMTEXT';
                    case 'long':
                    case 'big':
                        return 'LONGTEXT';
                    default:
                        return 'TEXT';
                }
            case 'datetime':
                return 'DATETIME';
            case 'date':
                return 'DATE';
            case 'time':
                return 'TIME';
            case 'char':
                return 'CHAR(' . (isset($options['length']) ? (int)$options['length'] : 255) . ')';
            case 'string':
            default:
                return 'VARCHAR(' . (isset($options['length']) ? (int)$options['length'] : 255) . ') NOT NULL';
        }
    }

    protected function createIndexDDL($name, $options)
    {

        if (!isset($options['type']))
            $options['type'] = '';

        $ddl = '("' . implode('","', $options['columns']) . '")';

        switch ($options['type']) {
            case 'primary':
                return 'CONSTRAINT ' . $name . ' PRIMARY KEY ' . $ddl;
            case 'unique':
                return 'CONSTRAINT ' . $name . ' UNIQUE ' . $ddl;
            default:
                assert(false);
                return 'INDEX ' . $ddl;
        }

    }

    public function createTable(Connection $connection, $tableName, $columns = [], $indexes = [], $extensions = [])
    {

        foreach ($extensions as $extension) {
            $extensionClassName = '\\T4\\Orm\\Extensions\\' . ucfirst($extension);
            $extension = new $extensionClassName;
            $columns = $extension->prepareColumns($columns);
            $indexes = $extension->prepareIndexes($indexes);
        }

        $sql = 'CREATE TABLE "' . $tableName . '"';

        $columnsDDL = [];
        $indexesDDL = [];

        $hasPK = false;
        foreach ($columns as $name => $options) {
            $columnsDDL[] = '"' . $name . '" ' . $this->createColumnDDL($options);
            if ('pk' == $options['type']) {
                $hasPK = true;
            }
            if ('link' == $options['type']) {
                $indexesDDL[] = 'INDEX "' . $name . '`' . ' (`' . $name . '")';
            }
        }
        if (!$hasPK) {
            array_unshift($columnsDDL, '"' . Model::PK . '" ' . $this->createColumnDDL(['type' => 'pk']));
       }

        foreach ($indexes as $name => $options) {
            if (is_numeric($name)) {
                if ($options['type'] == 'primary') {
                    $name = $tableName . '__' . implode('_', $options['columns']) . '_pkey';
                } else {
                    $name = $tableName . '__' . implode('_', $options['columns']) . '_key';
                }
            }
            $indexesDDL[] = $this->createIndexDDL($name, $options);
        }

        $sql .= ' ( ' .
            implode(', ', array_unique($columnsDDL)) . ', ' .
            implode(', ', array_unique($indexesDDL)) .
            ' )';
        $connection->execute($sql);

    }

    public function existsTable(Connection $connection, $tableName)
    {
        $sql = 'SELECT COUNT(*) FROM pg_tables where tablename=:table';
        $result = $connection->query($sql, [':table' => $tableName]);
        return 0 != $result->fetchScalar();
    }

    public function renameTable(Connection $connection, $tableName, $tableNewName)
    {
        $sql = 'RENAME TABLE `' . $tableName . '` TO `' . $tableNewName . '`';
        $connection->execute($sql);
    }

    public function truncateTable(Connection $connection, $tableName)
    {
        $connection->execute('TRUNCATE TABLE `' . $tableName . '`');
    }

    public function dropTable(Connection $connection, $tableName)
    {
        $connection->execute('DROP TABLE `' . $tableName . '`');
    }

    public function addColumn(Connection $connection, $tableName, array $columns)
    {
        $sql = 'ALTER TABLE `' . $tableName . '`';
        $columnsDDL = [];
        foreach ($columns as $name => $options) {
            $columnsDDL[] = 'ADD COLUMN `' . $name . '` ' . $this->createColumnDDL($options);
        }
        $sql .= ' ' .
            implode(', ', $columnsDDL) .
            '';
        $connection->execute($sql);
    }

    public function dropColumn(Connection $connection, $tableName, array $columns)
    {
        $sql = 'ALTER TABLE `' . $tableName . '`';
        $columnsDDL = [];
        foreach ($columns as $name) {
            $columnsDDL[] = 'DROP COLUMN `' . $name . '`';
        }
        $sql .= ' ' .
            implode(', ', $columnsDDL) .
            '';
        $connection->execute($sql);
    }

    public function renameColumn(Connection $connection, $tableName, $oldName, $newName)
    {
        $sql = 'SHOW CREATE TABLE `' . $tableName . '`';
        $result = $connection->query($sql)->fetch()['Create Table'];
        preg_match('~^[\s]+\`'.$oldName.'\`[\s]+(.*?)[\,]?$~m', $result, $m);
        $sql = '
            ALTER TABLE `' . $tableName . '`
            CHANGE `' . $oldName . '` `' . $newName . '` ' . $m[1];
        $connection->execute($sql);
    }

    public function addIndex(Connection $connection, $tableName, array $indexes)
    {
        $sql = 'ALTER TABLE `' . $tableName . '`';
        $indexesDDL = [];
        foreach ($indexes as $name => $options) {
            $indexesDDL[] = 'ADD ' . $this->createIndexDDL($name, $options);
        }
        $sql .= ' ' .
            implode(', ', $indexesDDL) .
            '';
        $connection->execute($sql);
    }

    public function dropIndex(Connection $connection, $tableName, array $indexes)
    {
        $sql = 'ALTER TABLE `' . $tableName . '`';
        $indexesDDL = [];
        foreach ($indexes as $name) {
            $indexesDDL[] = 'DROP INDEX `' . $name . '`';
        }
        $sql .= ' ' .
            implode(', ', $indexesDDL) .
            '';
        $connection->execute($sql);
    }

    public function insert(Connection $connection, $tableName, array $data)
    {
        $sql  = 'INSERT INTO `' . $tableName . '`';
        $sql .= ' (`' . implode('`, `', array_keys($data)) . '`)';
        $sql .= ' VALUES';
        $values = [];
        foreach ($data as $key => $val)
            $values[':'.$key] = $val;
        $sql .= ' (' . implode(', ', array_keys($values)) . ')';
        $connection->execute($sql, $values);
        return $connection->lastInsertId();
    }

    public function findAllByQuery($class, $query, $params=[])
    {
        if ($query instanceof QueryBuilder) {
            $params = $query->getParams();
            $query = $query->getQuery();
        }
        $result = $class::getDbConnection()->query($query, $params)->fetchAll(\PDO::FETCH_CLASS, $class);
        if (!empty($result)) {
            $ret = new Collection($result);
            $ret->setNew(false);
        } else {
            $ret = new Collection();
        }
        return $ret;
    }

    public function findByQuery($class, $query, $params = [])
    {
        if ($query instanceof QueryBuilder) {
            $params = $query->getParams();
            $query = $query->getQuery();
        }
        $result = $class::getDbConnection()->query($query, $params)->fetchObject($class);
        if (!empty($result))
            $result->setNew(false);
        return $result;
    }

    public function findAll($class, $options = [])
    {
        $query = new QueryBuilder();
        $query
            ->select('*')
            ->from($class::getTableName())
            ->where(!empty($options['where']) ? $options['where'] : '')
            ->order(!empty($options['order']) ? $options['order'] : '')
            ->limit(!empty($options['limit']) ? $options['limit'] : '')
            ->params(!empty($options['params']) ? $options['params'] : []);
        return $this->findAllByQuery($class, $query);
    }

    public function findAllByColumn($class, $column, $value, $options = [])
    {
        $query = new QueryBuilder();
        $query
            ->select('*')
            ->from($class::getTableName())
            ->where('`' . $column . '`=:value' . (!empty($options['where']) ? ' AND (' . $options['where'] . ')' : ''))
            ->order(!empty($options['order']) ? $options['order'] : '')
            ->limit(!empty($options['limit']) ? $options['limit'] : '')
            ->params([':value' => $value]);
        return $this->findAllByQuery($class, $query);
    }

    public function findByColumn($class, $column, $value, $options = [])
    {
        $query = new QueryBuilder();
        $query
            ->select('*')
            ->from($class::getTableName())
            ->where('`' . $column . '`=:value')
            ->order(!empty($options['order']) ? $options['order'] : '')
            ->limit(1)
            ->params([':value' => $value]);
        return $this->findByQuery($class, $query);
    }

    public function countAll($class, $options = [])
    {
        $query = new QueryBuilder();
        $query
            ->select('COUNT(*)')
            ->from($class::getTableName())
            ->where(!empty($options['where']) ? $options['where'] : '')
            ->params(!empty($options['params']) ? $options['params'] : []);

        return $class::getDbConnection()->query($query->getQuery(), $query->getParams())->fetchScalar();
    }

    public function countAllByColumn($class, $column, $value, $options = [])
    {
        $query = new QueryBuilder();
        $query
            ->select('COUNT(*)')
            ->from($class::getTableName())
            ->where('`' . $column . '`=:value')
            ->params([':value' => $value]);

        return $class::getDbConnection()->query($query->getQuery(), $query->getParams())->fetchScalar();
    }

    /**
     * TODO: много лишних isset, которые всегда true по определению
     * Сохранение полей модели без учета связей, требующих ID модели
     * @param Model $model
     * @return Model
     */
    protected function saveColumns(Model $model)
    {
        $class = get_class($model);
        $columns = $class::getColumns();
        $relations = $class::getRelations();
        $cols = [];
        $sets = [];
        $data = [];

        foreach ($columns as $column => $def) {
            if (isset($model->{$column}) && !is_null($model->{$column})) {
                $cols[] = $column;
                $sets[] = '`' . $column . '`=:' . $column;
                $data[':'.$column] = $model->{$column};
            } elseif (isset($def['default'])) {
                $sets[] = '`' . $column . '`=:' . $column;
                $data[':'.$column] = $def['default'];
            }
        }

        foreach ($relations as $rel => $def) {
            switch ($def['type']) {
                case $class::HAS_ONE:
                case $class::BELONGS_TO:
                    $column = $class::getRelationLinkName($def);
                    if (!in_array($column, $cols)) {
                        if (isset($model->{$column}) && !is_null($model->{$column})) {
                            $sets[] = '`' . $column . '`=:' . $column;
                            $data[':'.$column] = $model->{$column};
                        } elseif (isset($model->{$rel}) && $model->{$rel} instanceof Model) {
                            $sets[] = '`' . $column . '`=:' . $column;
                            $data[':'.$column] = $model->{$rel}->getPk();
                        }
                    }
                    break;
            }
        }

        $connection = $class::getDbConnection();
        if ($model->isNew()) {
            $sql = '
                INSERT INTO `' . $class::getTableName() . '`
                SET ' . implode(', ', $sets) . '
            ';
            $connection->execute($sql, $data);
            $model->{$class::PK} = $connection->lastInsertId();
        } else {
            $sql = '
                UPDATE `' . $class::getTableName() . '`
                SET ' . implode(', ', $sets) . '
                WHERE `' . $class::PK . '`=\'' . $model->{$class::PK} . '\'
            ';
            $connection->execute($sql, $data);
        }

        return $model;

    }

    public function save(Model $model)
    {
        $class = get_class($model);
        $relations = $class::getRelations();
        $connection = $class::getDbConnection();

        /*
         * TODO это тут лишнее, перенести в saveColumns
         * Сохраняем связанные данные, которым не требуется ID нашей записи
         */
        foreach ($relations as $key => $relation) {
            switch ($relation['type']) {
                case $class::HAS_ONE:
                case $class::BELONGS_TO:
                    $column = $class::getRelationLinkName($relation);
                    if (!empty($model->{$key}) && $model->{$key} instanceof Model ) {
                        if ( $model->{$key}->isNew() ) {
                            $model->{$key}->save();
                        }
                        $model->{$column} = $model->{$key}->getPk();
                    }
                    break;
            }
        }

        /*
         * Сохраняем поля самой модели
         */
        $this->saveColumns($model);

        /*
        * И еще раз сохраняем связанные данные, которым требовался ID нашей записи
        */
        foreach ($relations as $key => $relation) {
            switch ($relation['type']) {

                case $class::HAS_MANY:
                    if (!empty($model->{$key}) && $model->{$key} instanceof Collection ) {
                        $column = $class::getRelationLinkName($relation);
                        foreach ( $model->{$key} as $subModel) {
                            $subModel->{$column} = $model->getPk();
                            $subModel->save();
                        }
                    }
                    break;

                case $class::MANY_TO_MANY:
                    if (!empty($model->{$key}) && $model->{$key} instanceof Collection ) {
                        $sets = [];
                        foreach ( $model->{$key} as $subModel ) {
                            if ($subModel->isNew()) {
                                $this->saveColumns($subModel);
                            }
                            $sets[] = '(' . $model->getPk() . ',' . $subModel->getPk() . ')';
                        }

                        $table = $class::getRelationLinkName($relation);
                        $sql = 'DELETE FROM `' . $table . '` WHERE `' . $class::getManyToManyThisLinkColumnName() . '`=:id';
                        $connection->execute($sql, [':id'=>$model->getPk()]);
                        if (!empty($sets)) {
                            $sql = 'INSERT INTO `' . $table . '`
                                    (`' . $class::getManyToManyThisLinkColumnName() . '`, `' . $class::getManyToManyThatLinkColumnName($relation) . '`)
                                    VALUES
                                    ' . (implode(', ', $sets)) . '
                                    ';
                            $connection->execute($sql);
                        }
                    }
                    break;

            }
        }

    }

    public function delete(Model $model)
    {

        $class = get_class($model);
        $connection = $class::getDbConnection();

        $sql = '
            DELETE FROM `' . $class::getTableName() . '`
            WHERE `' . $class::PK . '`=\'' . $model->{$class::PK} . '\'
        ';
        $connection->execute($sql);

    }

}