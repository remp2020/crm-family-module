<?php

namespace Crm\FamilyModule\Api;

use Crm\ApiModule\Models\Api\ApiHandler;
use Crm\FamilyModule\Repositories\FamilyRequestsRepository;
use Nette\Http\Response;
use Nette\Utils\DateTime;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

class ListFamilyRequestsApiHandler extends ApiHandler
{
    private $familyRequestsRepository;

    public function __construct(
        FamilyRequestsRepository $familyRequestsRepository,
    ) {
        $this->familyRequestsRepository = $familyRequestsRepository;
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

        $response = new JsonApiResponse(Response::S200_OK, $result);

        return $response;
    }

    private function returnDateTimeFormatted(?DateTime $dateTime)
    {
        return ($dateTime === null) ? null : $dateTime->format('c');
    }
}
