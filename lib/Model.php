<?php
/* ===========================================================================
 * Opis Project
 * http://opis.io
 * ===========================================================================
 * Copyright 2013-2015 Marius Sarca
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * ============================================================================ */

namespace Opis\Database;

use DateTime;
use Opis\Database\ORM\LazyLoader;
use RuntimeException;
use Opis\Database\ORM\Query;
use Opis\Database\ORM\Relation\HasOne;
use Opis\Database\ORM\Relation\HasMany;
use Opis\Database\ORM\Relation\BelongsTo;
use Opis\Database\ORM\Relation\BelongsToMany;

abstract class Model
{
    /**
     * Autoincrement primary key type
     *
     * @var int
     */
    const PRIMARY_KEY_AUTOINCREMENT = 1;

    /**
     * Custom primary key type
     *
     * @var int
     */
    const PRIMARY_KEY_CUSTOM = 2;

    /**
     * Model's table name
     *
     * @var string
     */
    protected $table;

    /**
     * Table's primary key
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Primary key's type
     *
     * @var int
     */
    protected $primaryKeyType = Model::PRIMARY_KEY_AUTOINCREMENT;

    /**
     * Table's associated sequence name
     *
     * @var string
     */
    protected $sequence;

    /**
     * Guarded attributes that are not mass assignable
     *
     * @var array
     */
    protected $guarded;

    /**
     * Mass asignable attributes
     *
     * @var array
     */
    protected $fillable;

    /**
     * Database instance
     *
     * @var \Opis\Database\Database
     */
    protected $database;

    /**
     * Model's short class name
     *
     * @var string
     */
    protected $className;

    /**
     * Indicates if the record is loaded
     *
     * @var boolean
     */
    protected $loaded = false;

    /**
     * Indicates if the record was deleted
     *
     * @var boolean
     */
    protected $deleted = false;

    /**
     * Indicates if the model's properties are readonly
     *
     * @var boolean
     */
    protected $readonly = false;

    /**
     * Indicates if the model instance is a new record
     *
     * @var boolean
     */
    protected $isNewRecord = true;

    /**
     * Indicates if soft deletes are supported
     *
     * @var boolean
     */
    protected $softDeletes = true;

    /**
     * Indicates if timestamps are supported
     *
     * @var boolean
     */
    protected $timestamps = true;

    /**
     * A list with related models
     *
     * @var array
     */
    protected $result = array();

    /**
     * A list with loaders
     *
     * @var array
     */
    protected $loader = array();

    /**
     * Columns' values
     *
     * @var array
     */
    protected $columns = array();

    /**
     * A list with modified columns
     *
     * @var array
     */
    protected $modified = array();

    /**
     * A list of user defined column mappings
     */
    protected $mapColumns = array();

    /**
     * Internally used column mappings
     */
    protected $mapGetSet = array();

    /**
     * A list of user defined type casts for columns
     *
     * @var array
     */
    protected $cast = array();

    /**
     * A list of custom cast callbacks
     * 
     * @var array
     */
    protected $castType = array();

    /**
     * Date format
     *
     * @var string
     */
    protected $dateFormat;

    /**
     * Database connection
     *
     * @var \Opis\Database\Connection
     */
    protected $connection;

    /**
     * A flag that indicates if exceptions are thrown
     * 
     * @var boolean
     */
    protected $throwExceptions = true;
    
    /**
     * A flag that indicates if this model needs to be reoaded
     * 
     * @var boolean
     */
    protected $dehydrated = false;

    /** @var  \Opis\Database\SQL\Query|null */
    protected $queryBuilder;

    /**
     * Constructor
     *
     * @final
     * 
     * @param   boolean $readonly   Indicates if this is a read-only model
     */
    final public function __construct(Connection $connection, bool $readonly = null)
    {
        $this->loaded = true;
        $this->connection = $connection;
        if($readonly !== null) {
            $this->readonly = $readonly;
        }
    }

    /**
     * Handles dynamic method calls into the model
     *
     * @param   string  $name       Method's name
     * @param   string  $arguments  Method's arguments
     *
     * @return  mixed
     */
    public function __call($name, array $arguments)
    {
        if (method_exists($this, $name . 'Scope')) {
            array_unshift($arguments, $this->getQueryBuilder());
            $this->{$name . 'Scope'}(...$arguments);
            return $this;
        }

        return $this->getQueryBuilder()->{$name}(...$arguments);
    }

