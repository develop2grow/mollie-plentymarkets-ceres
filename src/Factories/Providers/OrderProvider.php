<?php

namespace Mollie\Factories\Providers;

use Mollie\Contracts\OrderFactoryProvider;
use Mollie\Helpers\PhoneHelper;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Models\OrderAmount;
use Plenty\Modules\Order\Models\OrderItem;
use Plenty\Modules\Order\Models\OrderItemAmount;
use Plenty\Modules\Order\Models\OrderItemType;

/**
 * Class OrderProvider
 * @package Mollie\Factories\Providers
 */
class OrderProvider extends OrderFactoryProvider
{
    /**
     * @param string $method
     * @param array $options
     * @return array
     */
    public function buildOrder($method, $options = [])
    {
        /** @var Order $order */
        $order = $options['order'];

        /** @var PhoneHelper $phoneHelper */
        $phoneHelper = pluginApp(PhoneHelper::class);

        /** @var OrderAmount $orderAmount */
        $orderAmount = $order->amount;

        /** @var Address $billingAddress */
        $billingAddress = $order->billingAddress;

        /** @var Address $deliveryAddress */
        $deliveryAddress = $order->deliveryAddress;

        $domain = $this->getDomain();


        $orderData = [
            'amount'          => [
                'currency' => $orderAmount->currency,
                'value'    => number_format($orderAmount->invoiceTotal, 2, '.', ''),
            ],
            'billingAddress'  => [
                'organizationName' => $billingAddress->companyName,
                'streetAndNumber'  => $billingAddress->street . ' ' . $billingAddress->houseNumber,
                'city'             => $billingAddress->town,
                'region'           => $billingAddress->state->name,
                'postalCode'       => (STRING)$billingAddress->postalCode,
                'country'          => $billingAddress->country->isoCode2,
                'title'            => $billingAddress->title,
                'givenName'        => $this->getName($billingAddress),
                'familyName'       => $this->getName($billingAddress, false),
                'email'            => $billingAddress->email,
            ],
            'shippingAddress' => [
                'organizationName' => $deliveryAddress->companyName,
                'streetAndNumber'  => $deliveryAddress->street . ' ' . $deliveryAddress->houseNumber,
                'streetAdditional' => $deliveryAddress->additional,
                'city'             => $deliveryAddress->town,
                'region'           => $deliveryAddress->state->name,
                'postalCode'       => (STRING)$deliveryAddress->postalCode,
                'country'          => $deliveryAddress->country->isoCode2,
                'title'            => $deliveryAddress->title,
                'givenName'        => $this->getName($deliveryAddress),
                'familyName'       => $this->getName($deliveryAddress, false),
                'email'            => $deliveryAddress->email,
            ],
            'metadata'        => [
                'orderId' => $order->id
            ],
            'locale'          => $this->getLocaleByOrder($order->billingAddress, $order->contactReceiver),
            'orderNumber'     => (STRING)$order->id,
            'redirectUrl'     => $domain . '/confirmation/' . $order->id,
            'webhookUrl'      => $domain . '/rest/mollie/webhook',
            'method'          => $method,
            'lines'           => [],
        ];

        $phone = $phoneHelper->correctPhone($billingAddress->phone, $billingAddress->country->isoCode2);
        if ($phone !== false) {
            $orderData['billingAddress']['phone'] = $phone;
        }

        if (!empty($billingAddress->birthday)) {
            $orderData['consumerDateOfBirth'] = date('Y-m-d', $billingAddress->birthday);
        }


        foreach ($order->orderItems as $orderItem) {
            if ($orderItem instanceof OrderItem) {
                /** @var OrderItemAmount $amount */
                $amount = $orderItem->amount;
                $discountAmount = ($amount->priceOriginalGross - $amount->priceGross) * $orderItem->quantity;
                $line   = [
                    'sku'            => (STRING)$orderItem->itemVariationId,
                    'name'           => $orderItem->orderItemName,
                    //'productUrl'
                    //'imageUrl' => $orderItem->itemVariationI
                    'quantity'       => $orderItem->quantity,
                    'vatRate'        => number_format($orderItem->vatRate, 2, '.', ''),
                    'unitPrice'      => [
                        'currency' => $amount->currency,
                        'value'    => number_format($amount->priceOriginalGross, 2, '.', ''),
                    ],
                    'totalAmount'    => [
                        'currency' => $amount->currency,
                        'value'    => number_format( $amount->priceGross * $orderItem->quantity, 2, '.', ''),
                    ],
                    'discountAmount' => [
                        'currency' => $amount->currency,
                        'value'    => number_format($discountAmount, 2, '.', ''),
                    ],
                    'vatAmount'      => [
                        'currency' => $amount->currency,
                        'value'    => number_format(($amount->priceGross - $amount->priceNet) * $orderItem->quantity, 2, '.', ''),
                    ]
                ];

                if ($orderItem->typeId == OrderItemType::TYPE_SHIPPING_COSTS) {
                    $line['type'] = 'shipping_fee';
                } elseif ($orderItem->typeId == OrderItemType::TYPE_GIFT_CARD) {
                    $line['type'] = 'gift_card';
                } elseif ($orderItem->typeId == OrderItemType::TYPE_PROMOTIONAL_COUPON) {
                    $line['type'] = 'discount';
                } elseif ($orderItem->typeId == OrderItemType::TYPE_DEPOSIT) {
                    $line['type'] = 'store_credit';
                } elseif ($orderItem->typeId == OrderItemType::TYPE_PAYMENT_SURCHARGE) {
                    $line['type'] = 'surcharge';
                } else {
                    //TODO check for digital goods
                    $line['type'] = 'physical';
                }

                $orderData['lines'][] = $line;
            }
        }

        return $orderData;
    }
}
