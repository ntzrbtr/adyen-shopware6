<?php declare(strict_types=1);
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2022 Adyen N.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <shopware@adyen.com>
 */

namespace Adyen\Shopware\Controller\StoreApi\Payment;

use Adyen\Service\Validator\CheckoutStateDataValidator;
use Adyen\Shopware\Exception\PaymentFailedException;
use Adyen\Shopware\Handlers\PaymentResponseHandler;
use Adyen\Shopware\Service\ConfigurationService;
use Adyen\Shopware\Service\PaymentDetailsService;
use Adyen\Shopware\Service\PaymentMethodsService;
use Adyen\Shopware\Service\PaymentResponseService;
use Adyen\Shopware\Service\PaymentStatusService;
use Adyen\Shopware\Service\Repository\OrderRepository;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Checkout\Order\SalesChannel\SetPaymentOrderRouteResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;

/**
 * Class PaymentController
 * @package Adyen\Shopware\Controller\StoreApi\Payment
 * @Route(defaults={"_routeScope"={"store-api"}})
 */
class PaymentController
{
    /**
     * @var PaymentMethodsService
     */
    private $paymentMethodsService;
    /**
     * @var PaymentDetailsService
     */
    private $paymentDetailsService;
    /**
     * @var CheckoutStateDataValidator
     */
    private $checkoutStateDataValidator;
    /**
     * @var PaymentStatusService
     */
    private $paymentStatusService;
    /**
     * @var PaymentResponseHandler
     */
    private $paymentResponseHandler;
    /**
     * @var PaymentResponseService
     */
    private $paymentResponseService;
    /**
     * @var OrderRepository
     */
    private $orderRepository;
    /**
     * @var OrderService
     */
    private $orderService;
    /**
     * @var EntityRepository
     */
    private $orderTransactionRepository;
    /**
     * @var StateMachineRegistry
     */
    private $stateMachineRegistry;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var ConfigurationService
     */
    private $configurationService;
    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;
    /**
     * @var InitialStateIdLoader
     */
    private $initialStateIdLoader;

    /**
     * StoreApiController constructor.
     *
     * @param PaymentMethodsService $paymentMethodsService
     * @param PaymentDetailsService $paymentDetailsService
     * @param CheckoutStateDataValidator $checkoutStateDataValidator
     * @param PaymentStatusService $paymentStatusService
     * @param PaymentResponseHandler $paymentResponseHandler
     * @param PaymentResponseService $paymentResponseService
     * @param OrderRepository $orderRepository
     * @param OrderService $orderService
     * @param StateMachineRegistry $stateMachineRegistry
     * @param InitialStateIdLoader $initialStateIdLoader
     * @param LoggerInterface $logger
     * @param EntityRepository $orderTransactionRepository
     * @param ConfigurationService $configurationService
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     */
    public function __construct(
        PaymentMethodsService $paymentMethodsService,
        PaymentDetailsService $paymentDetailsService,
        CheckoutStateDataValidator $checkoutStateDataValidator,
        PaymentStatusService $paymentStatusService,
        PaymentResponseHandler $paymentResponseHandler,
        PaymentResponseService $paymentResponseService,
        OrderRepository $orderRepository,
        OrderService $orderService,
        StateMachineRegistry $stateMachineRegistry,
        InitialStateIdLoader $initialStateIdLoader,
        LoggerInterface $logger,
        EntityRepository $orderTransactionRepository,
        ConfigurationService $configurationService,
        OrderTransactionStateHandler $orderTransactionStateHandler
    ) {
        $this->paymentMethodsService = $paymentMethodsService;
        $this->paymentDetailsService = $paymentDetailsService;
        $this->checkoutStateDataValidator = $checkoutStateDataValidator;
        $this->paymentStatusService = $paymentStatusService;
        $this->paymentResponseHandler = $paymentResponseHandler;
        $this->paymentResponseService = $paymentResponseService;
        $this->orderRepository = $orderRepository;
        $this->orderService = $orderService;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->logger = $logger;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->configurationService = $configurationService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->initialStateIdLoader = $initialStateIdLoader;
    }

