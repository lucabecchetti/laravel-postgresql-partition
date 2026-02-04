<?php

namespace Brokenice\LaravelPgsqlPartition\Models;

use Brokenice\LaravelPgsqlPartition\Exceptions\UnexpectedValueException;

class Partition
{
    const RANGE_TYPE = 'RANGE';
    const LIST_TYPE = 'LIST';
    const HASH_TYPE = 'HASH';

    /**
     * @var string
     */
    public $name;

    /**
     * @var string HASH|LIST|RANGE
     */
    public $type;

    /**
     * @var mixed From value for RANGE type
     */
    public $from;

    /**
     * @var mixed To value for RANGE type
     */
    public $to;

    /**
     * @var array Values for LIST type
     */
    public $values;

    /**
     * @var int Modulus for HASH type
     */
    public $modulus;

    /**
     * @var int Remainder for HASH type
     */
    public $remainder;

    /**
     * @var bool Whether this is a default partition
     */
    public $isDefault;

    /**
     * Create a new Partition instance.
     *
     * @param string $name Partition name
     * @param string $type Partition type (RANGE, LIST, HASH)
     * @param mixed $value Value depends on type
     * @param mixed $to End value for RANGE type (optional)
     */
    public function __construct($name, $type, $value, $to = null)
    {
        $this->name = $name;
        $this->type = $type;
        $this->isDefault = false;

        switch ($this->type) {
            case self::RANGE_TYPE:
                if ($to === null) {
                    throw new UnexpectedValueException('RANGE partition requires both from and to values');
                }
                $this->from = $value;
                $this->to = $to;
                break;

            case self::LIST_TYPE:
                if (!is_array($value)) {
                    throw new UnexpectedValueException('Value for LIST must be an array');
                }
                $this->values = $value;
                break;

            case self::HASH_TYPE:
                if (!is_array($value) || !isset($value['modulus']) || !isset($value['remainder'])) {
                    throw new UnexpectedValueException('Value for HASH must be an array with modulus and remainder keys');
                }
                $this->modulus = $value['modulus'];
                $this->remainder = $value['remainder'];
                break;

            default:
                throw new UnexpectedValueException("Unsupported partition type: {$this->type}");
        }
    }

    /**
     * Create a default partition (catches all unmatched rows).
     *
     * @param string $name
     * @return self
     */
    public static function createDefault($name)
    {
        $partition = new self($name, self::LIST_TYPE, [0]); // Dummy value
        $partition->isDefault = true;
        $partition->values = null;
        return $partition;
    }

    /**
     * Create a RANGE partition.
     *
     * @param string $name
     * @param mixed $from
     * @param mixed $to
     * @return self
     */
    public static function range($name, $from, $to)
    {
        return new self($name, self::RANGE_TYPE, $from, $to);
    }

    /**
     * Create a LIST partition.
     *
     * @param string $name
     * @param array $values
     * @return self
     */
    public static function list($name, array $values)
    {
        return new self($name, self::LIST_TYPE, $values);
    }

    /**
     * Create a HASH partition.
     *
     * @param string $name
     * @param int $modulus
     * @param int $remainder
     * @return self
     */
    public static function hash($name, $modulus, $remainder)
    {
        return new self($name, self::HASH_TYPE, [
            'modulus' => $modulus,
            'remainder' => $remainder
        ]);
    }

    /**
     * Convert this partition to SQL FOR VALUES clause.
     *
     * @return string
     */
    public function toSQL()
    {
        if ($this->isDefault) {
            return 'DEFAULT';
        }

        switch ($this->type) {
            case self::RANGE_TYPE:
                $fromValue = $this->formatValue($this->from);
                $toValue = $this->formatValue($this->to);
                return "FOR VALUES FROM ({$fromValue}) TO ({$toValue})";

            case self::LIST_TYPE:
                $values = collect($this->values)->map(function ($value) {
                    return $this->formatValue($value);
                })->implode(', ');
                return "FOR VALUES IN ({$values})";

            case self::HASH_TYPE:
                return "FOR VALUES WITH (MODULUS {$this->modulus}, REMAINDER {$this->remainder})";

            default:
                return '';
        }
    }

    /**
     * Format a value for SQL.
     *
     * @param mixed $value
     * @return string
     */
    protected function formatValue($value)
    {
        if ($value === 'MINVALUE' || $value === 'MAXVALUE') {
            return $value;
        }

        // If value is already a quoted string (starts and ends with quotes), return as-is
        if (is_string($value) && preg_match('/^[\'"].*[\'"]$/', $value)) {
            return $value;
        }

        if (is_string($value) && !is_numeric($value)) {
            return "'" . addslashes($value) . "'";
        }

        return $value;
    }

    /**
     * Get the full CREATE TABLE statement for this partition.
     *
     * @param string $parentTable
     * @param string|null $schema
     * @return string
     */
    public function toCreateSQL($parentTable, $schema = null)
    {
        $schemaPrefix = $schema !== null ? "\"{$schema}\"." : '';
        $partitionName = $schemaPrefix . "\"{$this->name}\"";
        $parentName = $schemaPrefix . "\"{$parentTable}\"";

        return "CREATE TABLE {$partitionName} PARTITION OF {$parentName} {$this->toSQL()}";
    }
}
