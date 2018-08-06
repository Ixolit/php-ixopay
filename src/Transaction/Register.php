<?php

namespace Ixopay\Client\Transaction;

use Ixopay\Client\Transaction\Base\AbstractTransaction;
use Ixopay\Client\Transaction\Base\OffsiteInterface;
use Ixopay\Client\Transaction\Base\OffsiteTrait;
use Ixopay\Client\Transaction\Base\ScheduleInterface;
use Ixopay\Client\Transaction\Base\ScheduleTrait;

/**
 * Register: Register the customer's payment data for recurring charges.
 *
 * The registered customer payment data will be available for recurring transaction without user interaction.
 *
 * @package Ixopay\Client\Transaction
 */
class Register extends AbstractTransaction implements OffsiteInterface, ScheduleInterface {
    use OffsiteTrait;
    use ScheduleTrait;

    /**
     * @var string
     */
    protected $updateRegisterId;

    /**
     * @return string
     */
    public function getUpdateRegisterId() {
        return $this->updateRegisterId;
    }

    /**
     * @param string $updateRegisterId
     */
    public function setUpdateRegisterId($updateRegisterId) {
        $this->updateRegisterId = $updateRegisterId;
    }
}