    /**
     * Sets a columns value
     *
     * @param   string  $name   Column's name
     * @param   mixed   $value  Column's value
     */
    public function __set($name, $value)
    {
        if (!$this->loaded) {
            $this->isNewRecord = false;
            $this->columns[$name] = $value;
            return;
        }

        if ($this->deleted) {
            throw new RuntimeException('This record was deleted');
        }

        if ($this->readonly) {
            throw new RuntimeException('This model is readonly');
        }

        $this->hydrate();

        $column = $this->mapColumns[$name] ?? $name;

        if ($this->primaryKey == $column && $this->primaryKeyType === Model::PRIMARY_KEY_AUTOINCREMENT) {
            return;
        }

        $mutator = $name . 'Mutator';

        if (method_exists($this, $mutator)) {
            $value = $this->{$mutator}($value);
        }

        if (isset($this->cast[$column])) {
            $value = $this->cast($column, $value, 'set');
        }

        if (method_exists($this, $name)) {
            //TODO: This must be reviewed
            $column = $this->{$name}()->getRelatedColumn($this, $column);
            $this->result[$name] = $value;
            if ($value instanceof Model) {
                $value = $value->{$value->primaryKey};
            }
        }

        $this->modified[$column] = true;
        $this->columns[$column] = $value;
    }

    /**
     * Gets a column's value or a related model
     *
     * @param   string  $name   Key
     *
     * @return  mixed
     */
    public function __get($name)
    {
        if ($this->deleted) {
            throw new RuntimeException('This record was deleted');
        }

        $this->hydrate();

        $column = $this->mapColumns[$name] ?? $name;

        if (array_key_exists($column, $this->columns)) {
            $accesor = $name . 'Accessor';
            $value = $this->columns[$column];

            if (isset($this->cast[$column])) {
                $value = $this->cast($column, $value, 'get');
            }

            if (method_exists($this, $accesor)) {
                return $this->{$accesor}($value);
            }

            return $value;
        }

        if (array_key_exists($name, $this->result)) {
            return $this->result[$name];
        }

        if (isset($this->loader[$name])) {
            return $this->result[$name] = $this->loader[$name]->getResult($this, $name);
        }

        if (method_exists($this, $name)) {
            return $this->result[$name] = $this->{$name}()->getResult();
        }

        throw new RuntimeException("Unknown property `$name`");
    }

    /**
     * Saves this model
     *
     * @return  boolean
     */
    public function save(): bool
    {
        if ($this->deleted) {
            throw new RuntimeException('This record was deleted');
        }

        if ($this->isNewRecord) {

            $id = $this->database()
                ->transaction(function(Database $db){
                    $columns = $this->prepareColumns();
                    $customPK = $this->primaryKeyType === Model::PRIMARY_KEY_CUSTOM;

                    if ($customPK) {
                        $columns[$this->primaryKey] = $this->generatePrimaryKey();
                    }

                    if ($this->supportsTimestamps()) {
                        $columns['created_at'] = date($this->getDateFormat());
                        $columns['updated_at'] = null;
                    }

                    $db->insert($columns)->into($this->getTable());

                    return $customPK ? $columns[$this->primaryKey] : $db->getConnection()->getPDO()->lastInsertId($this->getSequence());
                })
                ->onError(function(\PDOException $e, Transaction $transaction){
                    if($this->throwExceptions){
                        throw $e;
                    }
                })
                ->execute();

            $this->modified = array();
            $this->isNewRecord = false;
            $this->columns[$this->primaryKey] = $id;
            $this->dehydrated = true;

            return (bool) $id;
        }

        if (!empty($this->modified)) {
            $columns = $this->prepareColumns(true);
            $this->modified = array();
            return $this->update($columns);
        }

        return true;
    }

    /**
     * Deletes this model
     * 
     * @param   boolean $soft   (optional) Soft delete
     * 
     * @return  boolean
     */
    public function delete(): bool
    {
        if ($this->isNewRecord) {
            throw new RuntimeException('This is a new record that was not saved yet');
        }

        if ($this->deleted) {
            throw new RuntimeException('This record was already deleted');
        }

        $result = $this->database()->from($this->getTable())
                                    ->where($this->primaryKey)->is($this->columns[$this->primaryKey])
                                    ->delete();

        $this->deleted = true;

        return (bool) $result;
    }

