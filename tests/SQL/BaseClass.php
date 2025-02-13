<?php
/* ===========================================================================
 * Copyright 2018 Zindex Software
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

namespace Opis\Database\Test\SQL;

use Opis\Database\Database;
use Opis\Database\Test\Connection;
use PHPUnit\Framework\TestCase;

class BaseClass extends TestCase
{
    protected static $database;

    /** @var  Database */
    protected $db;

    public static function setUpBeforeClass(): void
    {
        static::$database = new Database(new Connection(''));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = static::$database;
    }
}
