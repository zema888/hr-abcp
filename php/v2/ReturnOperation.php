<?php

namespace NW\WebService\References\Operations\Notification;

/**
 * резюме по коду:
 * назначение кода: отправить сообщение сотрудникам и клиенту
 * Качество: нет валидации, трудночитаемо, всё свалено в кучу, лучше бы использовать объекты запроса,ответа, а не массивы
 * рефакторил только тут (как было в задании, но файл php/v2/others.php тоже странный)
 */
class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @return array
     * @throws \Exception
     */
    public function doOperation(): array
    {
        $data = $this->getData();
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => '',
            ],
        ];

        if ($data['resellerId'] === 0) {
            $result['notificationClientBySms']['message'] = 'Empty resellerId';

            return $result;
        }


        $reseller = $this->getReseller($data['resellerId']);

        $client = $this->getClient(
            $data['clientId'],
            $data['resellerId']
        );

        $creator = $this->getCreator($data['creatorId']);

        $expert = $this->getExpert($data['expertId']);

        $templateData = $this->createTemplateData(
            $data,
            $creator,
            $client,
            $expert
        );

        $emailFrom = getResellerEmailFrom();


        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($data['resellerId'], 'tsGoodsReturn');
        if (!empty($emailFrom)) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage(
                    [
                        [ // MessageTypes::EMAIL
                          'emailFrom' => $emailFrom,
                          'emailTo'   => $email,
                          'subject'   => __('complaintEmployeeEmailSubject', $templateData, $data['resellerId']),
                          'message'   => __('complaintEmployeeEmailBody', $templateData, $data['resellerId']),
                        ],
                    ],
                    $data['resellerId'],
                    NotificationEvents::CHANGE_RETURN_STATUS
                );
                $result['notificationEmployeeByEmail'] = true;

            }
        }

        // Шлём клиентское уведомление, только если произошла смена статуса
        if ($data['notificationType'] === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            if (!empty($emailFrom) && !empty($client->email)) {
                MessagesClient::sendMessage(
                    [
                        [ // MessageTypes::EMAIL
                          'emailFrom' => $emailFrom,
                          'emailTo'   => $client->email,
                          'subject'   => __('complaintClientEmailSubject', $templateData, $data['resellerId']),
                          'message'   => __('complaintClientEmailBody', $templateData, $data['resellerId']),
                        ],
                    ],
                    $data['resellerId'],
                    $client->id,
                    NotificationEvents::CHANGE_RETURN_STATUS,
                    $data['differences']['to']
                );
                $result['notificationClientByEmail'] = true;
            }

            if (!empty($client->mobile)) {
                $error = '';
                $res = NotificationManager::send($data['resellerId'], $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }

    /**
     * @return array{
     *     resellerId: int,
     *     notificationType: int,
     *     clientId: int,
     *     creatorId: int,
     *     expertId: int,
     *     differences: null|array{to:null|string, from: null|string},
     *     complaintId: int,
     *     complaintNumber: string,
     *     consumptionId: int,
     *     consumptionNumber: string,
     *     agreementNumber: string,
     *     date: string
     * }
     * @throws \Exception
     */
    public function getData(): array
    {
        $data = (array)$this->getRequest('data');
        $fields = [
            'resellerId' => [
                'required' => false,
                'prepare' => 'intval',
            ],
            'notificationType' => [
                'required' => true,
                'prepare' => 'intval',
            ],
            'clientId' => [
                'required' => true,
                'prepare' => 'intval',
            ],
            'creatorId' => [
                'required' => true,
                'prepare' => 'intval',
            ],
            'expertId' => [
                'required' => true,
                'prepare' => 'intval',
            ],
            'differences' => [
                'required' => false,
                'prepare' => function($value) {
                    return empty($value) ? null : [
                        'to' => intval($value['to'] ?? 0),
                        'from' => intval($value['from'] ?? 0),
                    ];
                }
            ],
            'complaintId' => [
                'required' => false,
                'prepare' => 'intval',
            ],
            'complaintNumber' => [
                'required' => false,
                'prepare' => 'strval',
            ],
            'consumptionId' => [
                'required' => false,
                'prepare' => 'intval',
            ],
            'consumptionNumber' => [
                'required' => false,
                'prepare' => 'strval',
            ],
            'agreementNumber' => [
                'required' => false,
                'prepare' => 'strval',
            ],
            'date' => [
                'required' => false,
                'prepare' => 'strval',
            ],
        ];

        $result = [];

        foreach ($fields as $field => $rules) {
            if ($rules['required'] && empty($data[$field])) {
                throw new \Exception(
                    sprintf('Empty %s', $field),
                    400
                );
            }

            $result[$field] = empty($data[$field]) ? null : $rules['prepare']($data[$field]);
        }

        return $result;
    }

    private function getCreator(
        int $creatorId
    ): Employee
    {
        $creator = Employee::getById($creatorId);
        if ($creator === null) {
            throw new \Exception('Creator not found!', 400);
        }

        return $creator;
    }

    private function getExpert(
        int $expertId
    ): Contractor
    {
        $expert = Employee::getById($expertId);
        if ($expert === null) {
            throw new \Exception('Creator not found!', 400);
        }

        return $expert;
    }

    /**
     * @param $resellerId
     *
     * @return Seller
     * @throws \Exception
     */
    public function getReseller($resellerId): Contractor
    {
        $reseller = Seller::getById($resellerId);
        if ($reseller === null) {
            throw new \Exception('Seller not found!', 400);
        }

        return $reseller;
    }

    /**
     * @param int $clientId
     * @param int $resellerId
     *
     * @return Contractor
     * @throws \Exception
     */
    public function getClient(
        int $clientId,
        int $resellerId,
    ): Contractor
    {
        $client = Contractor::getById($clientId);
        if ($client === null || $client->type !== Contractor::TYPE_CUSTOMER
            || $client->Seller->id !== $resellerId
        ) {
            throw new \Exception('сlient not found!', 400);
        }

        return $client;
    }

    /**
     * @param array{
     *      resellerId: int,
     *      notificationType: int,
     *      clientId: int,
     *      creatorId: int,
     *      expertId: int,
     *      differences: null|array{to:null|string, from: null|string},
     *      complaintId: int,
     *      complaintNumber: string,
     *      consumptionId: int,
     *      consumptionNumber: string,
     *      agreementNumber: string,
     *      date: string
     *  }      $data
     * @param Contractor $creator
     * @param Contractor $client
     * @param Contractor $expert
     *
     * @return array
     * @throws \Exception
     */
    private function createTemplateData(
        array $data,
        Contractor $creator,
        Contractor $client,
        Contractor $expert,
    ): array
    {

        $cFullName = $client->getFullName();
        if (empty($client->getFullName())) {
            $cFullName = $client->name;
        }

        $differences = '';
        if ($data['notificationType'] === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $data['resellerId']);
        } elseif ($data['notificationType'] === self::TYPE_CHANGE && !empty($data['differences'])) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName($data['differences']['from']),
                'TO'   => Status::getName($data['differences']['to']),
            ], $data['resellerId']);
        }

        $templateData = [
            'COMPLAINT_ID'       => $data['complaintId'],
            'COMPLAINT_NUMBER'   => $data['complaintNumber'],
            'CREATOR_ID'         => $data['creatorId'],
            'CREATOR_NAME'       => $creator->getFullName(),
            'EXPERT_ID'          => $data['expertId'],
            'EXPERT_NAME'        => $expert->getFullName(),
            'CLIENT_ID'          => $data['clientId'],
            'CLIENT_NAME'        => $cFullName,
            'CONSUMPTION_ID'     => $data['consumptionId'],
            'CONSUMPTION_NUMBER' => $data['consumptionNumber'],
            'AGREEMENT_NUMBER'   => $data['agreementNumber'],
            'DATE'               => $data['date'],
            'DIFFERENCES'        => $differences,
        ];

        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }

        return $templateData;
    }
}