    /**
     * Soft-delete a record
     * 
     * @return  boolean
     * 
     * @throws  RuntimeException
     */
    public function softDelete(): bool
    {
        if ($this->isNewRecord) {
            throw new RuntimeException('This is a new record that was not saved yet');
        }

        if ($this->deleted) {
            throw new RuntimeException('This record was already deleted');
        }

        if (!$this->supportsSoftDeletes()) {
            throw new RuntimeException('Soft deletes is not supported for this model');
        }

        $result = $this->database()->update($this->getTable())
            ->where($this->primaryKey)->is($this->columns[$this->primaryKey])
            ->set(['deleted_at' => date($this->getDateFormat())]);

        $this->deleted = true;

        return (bool) $result;
    }

    /**
     * Update columns
     * 
     * @param   array   $columns
     * 
     * @return  boolean
     */
    public function update(array $columns): bool
    {
        if ($this->supportsTimestamps()) {
            $this->columns['updated_at'] = $columns['updated_at'] = date($this->getDateFormat());
        }

        return (bool) $this->database()
                ->update($this->getTable())
                ->where($this->primaryKey)->is($this->columns[$this->primaryKey])
                ->set($columns);
    }

    /**
     * Mass assign values to this model
     *
     * @param   array   $values A column-value mapped array
     * 
     * @return  $this
     */
    public function assign(array $values): self
    {
        if ($this->fillable !== null && is_array($this->fillable)) {
            $values = array_intersect_key($values, array_flip($this->fillable));
        } elseif ($this->guarded !== null && is_array($this->guarded)) {
            $values = array_diff_key($values, array_flip($this->guarded));
        }

        foreach ($values as $column => &$value) {
            $this->{$column} = $value;
        }

        return $this;
    }

    /**
     * Set a lazy loader for a property
     *
     * @param   string                          $name   Property's name
     * @param   \Opis\Database\ORM\LazyLoader   $value  Lazy loader object
     */
    public function setLazyLoader(string $name, LazyLoader $value)
    {
        $this->loader[$name] = $value;
    }

    /**
     * Get the model's associated table
     *
     * @return  string
     */
    public function getTable(): string
    {
        if ($this->table === null) {
            $this->table = strtolower(preg_replace('/([^A-Z])([A-Z])/', "$1_$2", $this->getClassShortName())) . 's';
        }

        return $this->table;
    }

    /**
     * Get the name of the primary key of the modle's associated table
     *
     * @return  string
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * Get the name of the foreign key of the modle's associated table
     *
     * @return  string
     */
    public function getForeignKey(): string
    {
        return str_replace('-', '_', strtolower(preg_replace('/([^A-Z])([A-Z])/', "$1_$2", $this->getClassShortName()))) . '_id';
    }

    /**
     * Get a DateTime format
     *
     * @return string
     */
    public function getDateFormat(): string
    {
        if ($this->dateFormat === null) {
            $this->dateFormat = $this->connection->getCompiler()->getDateFormat();
        }

        return $this->dateFormat;
    }

    /**
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Check if this model supports soft deletes
     * 
     * @return  boolean
     */
    public function supportsSoftDeletes(): bool
    {
        return $this->softDeletes && isset($this->cast['deleted_at']) && $this->cast['deleted_at'] === 'date?';
    }

    /**
     * Check if this model supports timestamps
     * 
     * @return  boolean
     */
    public function supportsTimestamps(): bool
    {
        return $this->timestamps && isset($this->cast['created_at']) && isset($this->cast['updated_at']) &&
            $this->cast['created_at'] === 'date' && $this->cast['updated_at'] === 'date?';
    }

    /**
     * Define a Has One relation
     *
     * @param   string  $model          Related model
     * @param   string  $foreignKey     (optional) Foreign key
     *
     * @return  HasOne
     */
    public function hasOne(string $model, string $foreignKey = null): HasOne
    {
        return new HasOne($this, new $model($this->connection), $foreignKey);
    }

    /**
     * Define a Has Many relation
     *
     * @param   string  $model          Related model
     * @param   string  $foreignKey     (optional) Foreign key
     *
     * @return  HasMany
     */
    public function hasMany(string $model, string $foreignKey = null): HasMany
    {
        return new HasMany($this, new $model($this->connection), $foreignKey);
    }

