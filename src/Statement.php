<?php

namespace Doctrine\DBAL;

use Doctrine\DBAL\Abstraction\Result;
use Doctrine\DBAL\Driver\DriverException;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Throwable;
use Traversable;

use function is_string;

/**
 * A thin wrapper around a Doctrine\DBAL\Driver\Statement that adds support
 * for logging, DBAL mapping types, etc.
 */
class Statement implements DriverStatement, Result
{
    /**
     * The SQL statement.
     *
     * @var string
     */
    protected $sql;

    /**
     * The bound parameters.
     *
     * @var mixed[]
     */
    protected $params = [];

    /**
     * The parameter types.
     *
     * @var int[]|string[]
     */
    protected $types = [];

    /**
     * The underlying driver statement.
     *
     * @var \Doctrine\DBAL\Driver\Statement
     */
    protected $stmt;

    /**
     * The underlying database platform.
     *
     * @var AbstractPlatform
     */
    protected $platform;

    /**
     * The connection this statement is bound to and executed on.
     *
     * @var Connection
     */
    protected $conn;

    /**
     * Creates a new <tt>Statement</tt> for the given SQL and <tt>Connection</tt>.
     *
     * @param string     $sql  The SQL of the statement.
     * @param Connection $conn The connection on which the statement should be executed.
     */
    public function __construct($sql, Connection $conn)
    {
        $this->sql      = $sql;
        $this->stmt     = $conn->getWrappedConnection()->prepare($sql);
        $this->conn     = $conn;
        $this->platform = $conn->getDatabasePlatform();
    }

    /**
     * Binds a parameter value to the statement.
     *
     * The value can optionally be bound with a PDO binding type or a DBAL mapping type.
     * If bound with a DBAL mapping type, the binding type is derived from the mapping
     * type and the value undergoes the conversion routines of the mapping type before
     * being bound.
     *
     * @param string|int $name  The name or position of the parameter.
     * @param mixed      $value The value of the parameter.
     * @param mixed      $type  Either a PDO binding type or a DBAL mapping type name or instance.
     *
     * @return bool TRUE on success, FALSE on failure.
     */
    public function bindValue($name, $value, $type = ParameterType::STRING)
    {
        $this->params[$name] = $value;
        $this->types[$name]  = $type;
        if ($type !== null) {
            if (is_string($type)) {
                $type = Type::getType($type);
            }

            if ($type instanceof Type) {
                $value       = $type->convertToDatabaseValue($value, $this->platform);
                $bindingType = $type->getBindingType();
            } else {
                $bindingType = $type;
            }

            return $this->stmt->bindValue($name, $value, $bindingType);
        }

        return $this->stmt->bindValue($name, $value);
    }

    /**
     * Binds a parameter to a value by reference.
     *
     * Binding a parameter by reference does not support DBAL mapping types.
     *
     * @param string|int $name   The name or position of the parameter.
     * @param mixed      $var    The reference to the variable to bind.
     * @param int        $type   The PDO binding type.
     * @param int|null   $length Must be specified when using an OUT bind
     *                           so that PHP allocates enough memory to hold the returned value.
     *
     * @return bool TRUE on success, FALSE on failure.
     */
    public function bindParam($name, &$var, $type = ParameterType::STRING, $length = null)
    {
        $this->params[$name] = $var;
        $this->types[$name]  = $type;

        return $this->stmt->bindParam($name, $var, $type, $length);
    }

    /**
     * Executes the statement with the currently bound parameters.
     *
     * @param mixed[]|null $params
     *
     * @return bool TRUE on success, FALSE on failure.
     *
     * @throws DBALException
     */
    public function execute($params = null)
    {
        if ($params !== null) {
            $this->params = $params;
        }

        $logger = $this->conn->getConfiguration()->getSQLLogger();
        if ($logger !== null) {
            $logger->startQuery($this->sql, $this->params, $this->types);
        }

        try {
            $stmt = $this->stmt->execute($params);
        } catch (Throwable $ex) {
            if ($logger !== null) {
                $logger->stopQuery();
            }

            throw DBALException::driverExceptionDuringQuery(
                $this->conn->getDriver(),
                $ex,
                $this->sql,
                $this->conn->resolveParams($this->params, $this->types)
            );
        }

        if ($logger !== null) {
            $logger->stopQuery();
        }

        $this->params = [];
        $this->types  = [];

        return $stmt;
    }

    /**
     * Closes the cursor, freeing the database resources used by this statement.
     *
     * @deprecated Use Result::free() instead.
     *
     * @return bool TRUE on success, FALSE on failure.
     */
    public function closeCursor()
    {
        return $this->stmt->closeCursor();
    }

    /**
     * Returns the number of columns in the result set.
     *
     * @return int
     */
    public function columnCount()
    {
        return $this->stmt->columnCount();
    }

    /**
     * {@inheritdoc}
     *
     * @throws DBALException
     */
    public function fetchNumeric()
    {
        try {
            return $this->stmt->fetchNumeric();
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws DBALException
     */
    public function fetchAssociative()
    {
        try {
            return $this->stmt->fetchAssociative();
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function fetchOne()
    {
        try {
            return $this->stmt->fetchOne();
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws DBALException
     */
    public function fetchAllNumeric(): array
    {
        try {
            return $this->stmt->fetchAllNumeric();
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws DBALException
     */
    public function fetchAllAssociative(): array
    {
        try {
            return $this->stmt->fetchAllAssociative();
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws DBALException
     */
    public function fetchFirstColumn(): array
    {
        try {
            return $this->stmt->fetchFirstColumn();
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<int,array<int,mixed>>
     *
     * @throws DBALException
     */
    public function iterateNumeric(): Traversable
    {
        try {
            while (($row = $this->stmt->fetchNumeric()) !== false) {
                yield $row;
            }
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<int,array<string,mixed>>
     *
     * @throws DBALException
     */
    public function iterateAssociative(): Traversable
    {
        try {
            while (($row = $this->stmt->fetchAssociative()) !== false) {
                yield $row;
            }
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<int,mixed>
     *
     * @throws DBALException
     */
    public function iterateColumn(): Traversable
    {
        try {
            while (($value = $this->stmt->fetchOne()) !== false) {
                yield $value;
            }
        } catch (DriverException $e) {
            throw DBALException::driverException($this->conn->getDriver(), $e);
        }
    }

    /**
     * Returns the number of rows affected by the last execution of this statement.
     *
     * @return int The number of affected rows.
     */
    public function rowCount(): int
    {
        return $this->stmt->rowCount();
    }

    public function free(): void
    {
        if ($this->stmt instanceof Result) {
            $this->stmt->free();

            return;
        }

        $this->stmt->closeCursor();
    }

    /**
     * Gets the wrapped driver statement.
     *
     * @return \Doctrine\DBAL\Driver\Statement
     */
    public function getWrappedStatement()
    {
        return $this->stmt;
    }
}