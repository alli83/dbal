<?php

namespace Doctrine\DBAL\Driver\OCI8;

use Doctrine\DBAL\Driver\OCI8\Exception\UnknownParameterIndex;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;

use function assert;
use function is_int;
use function is_resource;
use function oci_bind_by_name;
use function oci_error;
use function oci_execute;
use function oci_new_descriptor;
use function oci_parse;

use const OCI_B_BIN;
use const OCI_B_BLOB;
use const OCI_COMMIT_ON_SUCCESS;
use const OCI_D_LOB;
use const OCI_NO_AUTO_COMMIT;
use const OCI_TEMP_BLOB;
use const SQLT_CHR;

/**
 * The OCI8 implementation of the Statement interface.
 *
 * @deprecated Use {@link Statement} instead
 */
class OCI8Statement implements StatementInterface
{
    /** @var resource */
    protected $_dbh;

    /** @var resource */
    protected $_sth;

    /** @var ExecutionMode */
    private $executionMode;

    /** @var string[] */
    protected $_paramMap = [];

    /**
     * Holds references to bound parameter values.
     *
     * This is a new requirement for PHP7's oci8 extension that prevents bound values from being garbage collected.
     *
     * @var mixed[]
     */
    private $boundValues = [];

    /**
     * Creates a new OCI8Statement that uses the given connection handle and SQL statement.
     *
     * @param resource $dbh   The connection handle.
     * @param string   $query The SQL query.
     */
    public function __construct($dbh, $query, ExecutionMode $executionMode)
    {
        [$query, $paramMap] = (new ConvertPositionalToNamedPlaceholders())($query);

        $stmt = oci_parse($dbh, $query);
        assert(is_resource($stmt));

        $this->_sth          = $stmt;
        $this->_dbh          = $dbh;
        $this->_paramMap     = $paramMap;
        $this->executionMode = $executionMode;
    }

    /**
     * {@inheritdoc}
     */
    public function bindValue($param, $value, $type = ParameterType::STRING)
    {
        return $this->bindParam($param, $value, $type, null);
    }

    /**
     * {@inheritdoc}
     */
    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null)
    {
        if (is_int($param)) {
            if (! isset($this->_paramMap[$param])) {
                throw UnknownParameterIndex::new($param);
            }

            $param = $this->_paramMap[$param];
        }

        if ($type === ParameterType::LARGE_OBJECT) {
            $lob = oci_new_descriptor($this->_dbh, OCI_D_LOB);

            $class = 'OCI-Lob';
            assert($lob instanceof $class);

            $lob->writetemporary($variable, OCI_TEMP_BLOB);

            $variable =& $lob;
        }

        $this->boundValues[$param] =& $variable;

        return oci_bind_by_name(
            $this->_sth,
            $param,
            $variable,
            $length ?? -1,
            $this->convertParameterType($type)
        );
    }

    /**
     * Converts DBAL parameter type to oci8 parameter type
     */
    private function convertParameterType(int $type): int
    {
        switch ($type) {
            case ParameterType::BINARY:
                return OCI_B_BIN;

            case ParameterType::LARGE_OBJECT:
                return OCI_B_BLOB;

            default:
                return SQLT_CHR;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function execute($params = null): ResultInterface
    {
        if ($params !== null) {
            foreach ($params as $key => $val) {
                if (is_int($key)) {
                    $this->bindValue($key + 1, $val);
                } else {
                    $this->bindValue($key, $val);
                }
            }
        }

        if ($this->executionMode->isAutoCommitEnabled()) {
            $mode = OCI_COMMIT_ON_SUCCESS;
        } else {
            $mode = OCI_NO_AUTO_COMMIT;
        }

        $ret = @oci_execute($this->_sth, $mode);
        if (! $ret) {
            throw OCI8Exception::fromErrorInfo(oci_error($this->_sth));
        }

        return new Result($this->_sth);
    }
}