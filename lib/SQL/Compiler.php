<?php
/* ===========================================================================
 * Opis Project
 * http://opis.io
 * ===========================================================================
 * Copyright 2013 Marius Sarca
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

namespace Opis\Database\SQL;

use DateTime;


class Compiler
{
    /** @var string Date format. */
    protected  $dateFormat = 'Y-m-d H:i:s';

    /** @var string Wrapper used to escape table and column names. */
    protected $wrapper = '"%s"';
    
    protected $params = array();
    
    protected function wrap($value)
    {
        if($value instanceof Expression)
        {
            return $this->handleExpressions($value->getExpressions());
        }
        
        $wrapped = array();
        
        foreach(explode('.', $value) as $segment)
        {
            if($segment == '*')
            {
                $wrapped[] = $segment;
            }
            else
            {
                $wrapped[] = sprintf($this->wrapper, $segment);
            }
        }
        
        return implode('.', $wrapped);
    }
    
    protected function param($value)
    {
        if($value instanceof Expression)
        {
            return $this->handleExpressions($value->getExpressions());
        }
        elseif($value instanceof DateTime)
        {
            $this->params[] = $value->format($this->dateFormat);
        }
        else
        {
            $this->params[] = $value;
        }
        return '?';
    }
    
    public function params(array $params)
    {
        return implode(', ', array_map(array($this, 'param'), $params));
    }
    
    public function columns(array $columns)
    {
        return implode(', ', array_map(array($this, 'wrap'), $columns));
    }
    
    public function getParams()
    {
        $params = $this->params;
        $this->params = array();
        return $params;
    }
    
    protected function handleExpressions(array $expressions)
    {
        $sql = array();
        //print_r($expressions); die('xx');
        foreach($expressions as $expr)
        {
            switch($expr['type'])
            {
                case 'column':
                    $sql[] = $this->wrap($expr['value']);
                    break;
                case 'op':
                    $sql[] = $expr['value'];
                    break;
                case 'value':
                    $sql[] = $this->param($expr['value']);
                    break;
                case 'group':
                    $sql[] = '(' . $this->handleExpressions($expr['value']->getExpressions()) . ')';
                    break;
                case 'function':
                    $sql[] = $this->handleSqlFunction($expr['value']);
                    break;
                case 'subquery':
                    $sql[] = '(' . $expr['value'] . ')';
                    break;
            }
        }
        
        return implode(' ', $sql);
    }
    
    protected function handleSqlFunction(array $func)
    {
        $method = $func['type'] . $func['name'];
        return $this->$method($func);
    }
    
    protected function handleTables(array $tables)
    {
        if(empty($tables))
        {
            return '';
        }
        
        $sql = array();
        
        foreach($tables as $name => $alias)
        {
            if(is_string($name))
            {
                $sql[] = $this->wrap($name) . ' AS ' . $this->wrap($alias);
            }
            else
            {
                $sql[] = $this->wrap($alias);
            }
        }
        return implode(', ', $sql);
    }
    
    
    protected function handleColumns(array $columns)
    {
        if(empty($columns))
        {
            return '*';
        }
        
        $sql = array();

        foreach($columns as $column)
        {
            if($column['alias'] !== null)
            {
                $sql[] = $this->wrap($column['name']) . ' AS ' .$this->wrap($column['alias']);
            }
            else
            {
                $sql[] = $this->wrap($column['name']);
            }
        }
        return implode(', ', $sql);
    }
    
    public function handleInto($table, $database)
    {
        if($table === null)
        {
            return '';
        }
        return ' INTO ' . $this->wrap($table) . ($database === null ? '' : ' IN ' . $this->wrap($database));
    }
    
    protected function handleWheres(array $wheres, $prefix = true)
    {
        if(empty($wheres))
        {
            return '';
        }
        
        $sql[] = $this->$wheres[0]['type']($wheres[0]);
        
        $count = count($wheres);
        
        for($i = 1; $i < $count; $i++)
        {
            $sql[] = $wheres[$i]['separator'] .' '. $this->$wheres[$i]['type']($wheres[$i]);
        }
        
        return ($prefix ? ' WHERE ' : '') . implode(' ', $sql);
    }
    
    protected function handleGroupings(array $grouping)
    {
        return empty($grouping) ? '' : ' GROUP BY ' . $this->columns($grouping);
    }
    
    protected function handleJoins(array $joins)
    {
        if(empty($joins))
        {
            return '';
        }
        $sql = array();
        foreach($joins as $join)
        {
            $sql[] = $join['type'] . ' JOIN '. $this->handleTables($join['table']) . ' ON ' . $this->handleJoinCondtitions($join['join']->getJoinConditions());
        }
        return ' ' . implode(' ', $sql);
    }
    
    protected function handleJoinCondtitions(array $conditions)
    {
        $sql[] = $this->$conditions[0]['type']($conditions[0]);
        $count = count($conditions);
        for($i = 1; $i < $count; $i++)
        {
            $sql[] = $conditions[$i]['separator'] . ' ' . $this->$conditions[$i]['type']($conditions[$i]);
        }
        return implode(' ', $sql);
    }
    
    protected function handleHavings(array $having)
    {
        if(empty($having))
        {
            return '';
        }
        $sql[] = $this->wrap($having[0]['column']) . ' ' . $having[0]['operator'] . ' ' . $this->param($having[0]['value']);
       
        $count = count($having);
        
        for($i = 1; $i < $count; $i++)
        {
            $sql[] = $having[$i]['separator'] .' '. $this->wrap($having[$i]['column']) . ' ' . $having[$i]['operator'] . ' ' . $this->param($having[$i]['value']);
        }
        
        return ' HAVING ' . implode(' ', $sql);
    }
    
    protected function handleOrderings(array $ordering)
    {
        if(empty($ordering))
        {
            return '';
        }
        
        $sql = array();
        
        foreach($ordering as $order)
        {
            $sql[] = $this->columns($order['columns']) . ' ' . $order['order'];
        }
        
        return ' ORDER BY ' . implode(', ', $sql);
    }
    
    protected function handleSetColumns(array $columns)
    {
        if(empty($columns))
        {
            return '';
        }
        
        $sql = array();
        
        foreach($columns as $column)
        {
            $sql[] = $this->wrap($column['column']) . ' = ' . $this->param($column['value']);
        }
        
        return ' SET ' . implode(', ', $sql);
    }
    
    protected function handleInsertValues(array $values)
    {
        $sql = array();
        
        foreach($values as $insert)
        {
            $sql[] = '(' . $this->params($insert) . ')';
        }
        
        return ' VALUES ' . implode(', ', $sql);
    }
    
    protected function handleLimit($limit)
    {
        return ($limit === null) ? '' : ' LIMIT ' . $this->param($limit);
    }
    
    protected function handleOffset($offset)
    {
        return ($offset === null) ? '' : ' OFFSET ' . $this->param($offset);
    }
    
    
    protected function joinColumn(array $join)
    {
        return $this->wrap($join['column1']) . ' ' . $join['operator'] . ' ' . $this->wrap($join['column2']);
    }
    
    protected function joinNested(array $join)
    {
        return '(' . $this->handleJoinCondtitions($join['join']->getJoinCOnditions()) . ')';
    }
    
    protected function whereColumn(array $where)
    {
        return $this->wrap($where['column']) . ' ' .$where['operator'] . ' ' .$this->param($where['value']);
    }
    
    protected function whereIn(array $where)
    {
        return $this->wrap($where['column']) . ' ' . ($where['not'] ? 'NOT IN ': 'IN ') . '(' . $this->params($where['value']) . ')';
    }
    
    protected function whereInSelect(array $where)
    {
        return $this->wrap($where['column']) . ' ' . ($where['not'] ? 'NOT IN ' : 'IN ') . '('.$where['subquery'].')';
    }
    
    protected function whereNested(array $where)
    {
        return '(' . $this->handleWheres($where['clause']->getWhereClauses(), false) . ')';
    }
    
    protected function whereExists(array $where)
    {
        return ($where['not'] ? 'NOT EXISTS ' : 'EXISTS ') . '(' . $where['subquery'] . ')';
    }
    
    protected function whereNull(array $where)
    {
        return $this->wrap($where['column']) . ' '. ($where['not'] ? 'IS NOT NULL' : 'IS NULL');
    }
    
    protected function whereBetween(array $where)
    {
        return $this->wrap($where['column']) . ' ' . ($where['not'] ? 'NOT BETWEEN' : 'BETWEEN') . ' ' . $this->param($where['value1']) . ' AND ' . $this->param($where['value2']);
    }
    
    protected function whereLike(array $where)
    {
        return $this->wrap($where['column']) . ' ' . ($where['not'] ? 'NOT LIKE' : 'LIKE') . ' ' . $this->param($where['pattern']);
    }
    
    protected function whereSubquery(array $where)
    {
        return $this->wrap($where['column']) . ' ' . $where['operator'] .' (' . $where['subquery'] . ')';
    }
    
    protected function aggregateFunctionCOUNT(array $func)
    {
        return 'COUNT(' . ($func['distinct'] ? 'DISTINCT ' : '') . $this->columns($func['column']) . ')';
    }
    
    protected function aggregateFunctionAVG(array $func)
    {
        return 'AVG(' . ($func['distinct'] ? 'DISTINCT ' : '') . $this->wrap($func['column']) . ')';
    }
    
    protected function aggregateFunctionSUM(array $func)
    {
        return 'SUM(' . ($func['distinct'] ? 'DISTINCT ' : '') . $this->wrap($func['column']) . ')';
    }
    
    protected function aggregateFunctionMIN(array $func)
    {
        return 'MIN(' . ($func['distinct'] ? 'DISTINCT ' : '') . $this->wrap($func['column']) . ')';
    }
    
    protected function aggregateFunctionMAX(array $func)
    {
        return 'MAX(' . ($func['distinct'] ? 'DISTINCT ' : '') . $this->wrap($func['column']) . ')';
    }
    
    protected function sqlFunctionUCASE(array $func)
    {
        return 'UCASE(' . $this->wrap($func['column']) . ')';
    }
    
    protected function sqlFunctionLCASE(array $func)
    {
        return 'LCASE(' . $this->wrap($func['column']) . ')';
    }
    
    protected function sqlFunctionMID(array $func)
    {
        return 'MID(' . $this->wrap($func['column']). ', ' . $this->param($func['start']) . ($func['lenght'] > 0 ? $this->param($func['lenght']) . ')' : ')');
    }
    
    protected function sqlFunctionLEN(array $func)
    {
        return 'LEN(' . $this->wrap($func['column']) . ')';
    }
    
    protected function sqlFunctionROUND(array $func)
    {
        return 'REOUND(' . $this->wrap($func['column']). ', ' . $this->param($func['decimals']) . ')';
    }
    
    protected function sqlFunctionNOW(array $func)
    {
        return 'NOW()';
    }
    
    protected function sqlFunctionFORMAT(array $func)
    {
        return 'FORMAT('. $this->wrap($func['column']). ', ' . $this->param($func['format']) . ')';
    }
    
    public function select(SelectStatement $select)
    {
        $sql  =  $select->isDistinct() ? 'SELECT DISTINCT ' : 'SELECT ';
        $sql .= $this->handleColumns($select->getColumns());
        $sql .= $this->handleInto($select->getIntoTable(), $select->getIntoDatabase());
        $sql .= ' FROM ';
        $sql .= $this->handleTables($select->getTables());
        $sql .= $this->handleJoins($select->getJoinClauses());
        $sql .= $this->handleWheres($select->getWhereClauses());
        $sql .= $this->handleGroupings($select->getGroupClauses());
        $sql .= $this->handleOrderings($select->getOrderClauses());
        $sql .= $this->handleHavings($select->getHavingClauses());
        $sql .= $this->handleLimit($select->getLimit());
        $sql .= $this->handleOffset($select->getOffset());
        return $sql;
    }
    
    public function insert(InsertStatement $insert)
    {
        $columns = $this->handleColumns($insert->getColumns());
        
        $sql  = 'INSERT INTO ';
        $sql .= $this->handleTables($insert->getTables());
        $sql .= ($columns === '*') ? '' : ' (' . $columns . ')';
        $sql .= $this->handleInsertValues($insert->getValues());
        
        return $sql;
    }
    
    public function update(UpdateStatement $update)
    {
        $sql  = 'UPDATE ';
        $sql .= $this->handleTables($update->getTables());
        $sql .= $this->handleSetColumns($update->getColumns());
        $sql .= $this->handleWheres($update->getWhereClauses());
        
        return $sql;
    }
    
    public function delete(DeleteStatement $delete)
    {
        $sql  = 'DELETE ' . $this->handleTables($delete->getTables());
        $sql .= $sql === 'DELETE ' ? 'FROM ' : ' FROM ';
        $sql .= $this->handleTables($delete->getFrom());
        $sql .= $this->handleJoins($delete->getJoinClauses());
        $sql .= $this->handleWheres($delete->getWhereClauses());
        
        return $sql;
    }
    
}