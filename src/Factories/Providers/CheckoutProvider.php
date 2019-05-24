<?php

namespace Mollie\Factories\Providers;

use Mollie\Contracts\OrderFactoryProvider;
use Mollie\Helpers\PhoneHelper;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Basket\Models\BasketItem;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;

/**
 * Class CheckoutProvider
 * @package Mollie\Factories\Providers
 */
class CheckoutProvider extends OrderFactoryProvider
{
    /**
     * @param string $method
     * @param array $options
     * @return array
     */
    public function buildOrder($method, $options = [])
    {
        /** @var PhoneHelper $phoneHelper */
        $phoneHelper = pluginApp(PhoneHelper::class);

        /** @var BasketRepositoryContract $basketRepository */
        $basketRepository = pluginApp(BasketRepositoryContract::class);

        /** @var AddressRepositoryContract $addressRepository */
        $addressRepository = pluginApp(AddressRepositoryContract::class);

        /** @var FrontendSessionStorageFactoryContract $sessionStorage */
        $sessionStorage = pluginApp(FrontendSessionStorageFactoryContract::class);

        /** @var ItemRepositoryContract $itemContract */
        $itemContract = pluginApp(ItemRepositoryContract::class);

        $domain = $this->getDomain();

        $basket = $basketRepository->load();

        $deliveryAddressId = $basket->customerShippingAddressId;
        $billingAddressId  = $basket->customerInvoiceAddressId;

        if (is_null($deliveryAddressId) || $deliveryAddressId == -99) {
            $deliveryAddressId = $billingAddressId;
        }

        /** @var Address $billingAddress */
        $billingAddress = $addressRepository->findAddressById($billingAddressId);

        /** @var Address $deliveryAddress */
        $deliveryAddress = $addressRepository->findAddressById($deliveryAddressId);


        $orderData = [
            'amount'          => [
                'currency' => $basket->currency,
                'value'    => number_format($basket->basketAmount, 2, '.', ''),
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
                'phone'            => $phoneHelper->correctPhone($billingAddress->phone, $billingAddress->country->isoCode2),
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
                'orderId'       => null,
                'transactionId' => $options['transactionId']
            ],
            'locale'          => $this->getLocaleByOrder($billingAddress),
            'orderNumber'     => $options['transactionId'],
            'redirectUrl'     => $domain . '/mollie/check',
            'webhookUrl'      => $domain . '/rest/mollie/webhook',
            'method'          => $method,
            'lines'           => [],
        ];

        if (!empty($billingAddress->birthday)) {
            $orderData['consumerDateOfBirth'] = date('Y-m-d', $billingAddress->birthday);
        }

        $vatRate = 0.00;
        foreach ($basket->basketItems as $basketItem) {
            if ($basketItem instanceof BasketItem) {

                /** @var \Plenty\Modules\Item\Item\Models\Item $item */
                $item = $itemContract->show($basketItem->itemId, ['*'], $sessionStorage->getLocaleSettings()->language);

                /** @var \Plenty\Modules\Item\Item\Models\ItemText $itemText */
                $itemText = $item->texts;

                $discount = 0.00;
                if ($basket->basketRebate > 0.00) {
                    $discount = $basketItem->price * ($basket->basketRebate / 100);
                }

                $vatRate = max($basketItem->vat, $vatRate);
                $line    = [
                    'sku'            => (STRING)$basketItem->variationId,
                    'name'           => $itemText->first()->name1,
                    'type'           => 'physical',
                    //'productUrl'
                    //'imageUrl' => $orderItem->itemVariationI
                    'quantity'       => $basketItem->quantity,
                    'vatRate'        => number_format($basketItem->vat, 2, '.', ''),
                    'unitPrice'      => [
                        'currency' => $basket->currency,
                        'value'    => number_format($basketItem->price, 2, '.', ''),
                    ],
                    'totalAmount'    => [
                        'currency' => $basket->currency,
                        'value'    => number_format($basketItem->price * $basketItem->quantity, 2, '.', ''),
                    ],
                    'discountAmount' => [
                        'currency' => $basket->currency,
                        'value'    => number_format($discount * $basketItem->quantity, 2, '.', ''),
                    ],
                    'vatAmount'      => [
                        'currency' => $basket->currency,
                        'value'    => number_format(($basketItem->price * ($basketItem->vat / 100.0)) * $basketItem->quantity, 2, '.', ''),
                    ]
                ];

                $orderData['lines'][] = $line;
            }
        }

        //shippingcosts
        $orderData['lines'][] = [
            'sku'            => '0',
            'name'           => 'Shipping',
            'type'           => 'shipping_fee',
            //'productUrl'
            //'imageUrl' => $orderItem->itemVariationI
            'quantity'       => 1,
            'vatRate'        => number_format($vatRate, 2, '.', ''),
            'unitPrice'      => [
                'currency' => $basket->currency,
                'value'    => number_format($basket->shippingAmount, 2, '.', ''),
            ],
            'totalAmount'    => [
                'currency' => $basket->currency,
                'value'    => number_format($basket->shippingAmount, 2, '.', ''),
            ],
            'discountAmount' => [
                'currency' => $basket->currency,
                'value'    => $basket->shippingDeleteByCoupon ? number_format(-1 * $basket->shippingAmount, 2, '.', '') : '0.00',
            ],
            'vatAmount'      => [
                'currency' => $basket->currency,
                'value'    => number_format($basket->shippingAmount - $basket->shippingAmountNet, 2, '.', ''),
            ]
        ];

        if ($basket->couponDiscount != 0.00) {
            //coupon
            $orderData['lines'][] = [
                'sku'            => '0',
                'name'           => 'Coupon: ' . $basket->couponCode,
                'type'           => 'discount',
                //'productUrl'
                //'imageUrl' => $orderItem->itemVariationI
                'quantity'       => 1,
                'vatRate'        => number_format($vatRate, 2, '.', ''),
                'unitPrice'      => [
                    'currency' => $basket->currency,
                    'value'    => number_format($basket->shippingAmount, 2, '.', ''),
                ],
                'totalAmount'    => [
                    'currency' => $basket->currency,
                    'value'    => number_format($basket->shippingAmount, 2, '.', ''),
                ],
                'discountAmount' => [
                    'currency' => $basket->currency,
                    'value'    => $basket->shippingDeleteByCoupon ? number_format(-1 * $basket->shippingAmount, 2, '.', '') : '0.00',
                ],
                'vatAmount'      => [
                    'currency' => $basket->currency,
                    'value'    => number_format($basket->shippingAmount - $basket->shippingAmountNet, 2, '.', ''),
                ]
            ];
        }

        return $orderData;
    }
}