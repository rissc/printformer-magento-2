<?php

namespace Rissc\Printformer\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

/**
 *  Handler Class
 */
class Handler extends Base
{
    /**
     * Logging level
     * @var int
     */
    protected $loggerType = Logger::INFO;

    /**
     * File name
     * @var string
     */
    protected $fileName = '/var/log/rissc_printformer.log';
}
