<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 02.11.20 03:54:22
 */

declare(strict_types = 1);
namespace dicr\fns\openapi;

use dicr\fns\openapi\types\AuthAppInfo;
use dicr\fns\openapi\types\AuthRequest;
use dicr\fns\openapi\types\AuthResponse;
use dicr\fns\openapi\types\CheckTicketInfo;
use dicr\fns\openapi\types\CheckTicketRequest;
use dicr\fns\openapi\types\CheckTicketResponse;
use dicr\fns\openapi\types\CheckTicketResult;
use dicr\fns\openapi\types\GetMessageRequest;
use dicr\fns\openapi\types\GetMessageResponse;
use dicr\fns\openapi\types\GetTicketInfo;
use dicr\fns\openapi\types\GetTicketRequest;
use dicr\fns\openapi\types\GetTicketResponse;
use dicr\fns\openapi\types\GetTicketResult;
use dicr\fns\openapi\types\Message;
use dicr\fns\openapi\types\ProcessingStatuses;
use dicr\fns\openapi\types\SendMessageRequest;
use dicr\fns\openapi\types\SendMessageResponse;
use yii\helpers\Html;
use SimpleXMLElement;
use Yii;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\httpclient\Client;

use function base64_encode;
use function ob_get_clean;
use function ob_start;
use function sleep;
use function strtotime;

/**
 * Клиент ФНС OpenAPI.
 *
 * @property-read Client $httpClient
 */
class FNSClient extends Component
{
    /** @var string */
    public const API_URL = 'https://openapi.nalog.ru:8090';

    /** @var string сервис авторизации */
    public const SERVICE_AUTH = '/open-api/AuthService/0.1';

    /** @var string сервис чеков */
    public const SERVICE_KKT = '/open-api/ais3/KktService/0.1';

    /** @var string xmlns:soap */
    public const XMLNS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

    /** @var string xmlns:ns для синхронного сервиса */
    public const XMLNS_SYNC = 'urn://x-artefacts-gnivc-ru/inplat/servin/OpenApiMessageConsumerService/types/1.0';

    /** @var string xmlns:ns для асинхронного сервиса */
    public const XMLNS_ASYNC = 'urn://x-artefacts-gnivc-ru/inplat/servin/OpenApiAsyncMessageConsumerService/types/1.0';

    /** @var string xmlns:tns для AuthService */
    public const XMLNS_AUTH = 'urn://x-artefacts-gnivc-ru/ais3/kkt/AuthService/types/1.0';

    /** @var string xmlns:tns для KktService */
    public const XMLNS_KKT = 'urn://x-artefacts-gnivc-ru/ais3/kkt/KktTicketService/types/1.0';

    /** @var string URL API */
    public $url = self::API_URL;

    /** @var string мастер-токен, выданный ФНС */
    public $masterToken;

    /** @var string мастер-токен, выданный ФНС */
    public $proxyIp;

    /** @var string мастер-токен, выданный ФНС */
    public $proxyPass;

    /** @var int пауза между запросами, сек при ожидании ответа асинхронного сервиса */
    public $asyncPause = 1;

    /** @var int кол-во попыток получения ответа при ожидании асинхронного сервиса */
    public $asyncRetries = 10;

    /**
     * @inheritDoc
     * @throws InvalidConfigException
     */
    public function init() : void
    {
        parent::init();

        if (empty($this->url)) {
            throw new InvalidConfigException('url');
        }

        if (empty($this->masterToken)) {
            throw new InvalidConfigException('materToken');
        }

        if ($this->asyncPause < 1) {
            throw new InvalidConfigException('asyncPause');
        }

        if ($this->asyncRetries < 1) {
            throw new InvalidConfigException('asyncRetries');
        }
    }

    /** @var Client */
    private $_httpClient;

