<?php

namespace Brokenice\LaravelPgsqlPartition\Tests\Unit\Schema;

use Brokenice\LaravelPgsqlPartition\Models\Partition;
use Brokenice\LaravelPgsqlPartition\Tests\Unit\BaseTestCase;

class SchemaTest extends BaseTestCase
{
    /**
     * Test RANGE partition SQL generation.
     */
    public function testRangePartitionToSQL()
    {
        $partition = Partition::range('year2024', '2024-01-01', '2025-01-01');
        
        $expected = "FOR VALUES FROM ('2024-01-01') TO ('2025-01-01')";
        $this->assertEquals($expected, $partition->toSQL());
    }

    /**
     * Test RANGE partition with numeric values.
     */
    public function testRangePartitionNumericToSQL()
    {
        $partition = Partition::range('range_100', 0, 100);
        
        $expected = "FOR VALUES FROM (0) TO (100)";
        $this->assertEquals($expected, $partition->toSQL());
    }

    /**
     * Test LIST partition SQL generation.
     */
    public function testListPartitionToSQL()
    {
        $partition = Partition::list('server_east', [1, 43, 65, 12]);
        
        $expected = "FOR VALUES IN (1, 43, 65, 12)";
        $this->assertEquals($expected, $partition->toSQL());
    }

    /**
     * Test LIST partition with string values.
     */
    public function testListPartitionStringValuesToSQL()
    {
        $partition = Partition::list('region_europe', ['IT', 'FR', 'DE', 'ES']);
        
        $expected = "FOR VALUES IN ('IT', 'FR', 'DE', 'ES')";
        $this->assertEquals($expected, $partition->toSQL());
    }

    /**
     * Test HASH partition SQL generation.
     */
    public function testHashPartitionToSQL()
    {
        $partition = Partition::hash('hash_p0', 4, 0);
        
        $expected = "FOR VALUES WITH (MODULUS 4, REMAINDER 0)";
        $this->assertEquals($expected, $partition->toSQL());
    }

    /**
     * Test default partition SQL generation.
     */
    public function testDefaultPartitionToSQL()
    {
        $partition = Partition::createDefault('future');
        
        $expected = "DEFAULT";
        $this->assertEquals($expected, $partition->toSQL());
    }

    /**
     * Test CREATE TABLE partition statement.
     */
    public function testPartitionToCreateSQL()
    {
        $partition = Partition::range('orders_2024', '2024-01-01', '2025-01-01');
        
        $expected = 'CREATE TABLE "orders_2024" PARTITION OF "orders" FOR VALUES FROM (\'2024-01-01\') TO (\'2025-01-01\')';
        $this->assertEquals($expected, $partition->toCreateSQL('orders'));
    }

    /**
     * Test CREATE TABLE partition statement with schema.
     */
    public function testPartitionToCreateSQLWithSchema()
    {
        $partition = Partition::range('orders_2024', '2024-01-01', '2025-01-01');
        
        $expected = 'CREATE TABLE "sales"."orders_2024" PARTITION OF "sales"."orders" FOR VALUES FROM (\'2024-01-01\') TO (\'2025-01-01\')';
        $this->assertEquals($expected, $partition->toCreateSQL('orders', 'sales'));
    }

    /**
     * Test that RANGE partition requires both from and to values.
     */
    public function testRangePartitionRequiresBothValues()
    {
        $this->expectException(\Brokenice\LaravelPgsqlPartition\Exceptions\UnexpectedValueException::class);
        
        new Partition('test', Partition::RANGE_TYPE, '2024-01-01');
    }

    /**
     * Test that LIST partition requires array value.
     */
    public function testListPartitionRequiresArrayValue()
    {
        $this->expectException(\Brokenice\LaravelPgsqlPartition\Exceptions\UnexpectedValueException::class);
        
        new Partition('test', Partition::LIST_TYPE, 'not_an_array');
    }

    /**
     * Test that HASH partition requires modulus and remainder.
     */
    public function testHashPartitionRequiresModulusAndRemainder()
    {
        $this->expectException(\Brokenice\LaravelPgsqlPartition\Exceptions\UnexpectedValueException::class);
        
        new Partition('test', Partition::HASH_TYPE, ['modulus' => 4]);
    }

    /**
     * Test partition type constants.
     */
    public function testPartitionTypeConstants()
    {
        $this->assertEquals('RANGE', Partition::RANGE_TYPE);
        $this->assertEquals('LIST', Partition::LIST_TYPE);
        $this->assertEquals('HASH', Partition::HASH_TYPE);
    }

    /**
     * Test RANGE partition with MINVALUE and MAXVALUE.
     */
    public function testRangePartitionWithSpecialValues()
    {
        $partition = Partition::range('all_data', 'MINVALUE', 'MAXVALUE');
        
        $expected = "FOR VALUES FROM (MINVALUE) TO (MAXVALUE)";
        $this->assertEquals($expected, $partition->toSQL());
    }
}
