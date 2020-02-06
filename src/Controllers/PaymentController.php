<?php

namespace Mollie\Controllers;

use IO\Extensions\Constants\ShopUrls;
use Mollie\Api\ApiClient;
use Mollie\Contracts\TransactionRepositoryContract;
use Mollie\Helpers\CeresHelper;
use Mollie\Models\Transaction;
use Mollie\Services\OrderService;
use Mollie\Services\OrderUpdateService;
use Mollie\Traits\CanHandleTransactionId;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Translation\Translator;

/**
 * Class PaymentController
 * @package Mollie\Controllers
 */
class PaymentController extends Controller
{
    use CanHandleTransactionId, Loggable;

    /**
     * @param OrderService $orderService
     * @param AuthHelper $authHelper
     * @param Request $request
     * @return array
     * @throws \Exception
     */
    public function reInit(OrderService $orderService, AuthHelper $authHelper, Request $request)
    {
        return $authHelper->processUnguarded(function () use ($orderService, $request) {
            return $orderService->prepareOrder($request->get('orderId'));
        });
    }

    /**
     * @param OrderUpdateService $orderUpdateService
     * @param AuthHelper $authHelper
     * @param Request $request
     * @return string
     */
    public function webHook(OrderUpdateService $orderUpdateService, AuthHelper $authHelper, Request $request)
    {
        $authHelper->processUnguarded(function () use ($orderUpdateService, $request) {
            $orderUpdateService->updatePlentyOrder($request->get('id'));
        });
        return 'OK';
    }

    /**
     * @param FrontendSessionStorageFactoryContract $frontendSessionStorageFactory
     * @param OrderRepositoryContract $orderRepository
     * @param Response $response
     * @param Translator $translator
     * @param CeresHelper $ceresHelper
     * @param ApiClient $apiClient
     * @param AuthHelper $authHelper
     * @param OrderUpdateService $orderUpdateService
     * @param TransactionRepositoryContract $transactionRepository
     * @return mixed
     */
    public function checkPayment(FrontendSessionStorageFactoryContract $frontendSessionStorageFactory,
                                 OrderRepositoryContract $orderRepository,
                                 Response $response,
                                 Translator $translator,
                                 CeresHelper $ceresHelper,
                                 ApiClient $apiClient,
                                 AuthHelper $authHelper,
                                 OrderUpdateService $orderUpdateService,
                                 TransactionRepositoryContract $transactionRepository)
    {
        $lang = $frontendSessionStorageFactory->getLocaleSettings()->language;

        $orderId = 0;
        if ($transactionRepository->openTransactionExists()) {

            /** @var Transaction $transaction */
            $transaction = $transactionRepository->getTransaction();
            $orderId     = $transaction->orderId;
            try {
                if ($transaction->isPaid) {

                    //set mollie id as external order id to order
                    $orderRepository->updateOrder(
                        [
                            'properties' => [
                                ['typeId' => OrderPropertyType::EXTERNAL_ORDER_ID, 'value' => $transaction->mollieOrderId]
                            ]
                        ],
                        $transaction->orderId
                    );

                    $mollieOrder = $apiClient->getOrder($transaction->mollieOrderId, true);

                    //set orderId at mollie
                    $orderUpdateResponse = $apiClient->updateOrderNumber($transaction->mollieOrderId, (STRING)$transaction->orderId);
                    $this->getLogger('updateOrderid')->debug(
                        'Mollie::Debug.mollieOrder',
                        $orderUpdateResponse
                    );

                    foreach ($mollieOrder['_embedded']['payments'] as $payment) {
                        $updatePaymentsResponse = $apiClient->updateOrderNumberAtPayment($payment['id'], (STRING)$transaction->orderId);
                        $this->getLogger('updatePaymentId')->debug(
                            'Mollie::Debug.mollieOrder',
                            $updatePaymentsResponse
                        );
                    }

                    $authHelper->processUnguarded(function () use ($transaction, $mollieOrder, $orderUpdateService, $orderRepository) {
                        $orderUpdateService->setPaid($orderRepository->findOrderById($transaction->orderId), $mollieOrder);
                    });

                } else {
                    //check payment status
                    $this->getLogger('checkPayment')->error(
                        'Mollie::Debug.transactionWasNotPaid',
                        ['transactionId' => $transactionRepository->getTransactionId()]
                    );

                    $ceresHelper->pushNotification($translator->trans('Mollie::Errors.notPaid'));

                }

            } catch (\Exception $exception) {
                $this->getLogger('checkPayment')->error(
                    'Mollie::Debug.transactionIdDoesNotMatch',
                    [
                        'transactionId' => $transactionRepository->getTransactionId(),
                        'session'       => $transactionRepository->getTransactionId()
                    ]
                );


                $ceresHelper->pushNotification($translator->trans('Mollie::Errors.failed'));
            }
        }

        return $response->redirectTo($lang . '/confirmation/' . $orderId);
    }

    /**
     * @param FrontendSessionStorageFactoryContract $frontendSessionStorageFactory
     * @return array
     */
    public function activateApplePay(FrontendSessionStorageFactoryContract $frontendSessionStorageFactory)
    {
        $frontendSessionStorageFactory->getPlugin()->setValue('mollie_apple_pay_active', true);
        return [];
    }

    /**
     * @param FrontendSessionStorageFactoryContract $frontendSessionStorageFactory
     * @param Request $request
     * @param Response $response
     * @param Checkout $checkout
     * @param CeresHelper $ceresHelper
     * @param Translator $translator
     * @param OrderService $orderService
     * @param ShopUrls $shopUrls
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function createOrderByCreditCard(FrontendSessionStorageFactoryContract $frontendSessionStorageFactory,
                                            Request $request,
                                            Response $response,
                                            Checkout $checkout,
                                            CeresHelper $ceresHelper,
                                            Translator $translator,
                                            OrderService $orderService,
                                            ShopUrls $shopUrls)
    {
        $lang   = $frontendSessionStorageFactory->getLocaleSettings()->language;

        $checkoutUrl = $shopUrls->checkout;
        $result = $orderService->preparePayment($checkout->getPaymentMethodId(), $request->get('mollie-cc-token'));

        $this->getLogger('checkCreditCard')->error(
            'Mollie::Debug.checkCreditCard',
            ['redirect' => $lang . $checkoutUrl]
        );

        if (array_key_exists('error', $result) || empty($result['_links']['checkout']['href'])) {
            $ceresHelper->pushNotification($translator->trans('Mollie::Errors.failed'));
            return $response->redirectTo($lang . $checkoutUrl);
        } else {
            return $response->redirectTo($lang . '/place-order');
        }
    }
}