    /**
     * HTTP-клиент.
     *
     * @return Client
     */
    public function getHttpClient() : Client
    {
        if ($this->_httpClient === null) {
            $this->_httpClient = new Client([
                'baseUrl' => $this->url
            ]);
        }

        return $this->_httpClient;
    }

    /**
     * Самозакрывающийся тэг XML.
     *
     * @param string $name
     * @param float|int|string|null $content
     * @param array $options
     * @return string
     */
    public static function xml(string $name, float|int|string|null $content = '', array $options = []): string
    {
        $content = (string)$content;

        return '<' . $name . Html::renderTagAttributes($options) .
            ($content === '' ? '/>' : '>' . $content . '</' . $name . '>');
    }

    /**
     * Документ soap.
     *
     * @param mixed $content содержимое Body
     * @return string
     */
    private static function soapXML($content) : string
    {
        ob_start();
        echo Html::beginTag('soap:Envelope', [
            'xmlns:soap' => self::XMLNS_SOAP,
        ]);

        echo self::xml('soap:Header');
        echo self::xml('soap:Body', (string)$content);
        echo Html::endTag('soap:Envelope');

        return ob_get_clean();
    }

    /**
     * SOAP запрос
     *
     * @param string $url URL
     * @param mixed $request запрос
     * @param ?string $token
     * @return SimpleXMLElement ответ (содержимое SOAP Body)
     * @throws Exception
     */
    private function soapCall(string $url, $request, ?string $token = null) : SimpleXMLElement
    {
        $data = self::soapXML($request);

        $req = self::curl(self::API_URL.$url, $data, [
            'headers'=>[
                'Content-Type: text/xml',
                'FNS-OpenApi-UserToken: '.base64_encode(__CLASS__),
                'FNS-OpenApi-Token: '.$token
            ],
            'asBody'=>true,
            'proxy'=>[
                'value'=>$this->proxyIp,
                'userpwd'=>$this->proxyPass,
                'type'=>'socks5',
            ],
            'timeout'=>25,
        ]);

        if ( $req['info']['http_code']!=200 ) {
            print_r($req);
            exit;
        }

        return (new SimpleXMLElement(utf8_encode($req['result'])))
            ->children(self::XMLNS_SOAP)->Body;

    }

