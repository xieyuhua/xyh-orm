<?php

declare (strict_types = 1);

namespace xyh\db\concern;

/**
 * 数据字段信息
 */
trait TableFieldInfo
{

    /**
     * 获取数据表字段信息
     * @access public
     * @param string $tableName 数据表名
     * @return array
     */
    public function getTableFields($tableName = ''): array
    {
        if ('' == $tableName) {
            $tableName = $this->getTable();
        }

        return $this->connection->getTableFields($tableName);
    }

    /**
     * 获取详细字段类型信息
     * @access public
     * @param string $tableName 数据表名称
     * @return array
     */
    public function getFields(string $tableName = ''): array
    {
        return $this->connection->getFields($tableName ?: $this->getTable());
    }

    /**
     * 获取字段类型信息
     * @access public
     * @return array
     */
    public function getFieldsType(): array
    {
        if (!empty($this->options['field_type'])) {
            return $this->options['field_type'];
        }

        return $this->connection->getFieldsType($this->getTable());
    }

    /**
     * 获取字段类型信息
     * @access public
     * @param string $field 字段名
     * @return string|null
     */
    public function getFieldType(string $field)
    {
        $fieldType = $this->getFieldsType();

        return $fieldType[$field] ?? null;
    }

    /**
     * 获取字段类型信息
     * @access public
     * @return array
     */
    public function getFieldsBindType(): array
    {
        $fieldType = $this->getFieldsType();

        return array_map([$this->connection, 'getFieldBindType'], $fieldType);
    }

    /**
     * 获取字段类型信息
     * @access public
     * @param string $field 字段名
     * @return int
     */
    public function getFieldBindType(string $field): int
    {
        $fieldType = $this->getFieldType($field);

        return $this->connection->getFieldBindType($fieldType ?: '');
    }

}
