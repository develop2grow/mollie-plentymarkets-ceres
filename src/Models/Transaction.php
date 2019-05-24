<?php

namespace Mollie\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * Class Transaction
 * @package Mollie\Models
 *
 * @property int $transactionId
 * @property int $orderId
 * @property int $timestamp
 * @property string $mollieOrderId
 * @property boolean $isPaid
 */
class Transaction extends Model
{
    /**
     * @var int
     */
    public $transactionId = 0;

    /**
     * @var int
     */
    public $orderId = 0;

    /**
     * @var string
     */
    public $mollieOrderId = '';

    /**
     * @var int
     */
    public $timestamp = 0;

    /**
     * @var bool
     */
    public $isPaid = false;

    protected $primaryKeyFieldName = 'transactionId';

    /**
     * @inheritdoc
     */
    public function getTableName(): string
    {
        return 'Mollie::Transaction';
    }
}