    public static function curl($url, $data=null, $params=[
        'cookie'=>false,
        'json'=>false,
        'header'=>false,
        'proxy'=>false,
        'referer'=>false,
        'origin'=>false,
        'headers'=>[],
        'post'=>false,
        'timeout'=>10,
        'generateAgent'=>false,
        'asBody'=>false,
        'sslversion'=>false,
        'method'=>false,
    ])
    {
        $ch = curl_init($url);

        if ( $data || $params['post'] ) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ( $data ) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, ( !$params['asBody'] ) ? (($params['json']) ? json_encode($data) : http_build_query($data)) : $data);
            }
        }
        if ( $params['method'] ) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $params['method']);
        }
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, $params['header']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if ( $params['sslversion'] ) {
            curl_setopt($ch, CURLOPT_SSLVERSION, 3);
        }

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $params['timeout']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $params['timeout']);

        $headers = $params['headers'];

        if ( $params['json'] ) {
            $headers[] = 'Content-Type: application/json';
//            $headers[] = 'x-requested-with: XMLHttpRequest';
        }

        if ( $params['origin'] ) {
            $headers[] = 'origin: '.$params['origin'];
        }

        if ( $params['cookie'] ) {
            curl_setopt($ch, CURLOPT_COOKIE, $params['cookie']);
        }

        if ( $params['referer'] ) {
            curl_setopt($ch, CURLOPT_REFERER, $params['referer']);
        }

        if ( $params['proxy']=='tor' ) {
            $proxy = '127.0.0.1:9150';
        } else {
            $proxy = $params['proxy'];
        }

        if ( $proxy ) {
            if ( isset($proxy['value'])  ) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy['value']);
            } else {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
            }
            if ( $proxy=='127.0.0.1:9150' || (isset($proxy['type']) && $proxy['type']=='socks5') ) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            }
            if ( isset($proxy['userpwd']) ) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['userpwd']);
            }
        }

        if ( is_array($headers) && count($headers)>0 ) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ( $params['header'] ) {
            $_headers = [];
            curl_setopt($ch, CURLOPT_HEADERFUNCTION,
                function($curl, $header) use (&$_headers)
                {
                    $len = strlen($header);
                    $header = explode(':', $header, 2);
                    if (count($header) < 2) // ignore invalid headers
                        return $len;

                    $_headers[strtolower(trim($header[0]))][] = trim($header[1]);

                    return $len;
                }
            );
        }

        $result = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = null;
        if ( curl_errno($ch) ) {
            $error = curl_error($ch);
        }

        if ( $params['header'] ) {
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $header = mb_substr($result, 0, $header_size);
            $body = mb_substr($result, $header_size);

            return [
                'info'=>$info,
                'result'=>$body,
                'headers'=>$_headers,
                'error'=>$error,
            ];
        }

        return [
            'info'=>$info,
            'result'=>$result,
            'error'=>$error,
        ];
    }

    /**
     * Функция SOAP SendMessage асинхронного сервиса.
     *
     * @param string $url
     * @param SendMessageRequest $sendMessageRequest
     * @param ?string $token
     * @return SendMessageResponse
     * @throws Exception
     * @noinspection PhpUndefinedFieldInspection
     */
    private function sendMessage(
        string $url,
        SendMessageRequest $sendMessageRequest,
        ?string $token = null
    ) : SendMessageResponse {
        $xml = $this->soapCall($url, $sendMessageRequest, $token);

        return SendMessageResponse::fromXml($xml
            ->children(self::XMLNS_ASYNC)->SendMessageResponse
        );
    }

    /**
     * Функция SOAP GetMessage синхронного сервиса.
     *
     * @param string $url
     * @param GetMessageRequest $getMessageRequest
     * @param ?string $token
     * @return GetMessageResponse
     * @throws Exception
     * @noinspection PhpUndefinedFieldInspection
     */
    private function getMessageSync(
        string $url,
        GetMessageRequest $getMessageRequest,
        ?string $token = null
    ) : GetMessageResponse {
        $getMessageRequest->xmlns = self::XMLNS_SYNC;

        $xml = $this->soapCall($url, $getMessageRequest, $token);

        return GetMessageResponse::fromXml($xml
            ->children(self::XMLNS_SYNC)->GetMessageResponse
        );
    }

    /**
     * Функция SOAP GetMessage асинхронного сервиса.
     *
     * @param string $url
     * @param GetMessageRequest $getMessageRequest
     * @param ?string $token
     * @return GetMessageResponse
     * @throws Exception
     * @noinspection PhpUndefinedFieldInspection
     */
    private function getMessageAsync(
        string $url,
        GetMessageRequest $getMessageRequest,
        ?string $token = null
    ) : GetMessageResponse {
        $getMessageRequest->xmlns = self::XMLNS_ASYNC;

        for ($i = 0; $i < $this->asyncRetries; $i++) {
            sleep($this->asyncPause);

            $xml = $this->soapCall($url, $getMessageRequest, $token);

            $getMessageResponse = GetMessageResponse::fromXml($xml
                ->children(self::XMLNS_ASYNC)->GetMessageResponse
            );

            if ($getMessageResponse->ProcessingStatus === ProcessingStatuses::COMPLETED) {
                break;
            }
        }

        if (empty($getMessageResponse->ProcessingStatus) ||
            $getMessageResponse->ProcessingStatus !== ProcessingStatuses::COMPLETED) {
//            throw new Exception('Ошибка получения ответа сообщения');
            print_r('Ошибка получения ответа сообщения');
            print_r($getMessageResponse);
            exit;
        }

        return $getMessageResponse;
    }

    /**
     * Получает/возвращает токен авторизации.
     *
     * @return string токен
     * @throws Exception
     */
    public function authToken() : string
    {
        $key = [__METHOD__, $this->url, $this->masterToken];

        $token = Yii::$app->cache->get($key);
        if (empty($token)) {
            $getMessageResponse = $this->getMessageSync(self::SERVICE_AUTH, new GetMessageRequest([
                'Message' => new Message([
                    'any' => new AuthRequest([
                        'AuthAppInfo' => new AuthAppInfo([
                            'MasterToken' => $this->masterToken
                        ])
                    ])
                ])
            ]));

            /** @var AuthResponse $authResponse */
            $authResponse = $getMessageResponse->Message->any;

            $token = $authResponse->Result->Token ?? '';
            $expireTime = $authResponse->Result->ExpireTime;
            if (empty($token)) {
                throw new Exception('Ошибка авторизации: ' . ($authResponse->Fault->Message ?? ''));
            }

            // сохраняем в кеше
            Yii::$app->cache->set(
                $key, $token, $expireTime ? strtotime($expireTime) - time() : null
            );

            Yii::debug('Получен новый токен: ' . $token . ' до ' . $expireTime, __METHOD__);
        }

        return $token;
    }

    /**
     * Проверка чека.
     *
     * @param CheckTicketInfo $ticketInfo
     * @return CheckTicketResult
     * @throws Exception
     */
    public function checkTicket(CheckTicketInfo $ticketInfo) : CheckTicketResult
    {
        $token = $this->authToken();

        // отправляем сообщение
        $sendMessageResponse = $this->sendMessage(self::SERVICE_KKT, new SendMessageRequest([
            'Message' => new Message([
                'any' => new CheckTicketRequest([
                    'CheckTicketInfo' => $ticketInfo
                ])
            ])
        ]), $token);

        // код отправленного сообщения
        $messageId = $sendMessageResponse->MessageId;
        if (empty($messageId)) {
            throw new Exception('Ошибка получения MessageId');
        }

        // получаем ответ
        $getMessageResponse = $this->getMessageAsync(self::SERVICE_KKT, new GetMessageRequest([
            'MessageId' => $messageId
        ]), $token);

        /** @var CheckTicketResponse $checkTicketResponse */
        $checkTicketResponse = $getMessageResponse->Message->any;

        if (! empty($checkTicketResponse->Fault)) {
            throw new Exception('Ошибка проверки чека: ' . $checkTicketResponse->Fault->Message);
        }

        return $checkTicketResponse->Result;
    }

    /**
     * Получение данных чека.
     *
     * @param GetTicketInfo $ticketInfo
     * @return GetTicketResult данные чека
     * @throws Exception
     */
    public function getTicket(GetTicketInfo $ticketInfo) : GetTicketResult
    {
        $token = $this->authToken();

        // отправляем сообщение
        $sendMessageResponse = $this->sendMessage(self::SERVICE_KKT, new SendMessageRequest([
            'Message' => new Message([
                'any' => new GetTicketRequest([
                    'GetTicketInfo' => $ticketInfo
                ])
            ])
        ]), $token);

        // код отправленного сообщения
        $messageId = $sendMessageResponse->MessageId;
        if (empty($messageId)) {
            throw new Exception('Ошибка получения MessageId');
        }

        // получаем ответ
        $getMessageResponse = $this->getMessageAsync(self::SERVICE_KKT, new GetMessageRequest([
            'MessageId' => $messageId
        ]), $token);

        /** @var GetTicketResponse $getTicketResponse */
        $getTicketResponse = $getMessageResponse->Message->any;

        if (! empty($getTicketResponse->Fault)) {
            throw new Exception('Ошибка запроса данных чека: ' . $getTicketResponse->Fault->Message);
        }

        return $getTicketResponse->Result;
    }
}
