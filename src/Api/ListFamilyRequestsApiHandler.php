<?php

namespace Crm\FamilyModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Api\JsonResponse;
use Crm\ApiModule\Authorization\ApiAuthorizationInterface;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Nette\Http\Response;
use Nette\Utils\DateTime;

class ListFamilyRequestsApiHandler extends ApiHandler
{
    private $familyRequestsRepository;

    public function __construct(
        FamilyRequestsRepository $familyRequestsRepository
    ) {
        $this->familyRequestsRepository = $familyRequestsRepository;
    }

    public function params(): array
    {
        return [];
    }

    public function handle(ApiAuthorizationInterface $authorization)
    {
        $data = $authorization->getAuthorizedData();
        if (!isset($data['token'])) {
            $response = new JsonResponse([
                'message' => 'Cannot authorize user',
                'code' => 'cannot_authorize_user',
            ]);
            $response->setHttpCode(Response::S403_FORBIDDEN);
            return $response;
        }

        $token = $data['token'];
        $user = $token->user;

        $requests = $this->familyRequestsRepository->userFamilyRequest($user)
            ->order('updated_at DESC, created_at DESC, id');

        $result = [
            'codes' => [],
        ];

        foreach ($requests as $request) {
            $result['codes'][] = [
                'code' => $request->code,
                'master_user_id' => $request->master_user_id,
                'status' => $request->status,
                'subscription_type_code' => $request->subscription_type->code,
                'slave_user_id' => $request->slave_user_id,
                'created_at' => $this->returnDateTimeFormatted($request->created_at),
                'updated_at' => $this->returnDateTimeFormatted($request->updated_at),
                'opened_at' => $this->returnDateTimeFormatted($request->opened_at),
                'accepted_at' => $this->returnDateTimeFormatted($request->accepted_at),
                'canceled_at' => $this->returnDateTimeFormatted($request->canceled_at),
                'expires_at' => $this->returnDateTimeFormatted($request->expires_at),
            ];
        }

        $response = new JsonResponse($result);
        $response->setHttpCode(Response::S200_OK);

        return $response;
    }

    private function returnDateTimeFormatted(?DateTime $dateTime)
    {
        return ($dateTime === null) ? null : $dateTime->format('c');
    }
}
