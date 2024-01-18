<?php

namespace Crm\FamilyModule\Api;

use Crm\ApiModule\Models\Api\ApiHandler;
use Crm\ApiModule\Models\Api\JsonValidationTrait;
use Crm\FamilyModule\Models\DonateSubscription;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Crm\SubscriptionsModule\Repositories\ContentAccessRepository;
use Crm\UsersModule\Models\Auth\UsersApiAuthorizationInterface;
use Crm\UsersModule\Repositories\UserActionsLogRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Response;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tracy\Debugger;

class ActivateFamilyRequestApiHandler extends ApiHandler
{
    use JsonValidationTrait;

    public function __construct(
        private ContentAccessRepository $contentAccessRepository,
        private DonateSubscription $donateSubscription,
        private FamilyRequestsRepository $familyRequestsRepository,
        private UserActionsLogRepository $userActionsLogRepository
    ) {
        parent::__construct();
    }

    public function params(): array
    {
        return [];
    }

    public function handle(array $params): ResponseInterface
    {
        $authorization = $this->getAuthorization();
        if (!$authorization instanceof UsersApiAuthorizationInterface) {
            throw new \Exception("Invalid authorization used for the API, it needs to implement 'UsersApiAuthorizationInterface': " . get_class($authorization));
        }

        $users = $authorization->getAuthorizedUsers();
        if (count($users) !== 1) {
            throw new \Exception('Incorrect number of authorized users, expected 1 but got ' . count($users));
        }
        $user = reset($users);

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
            case DonateSubscription::ERROR_REQUEST_WRONG_STATUS:
                $this->userActionsLogRepository->add(
                    $user->id,
                    'family.register.error.request-wrong-state',
                    ['user_id' => $user->id, 'request' => $requestApi->code]
                );
                $response = new JsonApiResponse(Response::S409_CONFLICT, [
                    'message' => "Family request code [$requestApi->code] has wrong status: {$familyRequest->status}",
                    'code' => 'family_request_wrong_status',
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