    /**
     * Define a Belong To relation
     *
     * @param   string  $model          Related model
     * @param   string  $foreignKey     (optional) Foreign key
     *
     * @return  BelongsTo
     */
    public function belongsTo(string $model, string $foreignKey = null): BelongsTo
    {
        return new BelongsTo($this, new $model($this->connection), $foreignKey);
    }

    /**
     * Define a Many to Many relation
     *
     * @param string $model
     * @param string|null $foreignKey
     * @param string|null $junctionTable
     * @param string|null $junctionKey
     * @return BelongsToMany
     */
    public function belongsToMany(string $model, string $foreignKey = null, string $junctionTable = null, string $junctionKey = null): BelongsToMany
    {
        return new BelongsToMany($this, new $model($this->connection), $foreignKey, $junctionTable, $junctionKey);
    }

    /**
     * Generates a unique primary key
     *
     * @return  mixed
     */
    protected function generatePrimaryKey()
    {
        throw new RuntimeException('Unimplemented method');
    }

    /**
     * Casts a column's value
     *
     * @param   string  $name   Column's name
     * @param   mixed   $value  Value to be casted
     * @param   string  $ctx    Context(get or set)
     * @return  mixed
     */
    protected function cast(string $name, $value, string $ctx)
    {
        $cast = $this->cast[$name];

        if ($cast[strlen($cast) - 1] === '?') {
            if ($value === null) {
                return null;
            }
            $cast = rtrim($cast, '?');
        }

        if (isset($this->castType[$cast])) {
            $callback = $this->castType[$cast];
            if (is_string($callback) && $callback[0] === '@') {
                $callback = array($this, substr($callback, 1));
            }
            return call_user_func($callback, $cast, $value, $ctx);
        }

        switch ($cast) {
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'string':
                return (string) $value;
            case 'boolean':
                return $ctx == 'get' ? (bool) $value : (int) $value;
            case 'json':
                return $ctx == 'get' ? json_decode($value) : json_encode($value);
            case 'date':
                return $ctx == 'get' ? DateTime::createFromFormat($this->getDateFormat(), $value) : $value;
        }

        throw new RuntimeException(vsprintf('Unknown cast type "%s"', array($cast)));
    }

    /**
     * Database instance
     *
     * @return  \Opis\Database\Database
     */
    protected function database()
    {
        if ($this->database === null) {
            $this->database = new Database($this->connection);
        }

        return $this->database;
    }

    /**
     * Prepare columns
     *
     * @param   boolean $update Indicates if this is an update operation
     *
     * @return  array
     */
    protected function prepareColumns($update = false)
    {
        $results = array();

        $columns = $update ? array_intersect_key($this->columns, $this->modified) : $this->columns;

        foreach ($columns as $column => &$value) {
            if ($value instanceof Model) {
                $results[$column] = $value->{$value->primaryKey};
                continue;
            }

            $results[$column] = &$value;
        }

        return $results;
    }

    /**
     * Returns the short class name of the model
     *
     * @return  string
     */
    protected function getClassShortName()
    {
        if ($this->className === null) {
            $name = get_class($this);

            if (false !== $pos = strrpos($name, '\\')) {
                $name = substr($name, $pos + 1);
            }

            $this->className = $name;
        }

        return $this->className;
    }

    /**
     * Sequence's name
     *
     * @return  string
     */
    protected function getSequence()
    {
        if ($this->sequence === null) {
            $this->sequence = $this->getTable() . '_' . $this->primaryKey . '_seq';
        }

        return $this->sequence;
    }

    /**
     * Returns a query builder
     *
     * @param   bool    $clean
     * @return  \Opis\Database\ORM\Query
     */
    protected function getQueryBuilder(bool $clean = false)
    {
        if($this->queryBuilder === null){
            $this->queryBuilder = new Query($this);
        }

        $qb = $this->queryBuilder;

        if($clean){
            $this->queryBuilder = null;
        }

        return $qb;
    }

    /**
     * Hydrate
     */
    protected function hydrate()
    {
        if($this->dehydrated) {
            $this->dehydrated = false;
            $this->columns = $this->getQueryBuilder(true)->find($this->columns[$this->primaryKey])->columns;
        }
    }
}
