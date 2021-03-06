<?php

namespace Crm\FamilyModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Api\JsonValidationTrait;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Repository\ContentAccessRepository;
use Crm\UsersModule\Repository\UserActionsLogRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Response;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tracy\Debugger;

class ActivateFamilyRequestApiHandler extends ApiHandler
{
    use JsonValidationTrait;

    private $contentAccessRepository;

    private $donateSubscription;

    private $familyRequestsRepository;

    private $userActionsLogRepository;

    public function __construct(
        ContentAccessRepository $contentAccessRepository,
        DonateSubscription $donateSubscription,
        FamilyRequestsRepository $familyRequestsRepository,
        UserActionsLogRepository $userActionsLogRepository
    ) {
        $this->contentAccessRepository = $contentAccessRepository;
        $this->donateSubscription = $donateSubscription;
        $this->familyRequestsRepository = $familyRequestsRepository;
        $this->userActionsLogRepository = $userActionsLogRepository;
    }

    public function params(): array
    {
        return [];
    }

    public function handle(array $params): ResponseInterface
    {
        $authorization = $this->getAuthorization();
        $data = $authorization->getAuthorizedData();
        if (!isset($data['token'])) {
            $response = new JsonApiResponse(Response::S403_FORBIDDEN, [
                'message' => 'Cannot authorize user',
                'code' => 'cannot_authorize_user',
            ]);
            return $response;
        }

        $token = $data['token'];
        $user = $token->user;

        $requestValidationResult = $this->validateInput(
            __DIR__ . '/activate-family-request.schema.json',
            $this->rawPayload()
        );
        if ($requestValidationResult->hasErrorResponse()) {
            return $requestValidationResult->getErrorResponse();
        }

        $requestApi = $requestValidationResult->getParsedObject();
        $familyRequest = $this->familyRequestsRepository->findByCode($requestApi->code);
        if (!$familyRequest) {
            $response = new JsonApiResponse(Response::S404_NOT_FOUND, [
                'message' => "Family request code [$requestApi->code] not found",
                'code' => 'family_request_not_found',
            ]);
            return $response;
        }

        $connectResult = $this->donateSubscription->connectFamilyUser($user, $familyRequest);

        // return subscription if activation was successful
        if ($connectResult instanceof ActiveRow) {
            $subscription = $connectResult->slave_subscription;
            $subscriptionType = $subscription->subscription_type;
            $access = $this->contentAccessRepository->allForSubscriptionType($subscriptionType)->fetchAssoc('name');

            $result = [
                'code' => $requestApi->code,
                'subscription' => [
                    'start_at' => $subscription->start_time->format('c'),
                    'end_at' => $subscription->end_time->format('c'),
                    'code' => $subscriptionType->code,
                    'access' => array_keys($access),
                ],
            ];

            $response = new JsonApiResponse(Response::S201_CREATED, $result);

            return $response;
        }

        // otherwise process errors
        switch ($connectResult) {
            case DonateSubscription::ERROR_INTERNAL:
                $this->userActionsLogRepository->add(
                    $user->id,
                    'family.logged.error.internal',
                    ['request' => $requestApi->code]
                );
                $response = new JsonApiResponse(Response::S500_INTERNAL_SERVER_ERROR, [
                    'message' => 'Internal server error',
                    'code' => 'internal_server_error',
                ]);
                return $response;
            case DonateSubscription::ERROR_IN_USE:
                $this->userActionsLogRepository->add(
                    $user->id,
                    'family.logged.error.in-use',
                    ['request' => $requestApi->code]
                );
                $response = new JsonApiResponse(Response::S400_BAD_REQUEST, [
                    'message' => "User [$user->email] already used company code from this company subscription.",
                    'code' => 'family_request_one_per_user',
                ]);
                return $response;
            case DonateSubscription::ERROR_SELF_USE:
                $this->userActionsLogRepository->add(
                    $user->id,
                    'family.logged.error.self-use',
                    ['request' => $requestApi->code]
                );
                $response = new JsonApiResponse(Response::S400_BAD_REQUEST, [
                    'message' => "Cannot activate family request code [$requestApi->code] on parent's account",
                    'code' => 'family_request_self_use_forbidden',
                ]);
                return $response;
            case DonateSubscription::ERROR_MASTER_SUBSCRIPTION_EXPIRED:
                $this->userActionsLogRepository->add(
                    $user->id,
                    'family.register.error.master-subscription-expired',
                    ['user_id' => $user->id, 'request' => $requestApi->code]
                );
                $response = new JsonApiResponse(Response::S400_BAD_REQUEST, [
                    'message' => "Family request code [$requestApi->code] expired",
                    'code' => 'family_request_expired',
                ]);
                return $response;
            default:
                Debugger::log(
                    "Unknown error status [$connectResult] from family request activation of code [$requestApi->code].",
                    Debugger::ERROR
                );
                $response = new JsonApiResponse(Response::S500_INTERNAL_SERVER_ERROR, [
                    'message' => 'Internal server error',
                    'code' => 'internal_server_error',
                ]);
                return $response;
        }
    }

    public function setDonateSubscriptionNow(\DateTime $dateTime): void
    {
        $this->donateSubscription->setNow($dateTime);
    }
}