    /**
     * @Route(
     *     "/store-api/adyen/payment-methods",
     *     name="store-api.action.adyen.payment-methods",
     *     methods={"GET"}
     * )
     *
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function getPaymentMethods(SalesChannelContext $context): JsonResponse
    {
        return new JsonResponse($this->paymentMethodsService->getPaymentMethods($context));
    }

    /**
     * @Route(
     *     "/store-api/adyen/payment-details",
     *     name="store-api.action.adyen.payment-details",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function postPaymentDetails(
        Request $request,
        SalesChannelContext $context
    ): JsonResponse {
        $orderId = $request->request->get('orderId');
        $paymentResponse = $this->paymentResponseService->getWithOrderId($orderId);
        if (!$paymentResponse) {
            $message = 'Could not find a transaction';
            $this->logger->error($message, ['orderId' => $orderId]);
            return new JsonResponse($message, 404);
        }

        // Get state data object if sent
        $stateData = $request->request->get('stateData', '');
        $stateData = json_decode($stateData, true);

        // Validate stateData object
        if (!empty($stateData)) {
            $stateData = $this->checkoutStateDataValidator->getValidatedAdditionalData($stateData);
        }

        if (empty($stateData['details'])) {
            $message = 'Details missing in $stateData';
            $this->logger->error(
                $message,
                ['stateData' => $stateData]
            );
            return new JsonResponse($message, 400);
        }

        try {
            $result = $this->paymentDetailsService->getPaymentDetails(
                $stateData,
                $paymentResponse->getOrderTransaction()
            );
        } catch (PaymentFailedException $exception) {
            $message = 'Error occurred finalizing payment';
            $this->logger->error(
                $message,
                ['orderId' => $orderId, 'paymentDetails' => $stateData]
            );
            return new JsonResponse($message, 500);
        }

        // If donation token is present in the result, store it in the custom fields of order transaction.
        $donationToken = $result->getDonationToken();
        if (isset($donationToken) &&
            $this->configurationService->isAdyenGivingEnabled($context->getSalesChannelId())) {
            $storedTransactionCustomFields = $paymentResponse->getOrderTransaction()->getCustomFields() ?: [];
            $transactionCustomFields[PaymentResponseHandler::DONATION_TOKEN] = $donationToken;

            $customFields = array_merge(
                $storedTransactionCustomFields,
                $transactionCustomFields
            );

            $paymentResponse->getOrderTransaction()->setCustomFields($customFields);
            $orderTransactionId = $paymentResponse->getOrderTransactionId();
            $context->getContext()->scope(
                Context::SYSTEM_SCOPE,
                function (Context $context) use ($orderTransactionId, $customFields) {
                    $this->orderTransactionRepository->update([
                        [
                            'id' => $orderTransactionId,
                            'customFields' => $customFields,
                        ]
                    ], $context);
                }
            );
        }

        return new JsonResponse($this->paymentResponseHandler->handleAdyenApis($result));
    }

    /**
     * @Route(
     *     "/store-api/adyen/payment-status",
     *     name="store-api.action.adyen.payment-status",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function getPaymentStatus(Request $request, SalesChannelContext $context): JsonResponse
    {
        $orderId = $request->request->get('orderId');
        if (empty($orderId)) {
            return new JsonResponse('Order ID not provided', 400);
        }

        try {
            return new JsonResponse(
                $this->paymentStatusService->getWithOrderId($orderId)
            );
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage());
            return new JsonResponse(["isFinal" => true]);
        }
    }

    /**
     * @OA\Post(
     *      path="/adyen/set-payment",
     *      summary="set payment for an order",
     *      operationId="orderSetPayment",
     *      tags={"Store API", "Account"},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(
     *                  property="paymentMethodId",
     *                  description="The ID of the new paymentMethod",
     *                  type="string"
     *              ),
     *              @OA\Property(property="orderId", description="The ID of the order", type="string")
     *          )
     *      ),
     *      @OA\Response(
     *          response="200",
     *          description="Successfully set a payment",
     *          @OA\JsonContent(ref="#/components/schemas/SuccessResponse")
     *     )
     * )
     * @Route(
     *     "/store-api/adyen/set-payment",
     *     name="store-api.action.adyen.set-payment",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @return SetPaymentOrderRouteResponse
     */
    public function updatePaymentMethod(Request $request, SalesChannelContext $context): SetPaymentOrderRouteResponse
    {
        $this->setPaymentMethod(
            $request->request->get('paymentMethodId'),
            $request->request->get('orderId'),
            $context
        );
        return new SetPaymentOrderRouteResponse();
    }

    private function setPaymentMethod(
        string $paymentMethodId,
        string $orderId,
        SalesChannelContext $salesChannelContext
    ): void {
        $context = $salesChannelContext->getContext();
        $initialStateId = $this->initialStateIdLoader->get(OrderTransactionStates::STATE_MACHINE);

        /** @var OrderEntity $order */
        $order = $this->orderRepository->getOrder($orderId, $context, ['transactions']);

        $context->scope(
            Context::SYSTEM_SCOPE,
            function () use ($order, $initialStateId, $orderId, $paymentMethodId, $context): void {
                if ($order->getTransactions() !== null && $order->getTransactions()->count() >= 1) {
                    foreach ($order->getTransactions() as $transaction) {
                        if ($transaction->getStateMachineState()->getTechnicalName()
                            !== OrderTransactionStates::STATE_CANCELLED) {
                            $this->orderTransactionStateHandler->cancel(
                                $transaction->getId(),
                                $context
                            );
                        }
                    }
                }
                $transactionAmount = new CalculatedPrice(
                    $order->getPrice()->getTotalPrice(),
                    $order->getPrice()->getTotalPrice(),
                    $order->getPrice()->getCalculatedTaxes(),
                    $order->getPrice()->getTaxRules()
                );

                $this->orderRepository->update($orderId, [
                    'transactions' => [
                        [
                            'id' => Uuid::randomHex(),
                            'paymentMethodId' => $paymentMethodId,
                            'stateId' => $initialStateId,
                            'amount' => $transactionAmount,
                        ],
                    ],
                ], $context);
            }
        );
    }

    /**
     * @Route(
     *     "/store-api/adyen/cancel-order-transaction",
     *     name="store-api.action.adyen.cancel-order-transaction",
     *     methods={"POST"}
     * )
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return JsonResponse
     * @throws \Adyen\Exception\MissingDataException
     * @throws \JsonException
     */
    public function cancelOrderTransaction(
        Request $request,
        SalesChannelContext $salesChannelContext
    ): JsonResponse {
        $context = $salesChannelContext->getContext();
        $orderId = $request->request->get('orderId');
        $order = $this->orderRepository->getOrder($orderId, $context, ['transactions']);

        $transaction = $order->getTransactions()->filterByState(OrderTransactionStates::STATE_IN_PROGRESS)->first();

        $this->stateMachineRegistry->transition(
            new Transition(OrderTransactionDefinition::ENTITY_NAME, $transaction->getId(), 'cancel', 'stateId'),
            $context
        );

        return new JsonResponse($this->paymentStatusService->getWithOrderId($orderId));
    }
}
